<?php

namespace App\Jobs;

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationErrorCode;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends a `pending` Generation to Pixelforge and applies the `202` response
 * (ADR-0003). The local id is the Idempotency-Key, so running this again for
 * the same Generation never creates a second one at Pixelforge.
 *
 * Rate limits, overloads, server errors, lost responses and success responses
 * this app can't read are retried after `Retry-After` (or an exponential
 * backoff), capped at a minute, for up to MAX_ATTEMPTS;
 * concurrency-limit waits are free for a while (see waitingItsTurn()).
 * Permanent rejections and exhausted retries end the Generation as `failed`,
 * with no Price. A Generation Pixelforge may already hold (a lost response or
 * an undocumented 5xx) or can't take yet (our API key refused) stays
 * `pending` for reconciliation to resend instead, up to
 * MAX_ATTEMPTS_AWAITING_PIXELFORGE (ADR-0005).
 *
 * The attempt budget lives on the Generation, so the queue never retries this
 * job itself ($tries = 1), and at most one is queued per Generation: a retry
 * waiting its turn keeps reconciliation from queueing a second one.
 */
class SubmitGenerationToPixelforge implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Longer than any retry delay and queue wait, should a queued job ever be lost. */
    public int $uniqueFor = 600;

    /** Create requests a Generation gets before it fails with `retries_exhausted`. */
    public const int MAX_ATTEMPTS = 5;

    /**
     * Create requests a Generation gets while Pixelforge may already hold it or
     * can't take it yet (lost responses, refused API key), resent by
     * reconciliation every couple of minutes, before it fails for good.
     */
    public const int MAX_ATTEMPTS_AWAITING_PIXELFORGE = 20;

    /**
     * The longest wait before a retry, `Retry-After` included: below
     * ReconcileGenerations::PENDING_STALE_AFTER_SECONDS.
     */
    private const int MAX_RETRY_DELAY_SECONDS = 60;

    /** How long after pressing Generate concurrency-limit waits don't count as attempts. */
    private const int MAX_CONCURRENCY_WAIT_MINUTES = 15;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (e.g. an early webhook) or failed: nothing to submit.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $snapshot = $pixelforge->createGeneration(
                $generation->id,
                $generation->prompt,
                $generation->size,
                $generation->quality,
                $generation->reference_images,
            );
        } catch (PixelforgeError $error) {
            $this->rejected($generation, $error, $lifecycle);

            return;
        } catch (ConnectionException|UnexpectedPixelforgeResponse $exception) {
            // The response was lost, or Pixelforge accepted the request (and may bill it)
            // in a body this app can't read: it may or may not have the Generation, and
            // resending with the same Idempotency-Key is safe either way.
            if ($exception instanceof UnexpectedPixelforgeResponse) {
                report($exception);
            }

            $this->retryOrGiveUp($generation, null, $exception->getMessage(), outcomeUnknown: true, lifecycle: $lifecycle);

            return;
        }

        try {
            // Possibly an idempotent replay of an earlier attempt, already final: applied like any snapshot.
            $lifecycle->apply($generation, $snapshot);
        } catch (Throwable $exception) {
            // Pixelforge has the Generation but it couldn't be recorded: resend, within the budget.
            report($exception);
            $this->retryOrGiveUp($generation, null, $exception->getMessage(), outcomeUnknown: true, lifecycle: $lifecycle);

            return;
        }

        $generation->increment('submission_attempts');
    }

    private function rejected(Generation $generation, PixelforgeError $error, GenerationLifecycle $lifecycle): void
    {
        if ($error->isAuthFailure()) {
            $this->apiKeyRefused($generation, $error, $lifecycle);
        } elseif ($this->waitingItsTurn($generation, $error)) {
            $generation->touch(); // Keeps reconciliation from resubmitting it meanwhile.
            $this->retryLater($generation, $error->retryAfter ?? self::backoffSeconds(1));
        } elseif ($error->isPermanent()) {
            $generation->increment('submission_attempts');
            $lifecycle->failSubmission($generation, $error->errorCode, $error->getMessage());
        } else {
            $this->retryOrGiveUp($generation, $error->retryAfter, $error->getMessage(), $error->leavesOutcomeUnknown(), $lifecycle);
        }
    }

    /**
     * Our own misconfiguration, not the Generation's fault: report it loudly and
     * stay `pending`, so reconciliation resubmits it once the key is fixed. A
     * key that stays broken fails the Generation after a while, so nothing
     * waits forever.
     */
    private function apiKeyRefused(Generation $generation, PixelforgeError $error, GenerationLifecycle $lifecycle): void
    {
        report($error);
        $generation->increment('submission_attempts');

        if ($generation->submission_attempts >= self::MAX_ATTEMPTS_AWAITING_PIXELFORGE) {
            $lifecycle->failSubmission($generation, $error->errorCode, $error->getMessage());
        }
    }

    /**
     * Count a failed attempt, then retry after `$retryAfter` seconds (or an
     * exponential backoff) until MAX_ATTEMPTS. Past that, fail the Generation,
     * unless some attempt got no definite answer (`$outcomeUnknown`, now or
     * earlier): Pixelforge may hold it, so it stays `pending` for
     * reconciliation's idempotent resends, up to MAX_ATTEMPTS_AWAITING_PIXELFORGE.
     */
    private function retryOrGiveUp(Generation $generation, ?int $retryAfter, string $reason, bool $outcomeUnknown, GenerationLifecycle $lifecycle): void
    {
        // Atomic, and only ever setting the flag, so a concurrent attempt's count is never overwritten.
        $generation->increment('submission_attempts', extra: $outcomeUnknown ? ['submission_outcome_unknown' => true] : []);
        $generation->refresh();

        if ($generation->submission_attempts < self::MAX_ATTEMPTS) {
            $this->retryLater($generation, $retryAfter ?? self::backoffSeconds($generation->submission_attempts));
        } elseif (! $generation->submission_outcome_unknown) {
            $lifecycle->failSubmission($generation, GenerationErrorCode::RetriesExhausted, $reason);
        } elseif ($generation->submission_attempts >= self::MAX_ATTEMPTS_AWAITING_PIXELFORGE) {
            $lifecycle->failSubmission($generation, GenerationErrorCode::ProviderUnreachable, $reason);
        }
    }

    /**
     * Pixelforge runs at most 3 Generations per account at once and refuses the
     * rest until one finishes, which can take 90 s or more. Those refusals mean
     * "not yet", so for a while they don't count against the attempt budget
     * (story 13); past the wait limit they do, so nothing waits forever.
     */
    private function waitingItsTurn(Generation $generation, PixelforgeError $error): bool
    {
        return $error->isConcurrencyLimit()
            && $generation->created_at?->addMinutes(self::MAX_CONCURRENCY_WAIT_MINUTES)->isFuture();
    }

    /**
     * A fresh job per attempt: the attempt budget lives on the Generation, not
     * on the queue. Waits longer than MAX_RETRY_DELAY_SECONDS are cut short:
     * a retry that comes too early is only refused again.
     */
    private function retryLater(Generation $generation, int $delaySeconds): void
    {
        self::dispatch($generation)->delay(min($delaySeconds, self::MAX_RETRY_DELAY_SECONDS));
    }

    /**
     * Seconds to wait after the given attempt when Pixelforge sends no
     * Retry-After: 2, 4, 8, 16…
     */
    private static function backoffSeconds(int $attempt): int
    {
        return min(2 ** $attempt, self::MAX_RETRY_DELAY_SECONDS);
    }
}
