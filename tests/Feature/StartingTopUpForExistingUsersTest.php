<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\LedgerEntryKind;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Money;

/**
 * Run the migration that gives users from before Balances their starting Top-up.
 */
function giveExistingUsersTheirStartingTopUp(): void
{
    $migration = require database_path('migrations/2026_11_24_120000_give_existing_users_their_starting_top_up.php');
    $migration->up();
}

it('gives a user with no ledger the starting Top-up new users get', function () {
    $user = User::factory()->create();

    giveExistingUsersTheirStartingTopUp();

    expect($user->balance()->nanodollars())->toBe(CreateNewUser::STARTING_CREDITS_NANODOLLARS)
        ->and($user->ledgerEntries()->sole()->kind)->toBe(LedgerEntryKind::TopUp);
});

it('leaves users who already have a ledger alone', function () {
    $user = User::factory()->withTopUp(Money::fromDecimal('5'))->create();

    giveExistingUsersTheirStartingTopUp();

    expect($user->balance()->nanodollars())->toBe(Money::fromDecimal('5')->nanodollars())
        ->and($user->ledgerEntries()->count())->toBe(1);
});

it('gives deleted accounts nothing', function () {
    User::factory()->create()->delete();

    giveExistingUsersTheirStartingTopUp();

    expect(LedgerEntry::query()->count())->toBe(0);
});

it('adds nothing when run again', function () {
    $user = User::factory()->create();

    giveExistingUsersTheirStartingTopUp();
    giveExistingUsersTheirStartingTopUp();

    expect($user->ledgerEntries()->count())->toBe(1);
});
