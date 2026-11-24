export type LedgerEntryKind = 'top_up' | 'hold' | 'hold_release' | 'charge';

export type CreditPackId = 'five' | 'ten' | 'twenty_five';

/** A pack on the Buy credits page; the server sets its amount. */
export type CreditPack = {
    id: CreditPackId;
    /** Integer nanodollars. */
    amount: number;
};

/** A row of the ledger history, from App\Http\Resources\LedgerEntryResource. */
export type LedgerEntry = {
    id: number;
    kind: LedgerEntryKind;
    /** Integer nanodollars, always positive: the kind says which way it moves. */
    amount: number;
    /** The Generation a Hold, Hold release or Charge belongs to; null for a Top-up. */
    generation: { id: string; prompt: string } | null;
    createdAt: string;
};

/** Which page of the ledger history is shown, 1-based, of how many. */
export type LedgerPage = {
    current: number;
    last: number;
};
