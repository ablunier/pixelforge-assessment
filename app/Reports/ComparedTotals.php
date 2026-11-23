<?php

namespace App\Reports;

use App\Money\Money;
use JsonSerializable;

/**
 * Some succeeded Generations' totals with our recorded Provider Cost set
 * beside what Pixelforge billed for them, or null when Pixelforge's figure
 * isn't known. The shape of a day row and of the report's totals.
 */
final readonly class ComparedTotals implements JsonSerializable
{
    public function __construct(
        public GenerationTotals $totals,
        public ?Money $pixelforgeBilled,
    ) {}

    /**
     * What Pixelforge billed minus our Provider Cost: positive when it billed
     * more than we recorded, zero when they match to the nanodollar.
     */
    public function costDifference(): ?Money
    {
        return $this->pixelforgeBilled?->minus($this->totals->providerCost);
    }

    /**
     * Amounts in integer nanodollars, null when Pixelforge's figure isn't known.
     *
     * @return array{generations: int, charges: int, providerCost: int, profit: int, pixelforgeBilled: int|null, costDifference: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->totals->jsonSerialize(),
            'pixelforgeBilled' => $this->pixelforgeBilled?->nanodollars(),
            'costDifference' => $this->costDifference()?->nanodollars(),
        ];
    }
}
