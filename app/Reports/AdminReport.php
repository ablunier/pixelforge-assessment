<?php

namespace App\Reports;

use App\Pixelforge\BilledCosts;

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
        public ?BilledCosts $billed,
    ) {}

    public function totals(): Figures
    {
        return array_reduce(
            $this->days,
            fn (Figures $total, DailyRow $day): Figures => $total->plus($day->figures),
            Figures::none(),
        );
    }

    public function totalCostComparison(): CostComparison
    {
        return new CostComparison($this->totals()->providerCost, $this->billed?->total);
    }
}
