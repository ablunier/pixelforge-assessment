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

    public function totals(): GenerationTotals
    {
        return array_reduce(
            $this->days,
            fn (GenerationTotals $total, DailyRow $day): GenerationTotals => $total->plus($day->totals),
            GenerationTotals::none(),
        );
    }

    public function totalCostComparison(): CostComparison
    {
        return new CostComparison($this->totals()->providerCost, $this->billed?->total);
    }
}
