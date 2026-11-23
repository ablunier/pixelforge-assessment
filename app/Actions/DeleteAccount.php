<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delete a user's account: soft-delete the user, so their Generations and
 * ledger stay (ADR-0002) and still count in the admin report, and remove
 * their passkeys. A soft-deleted user can't log in, and their email stays
 * taken, so no new account can register with it.
 */
class DeleteAccount
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->passkeys()->delete();
            $user->delete();
        });
    }
}
