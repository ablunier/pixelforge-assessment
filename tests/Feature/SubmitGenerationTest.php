<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('saves a submitted Generation as pending with its server-computed Maximum Price and queues its submission', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            // Ignored: the Maximum Price is always computed on the server.
            'max_price' => 1,
        ])
        ->assertRedirect(route('dashboard'));

    $generation = Generation::sole();

    expect($generation->user->is($user))->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->prompt)->toBe('A lighthouse on a cliff at dawn, oil painting')
        ->and($generation->reference_images)->toBe(['https://example.com/style.png'])
        // 45 characters → 12 text tokens, at most 14; 765 image, 6,450 output
        // and at most 8,000 reasoning tokens: $0.365720000 plus 25% Markup.
        ->and($generation->max_price->nanodollars())->toBe(457_150_000)
        ->and($generation->price)->toBeNull();

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->generation->is($generation));
});

it('saves nothing when the submitted form is invalid', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []])
        ->assertSessionHasErrors('prompt');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});
