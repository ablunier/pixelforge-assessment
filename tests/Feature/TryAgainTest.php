<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingUnavailable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\NoAnswer;

beforeEach(function () {
    Queue::fake();
});

it('creates a new pending Generation with the same parameters when trying a failed Generation again', function () {
    $user = User::factory()->withTopUp()->create();
    $failed = Generation::factory()->for($user)->failed()->create([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => Size::from('1536x1024'),
        'quality' => Quality::High,
        'reference_images' => ['https://example.com/style.png'],
    ]);
    $failedBefore = $failed->fresh()->getAttributes();

    $this->travel(1)->minute();

    $this->actingAs($user)
        ->post(route('generations.try-again', $failed))
        ->assertRedirect(route('generate'));

    $retry = Generation::query()->whereKeyNot($failed->id)->sole();

    expect($retry->id)->not->toBe($failed->id)
        ->and($retry->user->is($user))->toBeTrue()
        ->and($retry->status)->toBe(GenerationStatus::Pending)
        ->and($retry->prompt)->toBe('A lighthouse on a cliff at dawn, oil painting')
        ->and($retry->size)->toBe(Size::from('1536x1024'))
        ->and($retry->quality)->toBe(Quality::High)
        ->and($retry->reference_images)->toBe(['https://example.com/style.png'])
        // Same Maximum Price as a fresh submission of these parameters.
        ->and($retry->max_price)->toEqual($failed->parameters()->maximumPrice(InMemoryPixelforgeClient::publishedRates()))
        ->and($failed->fresh()->getAttributes())->toBe($failedBefore);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->generation->is($retry));

    $this->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.id', $retry->id)
            ->where('generations.0.status', 'pending')
            ->where('generations.1.id', $failed->id)
        );
});

it('creates nothing and says why when there are no rates to price the new Generation with', function () {
    Exceptions::fake();
    pixelforge()->answerRates(NoAnswer::Unreachable);
    $user = User::factory()->withTopUp()->create();
    $failed = Generation::factory()->for($user)->failed()->create();

    $this->actingAs($user)
        ->post(route('generations.try-again', $failed))
        ->assertSessionHasErrors(['generate' => "Prices can't be loaded right now, so nothing was submitted. Try again in a minute."]);

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
    Exceptions::assertReported(PricingUnavailable::class);
});

it('rejects trying again a Generation that isn\'t failed', function (string $state) {
    $user = User::factory()->withTopUp()->create();
    $generation = match ($state) {
        'pending' => Generation::factory()->for($user)->create(),
        'queued' => Generation::factory()->for($user)->accepted()->create(),
        'succeeded' => Generation::factory()->for($user)->succeeded()->create(),
    };

    $this->actingAs($user)
        ->post(route('generations.try-again', $generation))
        ->assertForbidden();

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['pending', 'queued', 'succeeded']);

it('rejects trying again another user\'s failed Generation', function () {
    $theirs = Generation::factory()->for(User::factory())->failed()->create();

    $this->actingAs(User::factory()->withTopUp()->create())
        ->post(route('generations.try-again', $theirs))
        ->assertForbidden();

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('requires a signed-in user to try again', function () {
    $failed = Generation::factory()->failed()->create();

    $this->post(route('generations.try-again', $failed))
        ->assertRedirect(route('login'));

    expect(Generation::count())->toBe(1);
});
