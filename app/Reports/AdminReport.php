<?php

namespace App\Reports;

use App\Pixelforge\PixelforgeBilled;

/**
 * The admin report for a date range: one row per UTC day, one per user with
 * activity, and the totals, with what Pixelforge billed when it could be had.
 */
final readonly class AdminReport
{
    /**
     * @param  list<DailyRow>  $days
     * @param  list<UserRow>  $users
     */
    public function __construct(
        public DateRange $range,
        public array $days,
        public array $users,
        /** What Pixelforge billed over the range, or null when it couldn't be had. */
        public ?PixelforgeBilled $billed,
    ) {}

    /**
     * The totals over every day, beside the total Pixelforge billed.
     */
    public function totals(): ComparedTotals
    {
        $totals = array_reduce(
            $this->days,
            fn (GenerationTotals $total, DailyRow $day): GenerationTotals => $total->plus($day->compared->totals),
            GenerationTotals::none(),
        );

        return new ComparedTotals($totals, $this->billed?->total);
    }
}
