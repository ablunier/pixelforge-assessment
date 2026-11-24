<?php

use App\Actions\TopUp;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\Reported;

it('gives a newly registered user a $1.00 Balance', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', 1_000_000_000));
});

it('adds exactly the pack\'s amount to the Balance', function (string $pack, int $nanodollars) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['pack' => $pack])
        ->assertRedirect(route('credits.show'));

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', $nanodollars));
})->with([
    '$5' => ['five', 5_000_000_000],
    '$10' => ['ten', 10_000_000_000],
    '$25' => ['twenty_five', 25_000_000_000],
]);

it('ignores an amount sent by the client and adds the pack\'s own', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['pack' => 'five', 'amount' => 1_000_000_000_000])
        ->assertRedirect(route('credits.show'));

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balance', 5_000_000_000)
            ->where('ledgerEntries.0.amount', 5_000_000_000));
});

it('rejects an unknown pack and adds nothing', function (mixed $pack) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('credits.show'))
        ->post(route('credits.store'), ['pack' => $pack, 'amount' => 5_000_000_000])
        ->assertRedirect(route('credits.show'))
        ->assertSessionHasErrors('pack');

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balance', 0)
            ->has('ledgerEntries', 0));
})->with([
    'unknown id' => ['hundred'],
    'an amount' => ['5'],
    'missing' => [null],
]);

it('lists the user\'s ledger entries newest first', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('credits.store'), ['pack' => 'ten']);
    $this->travel(1)->minute();
    $this->post(route('credits.store'), ['pack' => 'twenty_five']);
    $this->travel(1)->minute();
    $this->post(route('credits.store'), ['pack' => 'five']);

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Credits')
            ->where('balance', 40_000_000_000)
            ->has('ledgerEntries', 3)
            ->where('ledgerEntries.0.kind', 'top_up')
            ->where('ledgerEntries.0.amount', 5_000_000_000)
            ->where('ledgerEntries.1.amount', 25_000_000_000)
            ->where('ledgerEntries.2.amount', 10_000_000_000));
});

it('pages the ledger history 50 entries at a time, newest first', function () {
    $user = User::factory()->create();
    // Top-ups of 1 to 51 nanodollars, the 51st the newest.
    foreach (range(1, 51) as $nanodollars) {
        app(TopUp::class)->handle($user, Money::ofNanodollars($nanodollars));
    }
    $this->actingAs($user);

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('ledgerEntries', 50)
            ->where('ledgerEntries.0.amount', 51)
            ->where('ledgerEntries.49.amount', 2)
            ->where('ledgerPage', ['current' => 1, 'last' => 2]));

    $this->get(route('credits.show', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('ledgerEntries', 1)
            ->where('ledgerEntries.0.amount', 1)
            ->where('ledgerPage', ['current' => 2, 'last' => 2]));
});

it('shows users only their own ledger and Balance', function () {
    $other = User::factory()->create();
    $this->actingAs($other)->post(route('credits.store'), ['pack' => 'twenty_five']);

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('credits.store'), ['pack' => 'five']);

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balance', 5_000_000_000)
            ->has('ledgerEntries', 1)
            ->where('ledgerEntries.0.amount', 5_000_000_000));
});

it('names the Generation of each Hold, Hold release and Charge in the ledger history', function () {
    $this->travelTo('2026-11-04T14:03:00Z');
    $user = User::factory()->withTopUp(Money::ofNanodollars(1_000_000_000))->create();
    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ])->assertSessionHasNoErrors();
    $generation = Generation::query()->sole();
    pixelforge()->answerRetrieves($generation, Reported::succeeded());
    $this->travel(61)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    $named = ['id' => $generation->id, 'prompt' => 'A lighthouse on a cliff at dawn, oil painting'];

    $this->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('ledgerEntries', 4)
            ->where('ledgerEntries.0.kind', 'hold_release')
            ->where('ledgerEntries.0.generation', $named)
            ->where('ledgerEntries.1.kind', 'charge')
            ->where('ledgerEntries.1.generation', $named)
            ->where('ledgerEntries.2.kind', 'hold')
            ->where('ledgerEntries.2.generation', $named)
            ->where('ledgerEntries.3.kind', 'top_up')
            ->where('ledgerEntries.3.generation', null));
});

it('offers the $5, $10 and $25 packs', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('credits.show'))
        ->assertInertia(fn (Assert $page) => $page->where('packs', [
            ['id' => 'five', 'amount' => 5_000_000_000],
            ['id' => 'ten', 'amount' => 10_000_000_000],
            ['id' => 'twenty_five', 'amount' => 25_000_000_000],
        ]));
});

it('sends guests to log in instead of the Buy credits page', function () {
    $this->get(route('credits.show'))->assertRedirect(route('login'));
    $this->post(route('credits.store'), ['pack' => 'five'])->assertRedirect(route('login'));
});
