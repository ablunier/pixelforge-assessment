<?php

namespace App\Reports;

use App\Money\Money;
use JsonSerializable;

/**
 * One UTC day of the admin report: the figures for the Generations that
 * succeeded that day, and what Pixelforge billed that day when known.
 */
final readonly class DailyRow implements JsonSerializable
{
    public function __construct(
        /** The UTC day, as `Y-m-d`. */
        public string $date,
        public GenerationTotals $totals,
        public ?Money $pixelforgeBilled = null,
    ) {}

    public function costComparison(): CostComparison
    {
        return new CostComparison($this->totals->providerCost, $this->pixelforgeBilled);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function jsonSerialize(): array
    {
        return ['date' => $this->date, ...$this->totals->jsonSerialize(), ...$this->costComparison()->jsonSerialize()];
    }
}
