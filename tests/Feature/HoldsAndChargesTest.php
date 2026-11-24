<?php

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryKind;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use App\Pricing\Rates;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\Reported;

beforeEach(function () {
    // On the docs' timeline, so a succeeded Generation's image URL hasn't expired yet.
    $this->travelTo('2026-11-04T14:03:00Z');
});

/** The lighthouse example's Maximum Price at the published rates. */
const LIGHTHOUSE_MAX_PRICE = 457_150_000;

/** A user who bought the $1.00 they start with. */
function userWithOneDollar(): User
{
    return User::factory()->withTopUp(Money::ofNanodollars(1_000_000_000))->create();
}

/**
 * Press Generate with the docs' lighthouse example. The queue runs
 * synchronously, so Pixelforge has accepted it by the time this returns.
 */
function submitLighthouse(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ]);
}

function latestGeneration(): Generation
{
    return Generation::query()->latest('id')->firstOrFail();
}

/** Let reconciliation poll every quiet Generation. */
function pollGenerations(): void
{
    test()->travel(61)->seconds();
    test()->artisan('generations:reconcile')->assertSuccessful();
}

it('holds the Maximum Price from the Available Balance when a Generation is submitted', function () {
    $user = userWithOneDollar();

    submitLighthouse($user)->assertRedirect(route('dashboard'));

    expect($user->balance()->nanodollars())->toBe(1_000_000_000)
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000 - LIGHTHOUSE_MAX_PRICE);
});

it('rejects a submission the Available Balance can\'t cover, saving and submitting nothing', function () {
    // $0.40 left, short of the lighthouse's Maximum Price of $0.45715.
    $user = User::factory()->withTopUp(Money::ofNanodollars(400_000_000))->create();

    submitLighthouse($user)->assertSessionHasErrors([
        'generate' => 'Not enough credits: $0.4000 available, up to $0.4572 needed. Buy credits to continue.',
    ]);

    expect(Generation::count())->toBe(0)
        ->and(pixelforge()->createRequests())->toBe([])
        ->and($user->availableBalance()->nanodollars())->toBe(400_000_000)
        ->and($user->ledgerEntries()->count())->toBe(1);
});

it('charges the exact Price when a Generation succeeds and releases the rest of the Hold', function () {
    $user = userWithOneDollar();
    submitLighthouse($user);
    $generation = latestGeneration();
    pixelforge()->answerRetrieves($generation, Reported::succeeded());

    pollGenerations();

    // The docs' usage costs 305,510,000 nanodollars; ×5/4 is a Price of 381,887,500.
    expect(ledgerOf($generation))->toBe([
        'hold' => [LIGHTHOUSE_MAX_PRICE],
        'charge' => [381_887_500],
        'hold_release' => [LIGHTHOUSE_MAX_PRICE - 381_887_500],
    ])
        ->and($user->balance()->nanodollars())->toBe(1_000_000_000 - 381_887_500)
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000 - 381_887_500);
});

it('releases the whole Hold when a Generation fails after Pixelforge accepted it', function () {
    $user = userWithOneDollar();
    submitLighthouse($user);
    $generation = latestGeneration();
    pixelforge()->answerRetrieves($generation, Reported::failed());

    pollGenerations();

    expect(ledgerOf($generation))->toBe([
        'hold' => [LIGHTHOUSE_MAX_PRICE],
        'hold_release' => [LIGHTHOUSE_MAX_PRICE],
    ])
        ->and($user->balance()->nanodollars())->toBe(1_000_000_000)
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000);
});

it('releases the whole Hold when Pixelforge rejects the submission', function (string $errorCode) {
    $user = userWithOneDollar();
    pixelforge()->answerCreates(InMemoryPixelforgeClient::refusal($errorCode));

    submitLighthouse($user);
    $generation = latestGeneration();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and(ledgerOf($generation))->toBe([
            'hold' => [LIGHTHOUSE_MAX_PRICE],
            'hold_release' => [LIGHTHOUSE_MAX_PRICE],
        ])
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000);
})->with([
    'content policy' => ['content_policy_violation'],
    'invalid request' => ['validation_failed'],
]);

it('releases the whole Hold when a submission runs out of attempts', function () {
    $user = userWithOneDollar();
    pixelforge()->answerCreates(...array_fill(0, 5, InMemoryPixelforgeClient::refusal('overloaded')));

    submitLighthouse($user);
    $generation = latestGeneration();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('retries_exhausted')
        ->and(ledgerOf($generation))->toBe([
            'hold' => [LIGHTHOUSE_MAX_PRICE],
            'hold_release' => [LIGHTHOUSE_MAX_PRICE],
        ])
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000);
});

it('rejects a second submission, from another tab while the first is in flight, that the first one\'s Hold leaves short', function () {
    // Enough for one lighthouse, not two.
    $user = User::factory()->withTopUp(Money::ofNanodollars(800_000_000))->create();
    pixelforge()->answerCreates(function () use ($user) {
        submitLighthouse($user)->assertSessionHasErrors(['generate' => 'Not enough credits: $0.3429 available, up to $0.4572 needed. Buy credits to continue.']);

        return Reported::queued();
    });

    submitLighthouse($user)->assertRedirect(route('dashboard'));

    expect(Generation::count())->toBe(1)
        ->and(pixelforge()->createRequests())->toHaveCount(1)
        ->and($user->availableBalance()->nanodollars())->toBe(800_000_000 - LIGHTHOUSE_MAX_PRICE);
});

it('locks the user\'s row before reading the Available Balance, so concurrent submissions queue behind each other', function () {
    // One process can't race two requests, so this checks the lock they would queue on.
    $user = userWithOneDollar();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    submitLighthouse($user)->assertRedirect(route('dashboard'));

    $userLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "users"') && str_ends_with($sql, 'for update'));
    $balanceRead = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "ledger_entries"'));
    $holdWritten = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "ledger_entries"'));
    expect($userLock)->toBeInt()
        ->and($balanceRead)->toBeInt()->toBeGreaterThan($userLock)
        ->and($holdWritten)->toBeInt()->toBeGreaterThan($balanceRead);
});

it('lets a submission use the whole Available Balance', function () {
    $user = User::factory()->withTopUp(Money::ofNanodollars(LIGHTHOUSE_MAX_PRICE))->create();

    submitLighthouse($user)->assertRedirect(route('dashboard'));

    expect($user->availableBalance()->nanodollars())->toBe(0);
});

it('charges the whole Hold and releases nothing when the Price is capped at the Maximum Price', function () {
    $user = userWithOneDollar();
    // Pixelforge doubled its rates since the Maximum Price was computed.
    pixelforge()->answerCreates(Reported::queued(lockedRates: Rates::fromPixelforge([
        'input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00',
    ])));
    submitLighthouse($user);
    $generation = latestGeneration();
    pixelforge()->answerRetrieves($generation, Reported::succeeded());

    pollGenerations();

    expect(ledgerOf($generation))->toBe([
        'hold' => [LIGHTHOUSE_MAX_PRICE],
        'charge' => [LIGHTHOUSE_MAX_PRICE],
    ])
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000 - LIGHTHOUSE_MAX_PRICE);
});

it('finishes a Generation opened before Holds existed without charging or releasing anything', function () {
    $user = userWithOneDollar();
    $succeeds = Generation::factory()->for($user)->accepted()->create();
    $fails = Generation::factory()->for($user)->accepted()->create();
    pixelforge()->answerRetrieves($succeeds, Reported::succeeded());
    pixelforge()->answerRetrieves($fails, Reported::failed());

    pollGenerations();

    expect($succeeds->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($succeeds->price?->nanodollars())->toBe(381_887_500)
        ->and($fails->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and(ledgerOf($succeeds))->toBe([])
        ->and(ledgerOf($fails))->toBe([])
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000);
});

it('refuses, in the database, a second Charge or Hold release for a Generation', function (LedgerEntryKind $kind) {
    $user = userWithOneDollar();
    submitLighthouse($user);
    $generation = latestGeneration();
    pixelforge()->answerRetrieves($generation, Reported::succeeded());
    pollGenerations();

    expect(fn () => $user->ledgerEntries()->create(['generation_id' => $generation->id, 'kind' => $kind, 'amount' => Money::ofNanodollars(1)]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([LedgerEntryKind::Charge, LedgerEntryKind::HoldRelease]);

it('releases the whole Hold when reconciliation gives up on a Generation Pixelforge never finished', function () {
    $user = userWithOneDollar();
    submitLighthouse($user);
    $generation = latestGeneration();

    $this->travel(Generation::GIVE_UP_AFTER_MINUTES + 1)->minutes();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and(ledgerOf($generation))->toBe([
            'hold' => [LIGHTHOUSE_MAX_PRICE],
            'hold_release' => [LIGHTHOUSE_MAX_PRICE],
        ])
        ->and($user->availableBalance()->nanodollars())->toBe(1_000_000_000);
});
