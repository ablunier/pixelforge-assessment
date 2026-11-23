/** The admin report's figures, from App\Reports\Figures. Amounts are integer nanodollars. */
export type ReportFigures = {
    generations: number;
    charges: number;
    providerCost: number;
    /** Charges minus Provider Cost. */
    profit: number;
};

/**
 * The Provider Cost set beside what Pixelforge billed, from App\Reports\CostComparison.
 * Both are null when Pixelforge's usage couldn't be had (or doesn't cover the day).
 */
export type ReportCostComparison = {
    pixelforgeCost: number | null;
    /** What Pixelforge billed minus the Provider Cost; 0 when they match. */
    costDifference: number | null;
};

/** One UTC day of the admin report, from App\Reports\DailyRow. */
export type ReportDay = ReportFigures &
    ReportCostComparison & {
        /** The UTC day, as YYYY-MM-DD. */
        date: string;
    };

/** The report's totals: the figures and the comparison with Pixelforge's total. */
export type ReportTotals = ReportFigures & ReportCostComparison;

/** One user's figures over the range, from App\Reports\UserRow. */
export type ReportUser = ReportFigures & {
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
