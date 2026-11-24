<?php

namespace App\Actions;

use App\Enums\LedgerEntryKind;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Money;

/**
 * Add Credits to a user's Balance: a Top-up row in the ledger.
 */
class TopUp
{
    public function handle(User $user, Money $amount): LedgerEntry
    {
        return LedgerEntry::record($user->id, LedgerEntryKind::TopUp, $amount);
    }
}
