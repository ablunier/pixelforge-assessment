<?php

namespace App\Console\Commands;

use App\Actions\GenerationLifecycle;
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
            ->dueForResubmit()
            ->lazyById()
            ->each(function (Generation $generation): void {
                $generation->touch(); // Not again next minute, while this one waits in the queue.
                SubmitGenerationToPixelforge::dispatch($generation);
            });
    }

    /**
     * Poll each quiet in-flight Generation and let the lifecycle reconcile it:
     * record what the poll found, and give up on it once it's still not final
     * past its give-up deadline.
     */
    private function pollQuietInFlight(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): void
    {
        Generation::query()
            ->dueForPoll()
            ->lazyById()
            ->each(function (Generation $generation) use ($pixelforge, $lifecycle): void {
                try {
                    $snapshot = $pixelforge->retrieveGeneration((string) $generation->pixelforge_id);
                } catch (Throwable $exception) {
                    // Pixelforge unreachable, a 404, a response this app can't read:
                    // try again next run, and never let one bad poll stop the rest.
                    report($exception);
                    $snapshot = null;
                }

                $lifecycle->reconcile($generation, $snapshot);
            });
    }

    /**
     * StoreGenerationImage stops after a few tries. While Pixelforge's image
     * URL still works, start it again every so often (story 25).
     */
    private function retryMissingImages(): void
    {
        Generation::query()
            ->dueForImageRetry()
            ->lazyById()
            ->each(function (Generation $generation): void {
                $generation->touch(); // Not again until this download has had its tries.
                StoreGenerationImage::dispatch($generation);
            });
    }
}
