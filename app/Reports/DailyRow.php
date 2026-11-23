<?php

namespace App\Reports;

use JsonSerializable;

/**
 * One UTC day of the admin report: the totals for the Generations that
 * succeeded that day, beside what Pixelforge billed that day when known.
 */
final readonly class DailyRow implements JsonSerializable
{
    public function __construct(
        /** The UTC day, as `Y-m-d`. */
        public string $date,
        public ComparedTotals $compared,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function jsonSerialize(): array
    {
        return ['date' => $this->date, ...$this->compared->jsonSerialize()];
    }
}
