<?php

use App\Enums\LedgerEntryKind;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use Illuminate\Database\QueryException;
use Laravel\Passkeys\Passkey;

function deleteAccountOf(User $user): void
{
    test()->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));
}

it('signs the user out and keeps their ledger and Generations when they delete their account', function () {
    $user = User::factory()->withTopUp()->create();
    $generation = Generation::factory()->for($user)->create();

    deleteAccountOf($user);

    $this->assertGuest();
    $this->assertSoftDeleted($user);
    expect(User::withTrashed()->find($user->id)->ledgerEntries()->count())->toBe(1)
        ->and(Generation::query()->whereKey($generation->id)->exists())->toBeTrue();
});

it('doesn\'t let a deleted account log in again', function () {
    $user = User::factory()->create();
    deleteAccountOf($user);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('keeps a deleted account\'s email taken, so registering with it fails validation', function () {
    $user = User::factory()->create();
    deleteAccountOf($user);

    $this->post(route('register.store'), [
        'name' => 'Someone Else',
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::withTrashed()->where('email', $user->email)->count())->toBe(1);
});

it('refuses to hard-delete a user who has a ledger', function () {
    $user = User::factory()->withTopUp()->create();

    expect(fn () => $user->forceDelete())->toThrow(QueryException::class);
});

it('refuses to hard-delete a Generation that has a ledger', function () {
    $user = User::factory()->withTopUp()->create();
    $generation = Generation::factory()->for($user)->create();
    $user->ledgerEntries()->create(['generation_id' => $generation->id, 'kind' => LedgerEntryKind::Hold, 'amount' => Money::ofNanodollars(1)]);

    expect(fn () => $generation->delete())->toThrow(QueryException::class);
});

it('removes a deleted account\'s passkeys, so none can sign it in', function () {
    $user = User::factory()->create();
    $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential-1', 'credential' => []]);

    deleteAccountOf($user);

    expect(Passkey::query()->count())->toBe(0);
});
