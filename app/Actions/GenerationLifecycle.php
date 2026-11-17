<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationSnapshot;
use App\Pricing\Pricing;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one place a Generation's status and money change. Every provider
 * snapshot (create response, poll or Webhook Event) goes through apply().
 *
 * Each change runs in a transaction holding the Generation's row lock, and
 * status only moves forward (GenerationStatus::canMoveTo()), so duplicate,
 * stale or out-of-order snapshots are ignored and the single transition into
 * `succeeded` records the Price exactly once.
 */
final class GenerationLifecycle
{
    /**
     * Apply Pixelforge's view of a Generation. `$generation` only identifies
     * the row, which is re-read under lock; the saved Generation is returned.
     */
    public function apply(Generation $generation, GenerationSnapshot $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $generation = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

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

            $generation->save();

            return $generation;
        });
    }

    /**
     * End a Generation that Pixelforge never accepted (a rejected submission or
     * exhausted retries) as `failed`, with no Provider Cost or Price. Ignored
     * if the Generation has already moved on, e.g. through an early webhook.
     */
    public function failSubmission(Generation $generation, string $errorCode, ?string $errorMessage): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage): Generation {
            $generation = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($generation->status->canMoveTo(GenerationStatus::Failed) && $generation->pixelforge_id === null) {
                $generation->status = GenerationStatus::Failed;
                $generation->error_code = $errorCode;
                $generation->error_message = $errorMessage;
                $generation->save();
            }

            return $generation;
        });
    }

    private function recordSuccess(Generation $generation, GenerationSnapshot $snapshot): void
    {
        $lockedRates = $generation->lockedRates();

        if ($snapshot->usage === null || $lockedRates === null) {
            throw new LogicException("Pixelforge reported Generation {$generation->id} succeeded without usage or rates.");
        }

        $generation->input_text_tokens = $snapshot->usage->inputTextTokens;
        $generation->input_image_tokens = $snapshot->usage->inputImageTokens;
        $generation->output_tokens = $snapshot->usage->outputTokens;
        $generation->reasoning_tokens = $snapshot->usage->reasoningTokens;
        $generation->provider_cost = Pricing::providerCost($snapshot->usage, $lockedRates);
        $generation->price = Pricing::price($generation->provider_cost);
        $generation->provider_image_url = $snapshot->imageUrl;
        $generation->provider_image_url_expires_at = $snapshot->imageUrlExpiresAt;
    }

    private function recordFailure(Generation $generation, GenerationSnapshot $snapshot): void
    {
        $generation->error_code = $snapshot->errorCode ?? 'generation_failed';
        $generation->error_message = $snapshot->errorMessage;
    }
}
