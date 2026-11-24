<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Users registered before Balances existed have no ledger, so they would
     * start at $0 while new users start with a $1.00 Top-up. Give each active
     * user without a ledger entry that same starting Top-up.
     */
    public function up(): void
    {
        // CreateNewUser::STARTING_CREDITS_NANODOLLARS when this ran: $1.00. Kept
        // literal so the migration means the same if that constant later changes.
        $startingCredits = 1_000_000_000;

        DB::table('ledger_entries')->insertUsing(
            ['user_id', 'kind', 'amount', 'created_at'],
            DB::table('users')
                ->whereNull('deleted_at')
                ->whereNotExists(fn (Builder $query) => $query
                    ->from('ledger_entries')
                    ->whereColumn('ledger_entries.user_id', 'users.id'))
                ->select('id')
                ->selectRaw('?', ['top_up'])
                ->selectRaw('?::bigint', [$startingCredits])
                ->selectRaw('now()'),
        );
    }

    /**
     * Ledger rows are append-only (ADR-0002), so the Top-ups stay.
     */
    public function down(): void {}
};
