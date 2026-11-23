<?php

namespace App\Reports;

use JsonSerializable;

/**
 * One UTC day of the admin report: the figures for the Generations that
 * succeeded that day.
 */
final readonly class DailyRow implements JsonSerializable
{
    public function __construct(
        /** The UTC day, as `Y-m-d`. */
        public string $date,
        public Figures $figures,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return ['date' => $this->date, ...$this->figures->jsonSerialize()];
    }
}
