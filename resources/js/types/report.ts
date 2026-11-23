/** The admin report's figures, from App\Reports\Figures. Amounts are integer nanodollars. */
export type ReportFigures = {
    generations: number;
    charges: number;
    providerCost: number;
    /** Charges minus Provider Cost. */
    profit: number;
};

/** One UTC day of the admin report, from App\Reports\DailyRow. */
export type ReportDay = ReportFigures & {
    /** The UTC day, as YYYY-MM-DD. */
    date: string;
};

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
