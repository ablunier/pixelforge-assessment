<?php

namespace App\Console\Commands;

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Catches Generations that went quiet: resubmits `pending` ones whose submit
 * job died, polls Pixelforge for each one still in flight that hasn't
 * changed for a while (applying the result like any other snapshot), gives
 * up on ones Pixelforge never finishes, and retries image downloads that
 * gave up while the image URL still works. Scheduled every minute.
 */
#[Signature('generations:reconcile')]
#[Description('Resubmit stuck pending Generations, poll quiet in-flight ones and retry missing images')]
class ReconcileGenerations extends Command
{
    /** How long a Generation must go unchanged before it's polled. */
    public const int STALE_AFTER_SECONDS = 60;

    /**
     * How long a `pending` Generation must go unchanged before it's resubmitted:
     * longer than any retry delay (SubmitGenerationToPixelforge caps them at
     * 60 s). The submit job is also unique per Generation, so a retry still
     * waiting in a busy queue is never doubled up.
     */
    public const int PENDING_STALE_AFTER_SECONDS = 120;

    /**
     * How long after pressing Generate an accepted Generation may stay
     * `queued` or `processing` before it's given up on (ADR-0007).
     */
    public const int GIVE_UP_AFTER_MINUTES = 60;

    /**
     * How long a succeeded Generation without a stored image waits between
     * downloads: longer than StoreGenerationImage's own retries take.
     */
    public const int IMAGE_RETRY_AFTER_MINUTES = 15;

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): int
    {
        $this->resubmitStuckPending();
        $this->pollQuietInFlight($pixelforge, $lifecycle);
        $this->retryMissingImages();

        return self::SUCCESS;
    }

    /**
     * Resubmit `pending` Generations whose submit job died (ADR-0003) or that
     * the job left for a resend because Pixelforge may already hold them or
     * refused our API key (ADR-0005). The Idempotency-Key is the local id, so
     * a resend that Pixelforge already has replays it and creates nothing new.
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

    /**
     * Poll each quiet in-flight Generation, and give up on the ones still not
     * final long after they were submitted.
     */
    private function pollQuietInFlight(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): void
    {
        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->lazyById()
            ->each(function (Generation $generation) use ($pixelforge, $lifecycle): void {
                try {
                    $generation = $lifecycle->apply($generation, $pixelforge->retrieveGeneration((string) $generation->pixelforge_id));
                } catch (Throwable $exception) {
                    // Pixelforge unreachable, a 404, a response this app can't read:
                    // try again next run, and never let one bad poll stop the rest.
                    report($exception);
                }

                if ($generation->created_at?->addMinutes(self::GIVE_UP_AFTER_MINUTES)->isPast()) {
                    $lifecycle->giveUp($generation);
                }
            });
    }

    /**
     * StoreGenerationImage stops after a few tries. While Pixelforge's image
     * URL still works, start it again every so often (story 25).
     */
    private function retryMissingImages(): void
    {
        Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereNull('image_path')
            ->where('provider_image_url_expires_at', '>', now())
            ->where('updated_at', '<', now()->subMinutes(self::IMAGE_RETRY_AFTER_MINUTES))
            ->lazyById()
            ->each(function (Generation $generation): void {
                $generation->touch(); // Not again until this download has had its tries.
                StoreGenerationImage::dispatch($generation);
            });
    }
}
