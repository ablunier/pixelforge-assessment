<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the user's ledger history. The amount is integer nanodollars.
 * Holds, Hold releases and Charges name their Generation; load `generation`
 * first to avoid a query per row.
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
            'generation' => $this->generation === null ? null : [
                'id' => $this->generation->id,
                'prompt' => $this->generation->prompt,
            ],
            'createdAt' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
