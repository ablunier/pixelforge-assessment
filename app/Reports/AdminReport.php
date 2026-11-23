<?php

namespace App\Reports;

/**
 * The admin report for a date range: one row per UTC day, one per user with
 * activity, and the totals.
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
    ) {}

    public function totals(): Figures
    {
        return array_reduce(
            $this->days,
            fn (Figures $total, DailyRow $day): Figures => $total->plus($day->figures),
            Figures::none(),
        );
    }
}
