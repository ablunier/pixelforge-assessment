<?php

namespace App\Actions;

use App\Credits\InsufficientAvailableBalance;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryKind;
use App\Generations\GenerationParameters;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Money;
use App\Pixelforge\Answer;
use App\Pixelforge\Decision;
use App\Pixelforge\Fail;
use App\Pixelforge\GenerationErrorCode;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\SubmissionRules;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one place a Generation is opened and its status and money change.
 * Every `pending` Generation starts in open(), and every provider snapshot
 * (create response, poll or Webhook Event) goes through apply().
 *
 * Each change to an opened Generation runs in a transaction holding its row lock, and
 * status only moves forward (GenerationStatus::canMoveTo()), so duplicate,
 * stale or out-of-order snapshots are ignored and the single transition into
 * `succeeded` records the Price exactly once.
 */
final class GenerationLifecycle
{
    /**
     * Open a Generation: save it as `pending` with the Maximum Price its
     * parameters have at `$rates`, and Hold that Maximum Price from the
     * user's Available Balance (ADR-0002). The user's row stays locked until
     * both are saved, so concurrent submissions can't overspend. Submitting
     * it to Pixelforge is the caller's job.
     *
     * @throws InsufficientAvailableBalance when the Available Balance can't cover the Maximum Price; nothing is saved
     */
    public function open(User $user, GenerationParameters $parameters, Rates $rates): Generation
    {
        $maximumPrice = $parameters->maximumPrice($rates);

        return DB::transaction(function () use ($user, $parameters, $maximumPrice): Generation {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $availableBalance = $user->availableBalance();
            if ($availableBalance->isLessThan($maximumPrice)) {
                throw new InsufficientAvailableBalance($availableBalance, $maximumPrice);
            }

            $generation = $user->generations()->create([
                'prompt' => $parameters->prompt,
                'size' => $parameters->size,
                'quality' => $parameters->quality,
                'reference_images' => $parameters->referenceImages,
                'status' => GenerationStatus::Pending,
                'max_price' => $maximumPrice,
            ]);

            $this->writeLedgerEntry($generation, LedgerEntryKind::Hold, $maximumPrice);

            return $generation;
        });
    }

    /**
     * Apply Pixelforge's view of a Generation. `$generation` only identifies
     * the row, which is re-read under lock; the saved Generation is returned.
     */
    public function apply(Generation $generation, GenerationSnapshot $snapshot): Generation
    {
        return $this->underLock($generation, fn (Generation $generation) => $this->applyUnderLock($generation, $snapshot));
    }

    /**
     * Reconciliation's poll of a quiet in-flight Generation: apply what the
     * poll found (`null` when it failed), then give up if the Generation is
     * still not final past its give-up deadline (ADR-0007). One lock covers
     * both, so a Generation the last poll finds finished is recorded, never
     * given up on.
     */
    public function reconcile(Generation $generation, ?GenerationSnapshot $snapshot): Generation
    {
        return $this->underLock($generation, function (Generation $generation) use ($snapshot): void {
            if ($snapshot !== null) {
                $this->applyUnderLock($generation, $snapshot);
            }

            if ($generation->isPastGiveUpDeadline()) {
                $this->giveUpUnderLock($generation);
            }
        });
    }

    /**
     * Record one create request and Pixelforge's answer to it (ADR-0005): count
     * the attempt as SubmissionRules decides, and fail the Generation if they
     * say so, all under the row lock, so concurrent attempts never miscount.
     * Even an attempt that doesn't count renews `updated_at`, which keeps
     * reconciliation from resubmitting a Generation still waiting its turn.
     */
    public function recordSubmissionAttempt(Generation $generation, Answer $answer): Decision
    {
        $decision = null;

        $this->underLock($generation, function (Generation $generation) use ($answer, &$decision): void {
            $decision = SubmissionRules::decide($answer, $generation->submission_attempts, $generation->submission_outcome_unknown, $generation->created_at, now());

            $generation->submission_attempts = $decision->attempts;
            $generation->submission_outcome_unknown = $decision->outcomeUnknown;
            $generation->setUpdatedAt($generation->freshTimestamp());

            if ($decision->next instanceof Fail) {
                $this->failUnaccepted($generation, $decision->next->errorCode, $decision->next->errorMessage);
            }
        });

        /** @var Decision $decision */
        return $decision;
    }

    /**
     * End a Generation Pixelforge accepted but never finished (ADR-0007) as
     * `failed`, with no Provider Cost or Price. Ignored if it reached a final
     * status meanwhile.
     */
    public function giveUp(Generation $generation): Generation
    {
        return $this->underLock($generation, fn (Generation $generation) => $this->giveUpUnderLock($generation));
    }

    /**
     * Re-read the Generation under its row lock, let `$change` modify it, and
     * save it, all in one transaction. Returns the saved Generation.
     *
     * @param  Closure(Generation): void  $change
     */
    private function underLock(Generation $generation, Closure $change): Generation
    {
        return DB::transaction(function () use ($generation, $change): Generation {
            $generation = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            $change($generation);
            $generation->save(); // A no-op when nothing changed.

            return $generation;
        });
    }

    private function applyUnderLock(Generation $generation, GenerationSnapshot $snapshot): void
    {
        // A final Generation never changes, not even to learn its `gen_` id or rates (story 31).
        if ($generation->status->isFinal()) {
            return;
        }

        // From whichever source reports them first, e.g. the create response or an early webhook.
        $generation->pixelforge_id ??= $snapshot->pixelforgeId;

        if ($generation->lockedRates() === null) {
            $generation->lockRates($snapshot->rates);
        }

        if ($generation->status->canMoveTo($snapshot->status)) {
            $generation->status = $snapshot->status;
            $generation->provider_created_at ??= $snapshot->createdAt;
            $generation->started_at ??= $snapshot->startedAt;
            $generation->completed_at ??= $snapshot->completedAt;

            match ($snapshot->status) {
                GenerationStatus::Succeeded => $this->recordSuccess($generation, $snapshot),
                GenerationStatus::Failed => $this->recordFailure($generation, $snapshot),
                default => null,
            };
        }
    }

    private function giveUpUnderLock(Generation $generation): void
    {
        if ($generation->status->canMoveTo(GenerationStatus::Failed)) {
            $generation->status = GenerationStatus::Failed;
            $generation->error_code = GenerationErrorCode::NeverFinished->value;
            $generation->error_message = null;
            $this->settleHold($generation, null);
        }
    }

    /**
     * End a Generation that Pixelforge never accepted (a rejected submission or
     * exhausted retries) as `failed`, with no Provider Cost or Price. Ignored
     * if the Generation has already moved on, e.g. through an early webhook,
     * or once Pixelforge has its `gen_` id. `$errorCode` is Pixelforge's code
     * as sent (possibly one this app doesn't know yet) or a GenerationErrorCode.
     */
    private function failUnaccepted(Generation $generation, GenerationErrorCode|string $errorCode, ?string $errorMessage): void
    {
        if ($generation->status->canMoveTo(GenerationStatus::Failed) && $generation->pixelforge_id === null) {
            $generation->status = GenerationStatus::Failed;
            $generation->error_code = $errorCode instanceof GenerationErrorCode ? $errorCode->value : $errorCode;
            $generation->error_message = $errorMessage;
            $this->settleHold($generation, null);
        }
    }

    private function recordSuccess(Generation $generation, GenerationSnapshot $snapshot): void
    {
        $lockedRates = $generation->lockedRates();

        // GenerationSnapshot guarantees usage on a success, and apply() locked the rates.
        if ($snapshot->usage === null || $lockedRates === null) {
            throw new LogicException("Generation {$generation->id} succeeded without usage or locked rates.");
        }

        $generation->input_text_tokens = $snapshot->usage->inputTextTokens;
        $generation->input_image_tokens = $snapshot->usage->inputImageTokens;
        $generation->output_tokens = $snapshot->usage->outputTokens;
        $generation->reasoning_tokens = $snapshot->usage->reasoningTokens;
        $generation->provider_cost = Pricing::providerCost($snapshot->usage, $lockedRates);
        $generation->price = Pricing::price($generation->provider_cost, $generation->max_price);
        $this->settleHold($generation, $generation->price);
        $generation->provider_image_url = $snapshot->imageUrl;
        $generation->provider_image_url_expires_at = $snapshot->imageUrlExpiresAt;

        // Pixelforge always sends an image URL with a success; without one there's nothing to download.
        if ($snapshot->imageUrl !== null) {
            // Once this transaction commits, so the job sees the succeeded Generation.
            StoreGenerationImage::dispatch($generation)->afterCommit();
        }
    }

    private function recordFailure(Generation $generation, GenerationSnapshot $snapshot): void
    {
        $generation->error_code = $snapshot->errorCode ?? GenerationErrorCode::GenerationFailed->value;
        $generation->error_message = $snapshot->errorMessage;
        $this->settleHold($generation, null);
    }

    /**
     * Settle a Generation's Hold now that it's final: Charge `$price` for a
     * success (null for a failure) and release the rest of the Hold. Runs in
     * the transaction that made the Generation final, which happens once, and
     * the ledger's unique index refuses a second Charge or Hold release
     * regardless. A Generation opened before Holds existed has none, and is
     * neither charged nor released.
     */
    private function settleHold(Generation $generation, ?Money $price): void
    {
        $hold = LedgerEntry::query()
            ->where('generation_id', $generation->id)
            ->where('kind', LedgerEntryKind::Hold)
            ->first();

        if ($hold === null) {
            return;
        }

        $none = Money::ofNanodollars(0);
        $price ??= $none;

        if ($price->isGreaterThan($none)) {
            $this->writeLedgerEntry($generation, LedgerEntryKind::Charge, $price);
        }

        // Nothing is left to release when the Price was capped at the Maximum Price (ADR-0004).
        $remainder = $hold->amount->minus($price);
        if ($remainder->isGreaterThan($none)) {
            $this->writeLedgerEntry($generation, LedgerEntryKind::HoldRelease, $remainder);
        }
    }

    /**
     * Append one ledger entry for `$generation`, in its owner's ledger.
     */
    private function writeLedgerEntry(Generation $generation, LedgerEntryKind $kind, Money $amount): void
    {
        $entry = new LedgerEntry(['generation_id' => $generation->id, 'kind' => $kind, 'amount' => $amount]);
        $entry->user_id = $generation->user_id;
        $entry->save();
    }
}
