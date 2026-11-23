<?php

namespace App\Reports;

use App\Money\Money;
use JsonSerializable;

/**
 * Our recorded Provider Cost next to what Pixelforge billed for the same
 * Generations, or null when Pixelforge's figure isn't known.
 */
final readonly class CostComparison implements JsonSerializable
{
    public function __construct(
        public Money $providerCost,
        public ?Money $pixelforgeBilled,
    ) {}

    /**
     * What Pixelforge billed minus our Provider Cost: positive when it billed
     * more than we recorded, zero when they match to the nanodollar.
     */
    public function difference(): ?Money
    {
        return $this->pixelforgeBilled?->minus($this->providerCost);
    }

    /**
     * Amounts in integer nanodollars, null when Pixelforge's figure isn't known.
     *
     * @return array{pixelforgeBilled: int|null, costDifference: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'pixelforgeBilled' => $this->pixelforgeBilled?->nanodollars(),
            'costDifference' => $this->difference()?->nanodollars(),
        ];
    }
}
