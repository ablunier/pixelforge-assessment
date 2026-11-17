<?php

namespace App\Console\Commands;

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Catches Generations whose status updates were lost: polls Pixelforge for
 * each one still in flight that hasn't changed for a while, and applies the
 * result like any other snapshot. Scheduled every minute.
 */
#[Signature('generations:reconcile')]
#[Description('Poll Pixelforge for in-flight Generations that have gone quiet')]
class ReconcileGenerations extends Command
{
    /** How long a Generation must go unchanged before it's polled. */
    public const int STALE_AFTER_SECONDS = 60;

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): int
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

        return self::SUCCESS;
    }
}
