<?php

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/**
 * @return array<string, mixed>
 */
function generateForm(): array
{
    return [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => [],
    ];
}

it('refuses to start a Generation while the user already has the most in progress', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(StoreGenerationRequest::MAX_GENERATIONS_IN_PROGRESS)
        ->sequence(['status' => GenerationStatus::Pending], ['status' => GenerationStatus::Queued], ['status' => GenerationStatus::Processing])
        ->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('generations.store'), generateForm())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['generate' => 'You have 5 Generations in progress. Wait for one to finish, then try again.']);

    expect($user->generations()->count())->toBe(StoreGenerationRequest::MAX_GENERATIONS_IN_PROGRESS);
    Queue::assertNothingPushed();
});

it('starts a Generation again once one in progress is final', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(StoreGenerationRequest::MAX_GENERATIONS_IN_PROGRESS - 1)->create();
    Generation::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generateForm())
        ->assertSessionHasNoErrors();

    expect($user->generations()->notFinal()->count())->toBe(StoreGenerationRequest::MAX_GENERATIONS_IN_PROGRESS);
});

it('refuses to start more Generations than the daily limit in any 24 hours', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->count(StoreGenerationRequest::MAX_GENERATIONS_PER_DAY)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generateForm())
        ->assertSessionHasErrors(['generate' => "You've started 50 Generations in the last 24 hours, the most allowed. Try again later."]);

    $this->travel(1)->day();
    $this->travel(1)->second();

    $this->actingAs($user)
        ->post(route('generations.store'), generateForm())
        ->assertSessionHasNoErrors();

    expect($user->generations()->count())->toBe(StoreGenerationRequest::MAX_GENERATIONS_PER_DAY + 1);
});

it('applies the same limits to Try again', function () {
    $user = User::factory()->create();
    $failed = Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for($user)->count(StoreGenerationRequest::MAX_GENERATIONS_IN_PROGRESS)->create();

    $this->actingAs($user)
        ->post(route('generations.try-again', $failed))
        ->assertSessionHasErrors('generate');

    expect($user->generations()->count())->toBe(StoreGenerationRequest::MAX_GENERATIONS_IN_PROGRESS + 1);
});

it('throttles how many Generations a user starts per minute, counting Try again', function () {
    $user = User::factory()->create();
    $failed = Generation::factory()->for($user)->failed()->create();

    for ($i = 0; $i < AppServiceProvider::GENERATIONS_PER_MINUTE; $i++) {
        // Final at once, so the in-progress limit never bites.
        $this->actingAs($user)->post(route('generations.try-again', $failed))->assertSessionHasNoErrors();
        $user->generations()->notFinal()->update(['status' => GenerationStatus::Failed]);
    }

    $this->actingAs($user)
        ->post(route('generations.store'), generateForm())
        ->assertRedirect()
        ->assertSessionHasErrors(['generate' => 'Too many Generations at once. Wait a minute, then try again.']);

    // Other users aren't held up.
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), generateForm())
        ->assertSessionHasNoErrors();

    $this->travel(61)->seconds();

    $this->actingAs($user)
        ->post(route('generations.store'), generateForm())
        ->assertSessionHasNoErrors();
});

it('never throttles live validation of the generate form', function () {
    $user = User::factory()->create();

    for ($i = 0; $i <= AppServiceProvider::GENERATIONS_PER_MINUTE; $i++) {
        $this->actingAs($user)
            ->withHeaders(['Precognition' => 'true'])
            ->post(route('generations.store'), generateForm())
            ->assertNoContent();
    }

    $this->actingAs($user)
        ->withHeaders(['Precognition' => 'false'])
        ->post(route('generations.store'), generateForm())
        ->assertSessionHasNoErrors();

    expect($user->generations()->count())->toBe(1);
});
