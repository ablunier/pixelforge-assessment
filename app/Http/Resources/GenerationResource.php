<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in the user's Generation history. Money is integer nanodollars.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'referenceImages' => $this->reference_images,
            'status' => $this->status->value,
            'maxPrice' => $this->max_price->nanodollars(),
            'price' => $this->price?->nanodollars(),
            'error' => $this->error_code === null ? null : [
                'code' => $this->error_code,
                'message' => $this->error_message,
                'reason' => GenerationErrorCode::reasonFor($this->error_code, $this->submission_attempts),
            ],
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
            'image' => $this->image(),
        ];
    }

    /**
     * Where a succeeded Generation's image can be seen: the stored copy once
     * downloaded, else Pixelforge's signed URL until it expires.
     *
     * @return array{source: 'stored'|'provider'|'expired', url: string|null, downloadUrl: string|null, expiresAt: string|null}|null
     */
    private function image(): ?array
    {
        if ($this->status !== GenerationStatus::Succeeded) {
            return null;
        }

        if ($this->image_path !== null) {
            return [
                'source' => 'stored',
                'url' => route('generations.image', $this->resource),
                'downloadUrl' => route('generations.image.download', $this->resource),
                'expiresAt' => null,
            ];
        }

        $expiresAt = $this->provider_image_url_expires_at;
        $live = $this->provider_image_url !== null && $expiresAt?->isFuture() === true;

        return [
            'source' => $live ? 'provider' : 'expired',
            'url' => $live ? $this->provider_image_url : null,
            'downloadUrl' => $live ? $this->provider_image_url : null,
            'expiresAt' => $expiresAt?->toIso8601ZuluString(),
        ];
    }
}
