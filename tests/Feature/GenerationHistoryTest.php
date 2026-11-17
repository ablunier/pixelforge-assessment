<?php

use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    fakePixelforgePricing();
});

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
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
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
            ])
            ->where('generations.2', fn ($row) => collect($row)->only(['id', 'prompt', 'size', 'quality', 'status', 'maxPrice', 'price', 'error'])->all() === [
                'id' => $succeeded->id,
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
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
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Someone else\'s secret prompt')
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations', 1)
            ->where('generations.0.id', $own->id)
        );
});
