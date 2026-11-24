/** The admin report's figures, from App\Reports\GenerationTotals. Amounts are integer nanodollars. */
export type GenerationTotals = {
    generations: number;
    charges: number;
    providerCost: number;
    /** Charges minus Provider Cost. */
    profit: number;
};

/**
 * The Provider Cost set beside what Pixelforge billed. Both are null when
 * Pixelforge's usage couldn't be had.
 */
export type ReportCostComparison = {
    pixelforgeBilled: number | null;
    /** What Pixelforge billed minus the Provider Cost; 0 when they match. */
    costDifference: number | null;
};

/** Totals beside what Pixelforge billed, from App\Reports\ComparedTotals. */
export type ReportTotals = GenerationTotals & ReportCostComparison;

/** One UTC day of the admin report, from App\Reports\DailyRow. */
export type ReportDay = ReportTotals & {
    /** The UTC day, as YYYY-MM-DD. */
    date: string;
};

/** One user's figures over the range, from App\Reports\UserRow. */
export type ReportUser = GenerationTotals & {
    userId: number;
    name: string;
    email: string;
};

/** The UTC days the report covers, inclusive. */
export type ReportRange = {
    from: string;
    to: string;
    maxDays: number;
};
