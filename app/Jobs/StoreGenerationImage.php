<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a succeeded Generation's PNG to the `generation-images` disk, so
 * it stays available after Pixelforge's signed URL expires (24 hours after
 * completion). Dispatched once, on the transition into `succeeded`.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public const string DISK = 'generation-images';

    public int $tries = 5;

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

    public function handle(PixelforgeClient $pixelforge, Http $http): void
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

        $png = $http->timeout(30)->get((string) $generation->provider_image_url)->throw()->body();

        $path = "{$generation->id}.png";
        Storage::disk(self::DISK)->put($path, $png);

        $generation->image_path = $path;
        $generation->save();
    }
}
