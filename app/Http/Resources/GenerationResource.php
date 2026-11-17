<?php

namespace App\Http\Resources;

use App\Models\Generation;
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
            ],
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
