<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\Reported;

beforeEach(function () {
    // On the docs' timeline, so a succeeded Generation's image URL hasn't expired yet.
    $this->travelTo('2026-11-04T14:03:00Z');
});

/** Press Generate with the docs' lighthouse example, whose Maximum Price is $0.45715. */
function pressGenerateWithLighthouse(User $user): TestResponse
{
    return test()->actingAs($user)->from(route('dashboard'))->post(route('generations.store'), [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ]);
}

it('gives the main page the user\'s Available Balance', function () {
    $user = User::factory()->withTopUp(Money::ofNanodollars(1_000_000_000))->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('availableBalance', 1_000_000_000));
});

it('takes active Holds off the Available Balance on the main page', function () {
    $user = User::factory()->withTopUp(Money::ofNanodollars(1_000_000_000))->create();

    pressGenerateWithLighthouse($user)->assertRedirect(route('generations.index'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('availableBalance', 1_000_000_000 - 457_150_000)
            ->where('balance', 1_000_000_000));
});

it('gives a released Hold back to the Available Balance once the Generation fails', function () {
    $user = User::factory()->withTopUp(Money::ofNanodollars(1_000_000_000))->create();
    pressGenerateWithLighthouse($user);
    pixelforge()->answerRetrieves(Generation::query()->sole(), Reported::failed());
    $this->travel(61)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(Generation::query()->sole()->status)->toBe(GenerationStatus::Failed);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('availableBalance', 1_000_000_000));
});

it('shows the insufficient-credits rejection on the main page as an error under generate', function () {
    // $0.40 left, short of the lighthouse's Maximum Price of $0.45715.
    $user = User::factory()->withTopUp(Money::ofNanodollars(400_000_000))->create();

    pressGenerateWithLighthouse($user)->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('errors.generate', 'Not enough credits: $0.4000 available, up to $0.4572 needed. Buy credits to continue.')
            ->where('availableBalance', 400_000_000));
});
