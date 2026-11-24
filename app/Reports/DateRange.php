<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A run of whole UTC days, `from` to `to` inclusive.
 */
final readonly class DateRange
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->from = $from->utc()->startOfDay();
        $this->to = $to->utc()->startOfDay();

        if ($this->to->isBefore($this->from)) {
            throw new InvalidArgumentException("A date range can't end ({$this->to->toDateString()}) before it starts ({$this->from->toDateString()}).");
        }
    }

    /**
     * The `$days` UTC days up to and including `$to`.
     */
    public static function endingOn(CarbonImmutable $to, int $days): self
    {
        return new self($to->utc()->subDays($days - 1), $to);
    }

    public function dayCount(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Each day of the range, in order, as the start of that UTC day.
     *
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from; ! $day->isAfter($this->to); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /** The first instant after the range. */
    public function endExclusive(): CarbonImmutable
    {
        return $this->to->addDay();
    }
}
