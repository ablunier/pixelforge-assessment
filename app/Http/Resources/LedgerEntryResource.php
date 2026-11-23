<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the user's ledger history. The amount is integer nanodollars.
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'amount' => $this->amount->nanodollars(),
            'generationId' => $this->generation_id,
            'createdAt' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
