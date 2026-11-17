<?php

namespace App\Jobs;

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a `pending` Generation to Pixelforge and applies the `202` response
 * (ADR-0003). The local id is the Idempotency-Key, so running this again for
 * the same Generation never creates a second one at Pixelforge.
 */
class SubmitGenerationToPixelforge implements ShouldQueue
{
    use Queueable;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (e.g. an early webhook) or failed: nothing to submit.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        $snapshot = $pixelforge->createGeneration(
            $generation->id,
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
        );

        $lifecycle->apply($generation, $snapshot);
    }
}
