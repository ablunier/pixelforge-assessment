<?php

use App\Enums\GenerationStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('lists the user\'s Generations newest first with status, Maximum Price, and Price or error once final', function () {
    $user = User::factory()->create();

    $succeeded = Generation::factory()->for($user)->succeeded()->create([
        'prompt' => 'A lighthouse',
        'max_price' => Money::ofNanodollars(457_150_000),
    ]);

    $this->travel(1)->minute();
    $failed = Generation::factory()->for($user)->failed()->create(['prompt' => 'A [fail] lighthouse']);

    $this->travel(1)->minute();
    $pending = Generation::factory()->for($user)->create(['prompt' => 'A lighthouse at night']);

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generate')
            ->has('generations', 3)
            ->where('generations.0.id', $pending->id)
            ->where('generations.0.status', 'pending')
            ->where('generations.0.price', null)
            ->where('generations.0.error', null)
            ->where('generations.1.id', $failed->id)
            ->where('generations.1.status', 'failed')
            ->where('generations.1.price', null)
            ->where('generations.1.error', [
                'code' => 'generation_failed',
                'message' => "The model couldn't produce an image for this prompt.",
                'reason' => "The model couldn't produce an image",
            ])
            ->where('generations.2', fn ($row) => collect($row)->only(['id', 'prompt', 'size', 'quality', 'status', 'maxPrice', 'price', 'error'])->all() === [
                'id' => $succeeded->id,
                'prompt' => 'A lighthouse',
                'size' => '1536x1024',
                'quality' => 'high',
                'status' => 'succeeded',
                'maxPrice' => 457_150_000,
                'price' => 381_887_500,
                'error' => null,
            ])
        );
});

it('never shows a user another user\'s Generations', function () {
    $user = User::factory()->create();
    $own = Generation::factory()->for($user)->create();
    Generation::factory()->for(User::factory())->create(['prompt' => 'Someone else\'s secret prompt']);

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertOk()
        ->assertDontSee('Someone else\'s secret prompt')
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations', 1)
            ->where('generations.0.id', $own->id)
        );
});

it('signals the history to keep polling while any of the user\'s Generations isn\'t final', function (string $state) {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->for($user)->failed()->create();
    match ($state) {
        'pending' => Generation::factory()->for($user)->create(),
        'queued' => Generation::factory()->for($user)->accepted()->create(),
        'processing' => Generation::factory()->for($user)->accepted(GenerationStatus::Processing)->create(),
    };

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page->where('pollHistory', true));
})->with(['pending', 'queued', 'processing']);

it('signals the history to stop polling once all the user\'s Generations are final', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page->where('pollHistory', false));
});

it('shows an empty history, not polling, to a user with no Generations', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations', 0)
            ->where('pollHistory', false)
        );
});

it('caps the history at the user\'s 50 newest Generations', function () {
    $user = User::factory()->create();
    $oldest = Generation::factory()->for($user)->succeeded()->create();
    $this->travel(1)->minute();
    Generation::factory()->for($user)->succeeded()->count(49)->create();
    $this->travel(1)->minute();
    $newest = Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations', 50)
            ->where('generations.0.id', $newest->id)
            ->where('generations', fn ($rows) => ! collect($rows)->contains('id', $oldest->id))
        );
});

it('refreshes only the history when polled, without asking Pixelforge for rates', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->accepted()->create();

    $this->actingAs($user)
        ->get(route('generate'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Generate',
            'X-Inertia-Partial-Data' => 'generations,pollHistory',
        ])
        ->assertOk()
        ->assertJsonPath('props.generations.0.id', $generation->id)
        ->assertJsonPath('props.pollHistory', true)
        ->assertJsonMissingPath('props.pricing');

    expect(pixelforge()->rateRequests())->toBe(0);
});

it('shows a newly submitted Generation at the top of the history as pending, and keeps polling', function () {
    Queue::fake();
    $user = User::factory()->withTopUp()->create();
    Generation::factory()->for($user)->succeeded()->create();
    $this->travel(1)->minute();

    $this->actingAs($user)
        ->followingRedirects()
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse at night',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations', 2)
            ->where('generations.0.prompt', 'A lighthouse at night')
            ->where('generations.0.status', 'pending')
            ->where('pollHistory', true)
        );
});
