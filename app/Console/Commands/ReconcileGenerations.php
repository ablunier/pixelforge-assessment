<?php

namespace App\Console\Commands;

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Catches Generations that went quiet: resubmits `pending` ones whose submit
 * job died, and polls Pixelforge for each one still in flight that hasn't
 * changed for a while, applying the result like any other snapshot.
 * Scheduled every minute.
 */
#[Signature('generations:reconcile')]
#[Description('Resubmit stuck pending Generations and poll quiet in-flight ones')]
class ReconcileGenerations extends Command
{
    /** How long a Generation must go unchanged before it's polled. */
    public const int STALE_AFTER_SECONDS = 60;

    /**
     * How long a `pending` Generation must go unchanged before it's resubmitted:
     * longer than any retry delay, so a live retry is never doubled up.
     */
    public const int PENDING_STALE_AFTER_SECONDS = 120;

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): int
    {
        $this->resubmitStuckPending();
        $this->pollQuietInFlight($pixelforge, $lifecycle);

        return self::SUCCESS;
    }

    /**
     * Resubmit `pending` Generations whose submit job died (ADR-0003). The
     * Idempotency-Key is the local id, so a resend that Pixelforge already has
     * replays it and creates nothing new.
     */
    private function resubmitStuckPending(): void
    {
        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', now()->subSeconds(self::PENDING_STALE_AFTER_SECONDS))
            ->lazyById()
            ->each(function (Generation $generation): void {
                $generation->touch(); // Not again next minute, while this one waits in the queue.
                SubmitGenerationToPixelforge::dispatch($generation);
            });
    }

    private function pollQuietInFlight(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): void
    {
        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->lazyById()
            ->each(function (Generation $generation) use ($pixelforge, $lifecycle): void {
                try {
                    $lifecycle->apply($generation, $pixelforge->retrieveGeneration((string) $generation->pixelforge_id));
                } catch (PixelforgeError|ConnectionException $exception) {
                    // Try again on the next run; one failing poll mustn't stop the rest.
                    report($exception);
                }
            });
    }
}
