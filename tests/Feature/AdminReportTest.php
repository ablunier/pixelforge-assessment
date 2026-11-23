<?php

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\GenerationParameters;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\PixelforgeError;
use App\Pricing\Usage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\NoAnswer;
use Tests\Pixelforge\UsageRequest;

/*
 * The docs' lighthouse example, succeeded with the docs' usage at the
 * published rates: a Provider Cost of $0.30551 and a Price of $0.3818875.
 */
const LIGHTHOUSE_PROVIDER_COST = 305_510_000;
const LIGHTHOUSE_PRICE = 381_887_500;

function admin(): User
{
    return User::factory()->admin()->create();
}

/**
 * A Generation of the lighthouse example that `$user` pressed Generate for at
 * `$createdAt` and that Pixelforge reported succeeded at `$completedAt`. It goes
 * through the lifecycle, so it is Held and Charged like any other.
 */
function succeededAt(User $user, string $createdAt, string $completedAt, ?Usage $usage = null): Generation
{
    test()->travelTo($createdAt);
    $lifecycle = app(GenerationLifecycle::class);
    $generation = $lifecycle->open(
        $user,
        new GenerationParameters('A lighthouse on a cliff at dawn, oil painting', Size::Landscape, Quality::High, ['https://example.com/style.png']),
        InMemoryPixelforgeClient::publishedRates(),
    );

    return $lifecycle->apply($generation, new GenerationSnapshot(
        pixelforgeId: InMemoryPixelforgeClient::pixelforgeIdFor($generation->id),
        status: GenerationStatus::Succeeded,
        generationId: $generation->id,
        rates: InMemoryPixelforgeClient::publishedRates(),
        usage: $usage ?? new Usage(12, 765, 6_450, 3_184),
        errorCode: null,
        errorMessage: null,
        imageUrl: null,
        imageUrlExpiresAt: null,
        createdAt: CarbonImmutable::parse($createdAt),
        startedAt: CarbonImmutable::parse($createdAt),
        completedAt: CarbonImmutable::parse($completedAt),
    ));
}

it('denies the report to a user who isn\'t an admin', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('links to the report for admins only', function (bool $isAdmin) {
    $this->actingAs(User::factory()->create(['is_admin' => $isAdmin]))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAdminReport', $isAdmin));
})->with([
    'an admin' => [true],
    'not an admin' => [false],
]);

it('sends a guest to log in instead of showing the report', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});

it('makes a user an admin, who can then see the report', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('user:make-admin', ['email' => 'ops@example.com'])
        ->expectsOutputToContain('ops@example.com is now an admin.')
        ->assertSuccessful();

    $this->actingAs($user->fresh())->get(route('admin.report'))->assertOk();
});

it('fails to make an admin of an email no user has', function () {
    $this->artisan('user:make-admin', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user has the email nobody@example.com.')
        ->assertFailed();
});

it('shows the report to an admin', function () {
    $this->actingAs(admin())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AdminReport'));
});

it('reports a chosen range of up to 92 days', function () {
    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-08-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('range.from', '2026-08-01')
            ->where('range.to', '2026-10-31')
            ->has('days', 92)
            ->where('days.91.date', '2026-10-31'));
});

it('refuses a range it can\'t report', function (array $range, string $error) {
    $this->actingAs(admin())
        ->from(route('dashboard'))
        ->get(route('admin.report', $range))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors([$error]);
})->with([
    '93 days' => [['from' => '2026-07-31', 'to' => '2026-10-31'], 'to'],
    'ending before it starts' => [['from' => '2026-10-31', 'to' => '2026-10-30'], 'to'],
    'a malformed start' => [['from' => '31/10/2026', 'to' => '2026-10-31'], 'from'],
    'an impossible end' => [['from' => '2026-10-01', 'to' => '2026-02-30'], 'to'],
]);

it('covers the last 30 UTC days by default, with a row for each day even without activity', function () {
    $this->travelTo('2026-11-23T10:00:00Z');

    $this->actingAs(admin())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('range', ['from' => '2026-10-25', 'to' => '2026-11-23', 'maxDays' => 92])
            ->has('days', 30)
            ->where('days.0', ['date' => '2026-10-25', 'generations' => 0, 'charges' => 0, 'providerCost' => 0, 'profit' => 0, 'pixelforgeCost' => 0, 'costDifference' => 0])
            ->where('days.29.date', '2026-11-23')
            ->has('users', 0)
            ->where('totals', ['generations' => 0, 'charges' => 0, 'providerCost' => 0, 'profit' => 0, 'pixelforgeCost' => 0, 'costDifference' => 0]));
});

it('counts only Generations that succeeded within the range', function () {
    $user = User::factory()->withTopUp()->create();
    succeededAt($user, '2026-11-02T23:58:00Z', '2026-11-02T23:59:59Z');
    succeededAt($user, '2026-11-04T23:59:00Z', '2026-11-05T00:00:00Z');
    Generation::factory()->for($user)->failed()->create(['completed_at' => '2026-11-03T12:00:00Z']);
    Generation::factory()->for($user)->accepted(GenerationStatus::Processing)->create();
    Generation::factory()->for($user)->create();

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-04']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals', ['generations' => 0, 'charges' => 0, 'providerCost' => 0, 'profit' => 0, 'pixelforgeCost' => 0, 'costDifference' => 0])
            ->has('users', 0));
});

it('counts the Provider Cost of a Generation from before Balances existed, with no Charge', function () {
    // Succeeded before Holds and Charges were written: it has a Price but no ledger entries.
    $generation = Generation::factory()->succeeded()->create(['completed_at' => '2026-11-03T12:00:00Z']);

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-03']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals', ['generations' => 1, 'charges' => 0, 'providerCost' => LIGHTHOUSE_PROVIDER_COST, 'profit' => -LIGHTHOUSE_PROVIDER_COST, 'pixelforgeCost' => 0, 'costDifference' => -LIGHTHOUSE_PROVIDER_COST])
            ->where('users.0.userId', $generation->user_id)
            ->where('users.0.charges', 0));
});

it('totals Charges, Provider Cost and Profit exactly, per day and per user', function () {
    // A small Generation: a Provider Cost of $0.01517 and a Price of $0.0189625.
    $small = new Usage(14, 0, 280, 312);
    $alice = User::factory()->withTopUp()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->withTopUp()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $carol = User::factory()->create(['name' => 'Carol', 'email' => 'carol@example.com']);
    succeededAt($alice, '2026-11-03T09:00:00Z', '2026-11-03T09:00:20Z');
    succeededAt($alice, '2026-11-04T09:00:00Z', '2026-11-04T09:00:20Z', $small);
    succeededAt($bob, '2026-11-04T10:00:00Z', '2026-11-04T10:00:20Z');
    succeededAt($bob, '2026-11-04T23:00:00Z', '2026-11-04T23:00:20Z');
    // From before Balances existed: no Charge.
    Generation::factory()->for($carol)->succeeded()->create(['completed_at' => '2026-11-03T15:00:00Z']);
    pixelforge()->answerUsage(InMemoryPixelforgeClient::billed(['2026-11-03' => 611_020_000, '2026-11-04' => 626_190_000]));

    $response = $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-04']));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('days', [
            ['date' => '2026-11-03', 'generations' => 2, 'charges' => 381_887_500, 'providerCost' => 611_020_000, 'profit' => -229_132_500, 'pixelforgeCost' => 611_020_000, 'costDifference' => 0],
            ['date' => '2026-11-04', 'generations' => 3, 'charges' => 782_737_500, 'providerCost' => 626_190_000, 'profit' => 156_547_500, 'pixelforgeCost' => 626_190_000, 'costDifference' => 0],
        ])
        ->where('totals', ['generations' => 5, 'charges' => 1_164_625_000, 'providerCost' => 1_237_210_000, 'profit' => -72_585_000, 'pixelforgeCost' => 1_237_210_000, 'costDifference' => 0])
        ->where('users', [
            ['userId' => $bob->id, 'name' => 'Bob', 'email' => 'bob@example.com', 'generations' => 2, 'charges' => 763_775_000, 'providerCost' => 611_020_000, 'profit' => 152_755_000],
            ['userId' => $alice->id, 'name' => 'Alice', 'email' => 'alice@example.com', 'generations' => 2, 'charges' => 400_850_000, 'providerCost' => 320_680_000, 'profit' => 80_170_000],
            ['userId' => $carol->id, 'name' => 'Carol', 'email' => 'carol@example.com', 'generations' => 1, 'charges' => 0, 'providerCost' => 305_510_000, 'profit' => -305_510_000],
        ]));

    // The per-user breakdown and the daily rows each add up to the totals.
    $props = $response->inertiaProps();
    foreach (['generations', 'charges', 'providerCost', 'profit'] as $column) {
        expect(array_sum(array_column($props['users'], $column)))->toBe($props['totals'][$column])
            ->and(array_sum(array_column($props['days'], $column)))->toBe($props['totals'][$column]);
    }
});

it('still counts the Generations of a user who deleted their account', function () {
    $user = User::factory()->withTopUp()->create(['name' => 'Dana', 'email' => 'dana@example.com']);
    succeededAt($user, '2026-11-03T09:00:00Z', '2026-11-03T09:00:20Z');
    $user->delete();

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-03']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.charges', LIGHTHOUSE_PRICE)
            ->where('users', [
                ['userId' => $user->id, 'name' => 'Dana', 'email' => 'dana@example.com', 'generations' => 1, 'charges' => LIGHTHOUSE_PRICE, 'providerCost' => LIGHTHOUSE_PROVIDER_COST, 'profit' => LIGHTHOUSE_PRICE - LIGHTHOUSE_PROVIDER_COST],
            ]));
});

it('counts a Generation on the UTC day it completed, not the day it was created', function () {
    $user = User::factory()->withTopUp()->create();
    succeededAt($user, '2026-11-03T23:59:00Z', '2026-11-04T00:01:00Z');

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-04']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0', ['date' => '2026-11-03', 'generations' => 0, 'charges' => 0, 'providerCost' => 0, 'profit' => 0, 'pixelforgeCost' => 0, 'costDifference' => 0])
            ->where('days.1', [
                'date' => '2026-11-04',
                'generations' => 1,
                'charges' => LIGHTHOUSE_PRICE,
                'providerCost' => LIGHTHOUSE_PROVIDER_COST,
                'profit' => 76_377_500,
                'pixelforgeCost' => 0,
                'costDifference' => -LIGHTHOUSE_PROVIDER_COST,
            ]));
});

it('asks Pixelforge what it billed for the selected days', function () {
    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-04']))
        ->assertOk();

    expect(pixelforge()->usageRequests())->toEqual([new UsageRequest('2026-11-03', '2026-11-04')]);
});

it('shows what Pixelforge billed each day, flagging only the days it differs from the Provider Cost', function () {
    $user = User::factory()->withTopUp()->create();
    succeededAt($user, '2026-11-03T09:00:00Z', '2026-11-03T09:00:20Z');
    succeededAt($user, '2026-11-04T09:00:00Z', '2026-11-04T09:00:20Z');
    pixelforge()->answerUsage(InMemoryPixelforgeClient::billed([
        '2026-11-03' => LIGHTHOUSE_PROVIDER_COST,
        // One nanodollar more than recorded here.
        '2026-11-04' => LIGHTHOUSE_PROVIDER_COST + 1,
        '2026-11-05' => 0,
    ]));

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pixelforgeReachable', true)
            ->where('days.0.pixelforgeCost', LIGHTHOUSE_PROVIDER_COST)
            ->where('days.0.costDifference', 0)
            ->where('days.1.pixelforgeCost', LIGHTHOUSE_PROVIDER_COST + 1)
            ->where('days.1.costDifference', 1)
            ->where('days.2.pixelforgeCost', 0)
            ->where('days.2.costDifference', 0)
            ->where('totals.providerCost', 611_020_000)
            ->where('totals.pixelforgeCost', 611_020_001)
            ->where('totals.costDifference', 1));
});

it('flags a day Pixelforge billed less than the Provider Cost with a negative difference', function () {
    $user = User::factory()->withTopUp()->create();
    succeededAt($user, '2026-11-03T09:00:00Z', '2026-11-03T09:00:20Z');
    pixelforge()->answerUsage(InMemoryPixelforgeClient::billed(['2026-11-03' => 0]));

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.pixelforgeCost', 0)
            ->where('days.0.costDifference', -LIGHTHOUSE_PROVIDER_COST));
});

it('counts a day missing from Pixelforge\'s usage as $0 billed, flagging any Provider Cost that day', function () {
    $user = User::factory()->withTopUp()->create();
    succeededAt($user, '2026-11-04T09:00:00Z', '2026-11-04T09:00:20Z');
    // Pixelforge's usage leaves out 2026-11-04.
    pixelforge()->answerUsage(InMemoryPixelforgeClient::billed(['2026-11-03' => 0]));

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-04']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.1.date', '2026-11-04')
            ->where('days.1.pixelforgeCost', 0)
            ->where('days.1.costDifference', -LIGHTHOUSE_PROVIDER_COST));
});

it('compares the totals against the total Pixelforge reports', function () {
    pixelforge()->answerUsage(InMemoryPixelforgeClient::billed(['2026-11-03' => 0], total: 5));

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.costDifference', 0)
            ->where('totals.pixelforgeCost', 5)
            ->where('totals.costDifference', 5));
});

it('still shows the local figures when Pixelforge\'s usage can\'t be had', function (PixelforgeError|NoAnswer $answer) {
    Exceptions::fake();
    $user = User::factory()->withTopUp()->create();
    succeededAt($user, '2026-11-03T09:00:00Z', '2026-11-03T09:00:20Z');
    pixelforge()->answerUsage($answer);

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-03']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pixelforgeReachable', false)
            ->where('days.0.providerCost', LIGHTHOUSE_PROVIDER_COST)
            ->where('days.0.pixelforgeCost', null)
            ->where('days.0.costDifference', null)
            ->where('totals.providerCost', LIGHTHOUSE_PROVIDER_COST)
            ->where('totals.pixelforgeCost', null)
            ->where('totals.costDifference', null));
})->with([
    'rate limited' => [InMemoryPixelforgeClient::refusal('rate_limit_exceeded', retryAfter: 1)],
    'overloaded' => [InMemoryPixelforgeClient::refusal('overloaded')],
    'our API key refused' => [InMemoryPixelforgeClient::refusal('invalid_api_key')],
    'unreachable' => [NoAnswer::Unreachable],
    'unreadable' => [NoAnswer::Unreadable],
]);

it('asks Pixelforge again for the same days only after a minute', function () {
    $range = ['from' => '2026-11-03', 'to' => '2026-11-04'];
    $this->actingAs(admin());

    $this->get(route('admin.report', $range));
    $this->travel(59)->seconds();
    $this->get(route('admin.report', $range));
    expect(pixelforge()->usageRequests())->toHaveCount(1);

    $this->get(route('admin.report', ['from' => '2026-11-03', 'to' => '2026-11-05']));
    expect(pixelforge()->usageRequests())->toHaveCount(2);

    $this->travel(2)->seconds();
    $this->get(route('admin.report', $range));
    expect(pixelforge()->usageRequests())->toHaveCount(3);
});

it('asks Pixelforge again on the next refresh after its usage couldn\'t be had', function () {
    Exceptions::fake();
    pixelforge()->answerUsage(InMemoryPixelforgeClient::refusal('overloaded'));
    $range = ['from' => '2026-11-03', 'to' => '2026-11-03'];
    $this->actingAs(admin());

    $this->get(route('admin.report', $range))->assertInertia(fn (Assert $page) => $page->where('pixelforgeReachable', false));
    $this->get(route('admin.report', $range))->assertInertia(fn (Assert $page) => $page->where('pixelforgeReachable', true));

    expect(pixelforge()->usageRequests())->toHaveCount(2);
});
