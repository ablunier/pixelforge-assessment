<?php

namespace App\Enums;

/**
 * What a ledger entry records (ADR-0002). Top-ups add to the Balance and
 * Charges deduct from it; Holds and Hold releases only move the Available
 * Balance.
 */
enum LedgerEntryKind: string
{
    case TopUp = 'top_up';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';
}
