<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * One UTC day of the admin report: the totals for the Generations that
 * succeeded that day, beside what Pixelforge billed that day when known.
 */
final readonly class DailyRow implements JsonSerializable
{
    public function __construct(
        /** The start of the UTC day. */
        public CarbonImmutable $day,
        public ComparedTotals $compared,
    ) {}

    /**
     * The day as `Y-m-d`, and amounts in integer nanodollars.
     *
     * @return array<string, int|string|null>
     */
    public function jsonSerialize(): array
    {
        return ['date' => $this->day->toDateString(), ...$this->compared->jsonSerialize()];
    }
}
