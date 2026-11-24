<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();
});

function seededExample(string $keyword): Generation
{
    return Generation::query()->where('prompt', 'like', "%{$keyword}%")->sole();
}

it('seeds the reviewer with example Generations without calling Pixelforge', function () {
    $this->seed();

    $reviewer = User::query()->where('email', 'reviewer@example.com')->sole();
    expect($reviewer->generations)->toHaveCount(4);
    Queue::assertNothingPushed();
});

it('prices the succeeded example at its Provider Cost plus the 25% Markup', function () {
    $this->seed();

    // The Pixelforge docs' example usage at the published rates.
    $generation = seededExample('[succeed]');
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500)
        ->and($generation->price?->isGreaterThan($generation->max_price))->toBeFalse();
});

it('records the duplicate-delivery example once, with one stored Webhook Event per event', function () {
    $this->seed();

    // 14 text, 280 output and 312 reasoning tokens: 15,170,000 nanodollars × 5/4.
    $generation = seededExample('[duplicate]');
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->nanodollars())->toBe(15_170_000)
        ->and($generation->price?->nanodollars())->toBe(18_962_500)
        ->and($generation->price?->isGreaterThan($generation->max_price))->toBeFalse();

    $events = WebhookEvent::query()->where('generation_id', $generation->id)->get();
    expect($events->pluck('type')->sort()->values()->all())->toBe(['generation.processing', 'generation.succeeded'])
        ->and($events->pluck('event_id')->unique())->toHaveCount(2);
});

it('seeds failures with no Provider Cost or Price and a readable reason', function () {
    $this->seed();

    foreach (['[fail]', '[blocked]'] as $keyword) {
        $generation = seededExample($keyword);
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->provider_cost)->toBeNull()
            ->and($generation->price)->toBeNull();
    }

    $this->actingAs(User::query()->where('email', 'reviewer@example.com')->sole())
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations', fn ($generations): bool => collect($generations)
                ->pluck('error.reason')->filter()->sort()->values()->all()
                === ['Blocked by content policy', "The model couldn't produce an image"]));
});

it('pays for the examples like any Generation: each success charged its Price, every Hold settled', function () {
    $this->seed();

    $reviewer = User::query()->where('email', 'reviewer@example.com')->sole();

    expect(ledgerOf(seededExample('[succeed]'))['charge'])->toBe([381_887_500])
        ->and(ledgerOf(seededExample('[duplicate]'))['charge'])->toBe([18_962_500])
        ->and(ledgerOf(seededExample('[fail]')))->not->toHaveKey('charge')
        ->and(ledgerOf(seededExample('[blocked]')))->not->toHaveKey('charge')
        ->and($reviewer->availableBalance()->nanodollars())->toBe($reviewer->balance()->nanodollars())
        ->and($reviewer->balance()->nanodollars())->toBeGreaterThanOrEqual(0);
});

it('seeds the reviewer as an admin with $10 of Credits, less the examples\' Charges', function () {
    $this->seed();

    $reviewer = User::query()->where('email', 'reviewer@example.com')->sole();
    // $10.00 minus the [succeed] and [duplicate] Charges.
    expect($reviewer->balance()->nanodollars())->toBe(9_599_150_000);
    $this->actingAs($reviewer)->get(route('admin.report'))->assertOk();
});

it('fabricates no example Generations in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->where('email', 'reviewer@example.com')->exists())->toBeTrue()
        ->and(Generation::query()->count())->toBe(0);
});
