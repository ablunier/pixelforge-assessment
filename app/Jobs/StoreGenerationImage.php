<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use UnexpectedValueException;

/**
 * Downloads a succeeded Generation's PNG to the `generation-images` disk, so
 * it stays available after Pixelforge's signed URL expires (24 hours after
 * completion). Dispatched on the transition into `succeeded`, and again by
 * reconciliation while the URL works and no copy is stored.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public const string DISK = 'generation-images';

    public int $tries = 5;

    /** The largest image accepted: a 1536×1024 PNG is a few megabytes at most. */
    public const int MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(public Generation $generation) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(PixelforgeClient $pixelforge): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Succeeded || $generation->image_path !== null) {
            return;
        }

        if (! $generation->hasLiveProviderImageUrl() && $generation->pixelforge_id !== null) {
            // A fresh signed URL, though it expires at the same time as the old one.
            $snapshot = $pixelforge->retrieveGeneration($generation->pixelforge_id);
            $generation->provider_image_url = $snapshot->imageUrl;
            $generation->provider_image_url_expires_at = $snapshot->imageUrlExpiresAt;
            $generation->save();
        }

        if (! $generation->hasLiveProviderImageUrl()) {
            // Expired for good: the history shows the "image expired" placeholder.
            return;
        }

        $png = $pixelforge->downloadImage((string) $generation->provider_image_url);

        if (strlen($png) > self::MAX_IMAGE_BYTES || ! str_starts_with($png, self::PNG_SIGNATURE)) {
            throw new UnexpectedValueException("The image for Generation {$generation->id} isn't a PNG of at most ".self::MAX_IMAGE_BYTES.' bytes.');
        }

        $path = "{$generation->id}.png";
        Storage::disk(self::DISK)->put($path, $png);

        $generation->image_path = $path;
        $generation->save();
    }
}
