<?php

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Answer;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\Refusal;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\NoAnswer;
use Tests\Pixelforge\Reported;

beforeEach(function () {
    // Before the fixture's image URL expires, so a replayed success downloads its image.
    $this->travelTo('2026-11-04T14:03:00Z');
});

/**
 * Press Generate. The queue runs synchronously, so this also runs the submit
 * job and every retry it schedules.
 */
function pressGenerate(string $prompt = 'A lighthouse on a cliff at dawn, oil painting'): Generation
{
    test()->actingAs(User::factory()->withTopUp()->create())
        ->post(route('generations.store'), [
            'prompt' => $prompt,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
        ])
        ->assertRedirect(route('generations.index'));

    return Generation::query()->latest('id')->firstOrFail();
}

function createRequestsSent(): int
{
    return count(pixelforge()->createRequests());
}

it('never queues a second submission for a Generation whose retry is still waiting in the queue', function () {
    Queue::fake();
    $generation = pressGenerate();

    // The queue is so busy the job hasn't started by the time reconciliation looks.
    $this->travel(Generation::PENDING_STALE_AFTER_SECONDS + 1)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

it('resends a submission Pixelforge accepted in a response this app can\'t read, and reports it', function () {
    Exceptions::fake();
    pixelforge()->answerCreates(NoAnswer::Unreadable);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submission_attempts)->toBe(2)
        ->and(createRequestsSent())->toBe(2);
    Exceptions::assertReported(UnexpectedPixelforgeResponse::class);
});

it('keeps a Generation pending and resends it when Pixelforge accepted it but the acceptance couldn\'t be recorded', function () {
    Exceptions::fake();
    Queue::fake();
    $generation = Generation::factory()->create();
    // Another Generation already holds the `gen_` id Pixelforge gives it, so recording the acceptance breaks its unique constraint.
    Generation::factory()->accepted()->create(['pixelforge_id' => InMemoryPixelforgeClient::pixelforgeIdFor($generation->id)]);

    app()->call([new SubmitGenerationToPixelforge($generation), 'handle']);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->pixelforge_id)->toBeNull()
        ->and($generation->submission_attempts)->toBe(1)
        ->and($generation->submission_outcome_unknown)->toBeTrue();
    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->delay === 2
        && $job->generation->is($generation));
    Exceptions::assertReported(UniqueConstraintViolationException::class);
});

it('resends a submission whose response was lost, with the same Idempotency-Key, and applies the replayed Generation', function () {
    pixelforge()->answerCreates(NoAnswer::ResponseLost);

    $generation = pressGenerate();

    $keys = array_map(fn ($request) => $request->generationId, pixelforge()->createRequests());

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submission_attempts)->toBe(2)
        ->and($keys)->toBe([$generation->id, $generation->id])
        ->and(pixelforge()->heldGenerations())->toBe(1);
});

it('resends a Generation left pending after lost responses from reconciliation, and applies the replay', function () {
    pixelforge()->answerCreates(...array_fill(0, 5, NoAnswer::ResponseLost));
    $generation = pressGenerate();

    $this->travel(Generation::PENDING_STALE_AFTER_SECONDS + 1)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe(InMemoryPixelforgeClient::pixelforgeIdFor($generation->id))
        ->and(createRequestsSent())->toBe(6)
        ->and(pixelforge()->heldGenerations())->toBe(1);
});

/**
 * Let reconciliation resend a stuck `pending` Generation `$times` times.
 */
function reconcileResends(int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->travel(Generation::PENDING_STALE_AFTER_SECONDS + 1)->seconds();
        test()->artisan('generations:reconcile')->assertSuccessful();
    }
}

it('fails a Generation whose responses were lost for 20 attempts, with no Price and a readable reason, and stops resending it', function () {
    pixelforge()->answerCreates(...array_fill(0, 21, NoAnswer::ResponseLost));
    $generation = pressGenerate();

    // Pixelforge may hold it, so it outlives the 5-attempt budget for reconciliation's resends.
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submission_attempts)->toBe(5);

    reconcileResends(16);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('provider_unreachable')
        ->and($generation->submission_attempts)->toBe(20)
        ->and($generation->price)->toBeNull()
        ->and(createRequestsSent())->toBe(20);

    $this->actingAs($generation->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.error.reason', "Couldn't reach Pixelforge, gave up after 20 attempts"));

    reconcileResends(1);

    expect(createRequestsSent())->toBe(20);
});

it('stops resending a Generation after 20 attempts with a refused API key, failing it with Pixelforge\'s code', function () {
    Exceptions::fake();
    pixelforge()->answerCreates(...array_fill(0, 21, InMemoryPixelforgeClient::refusal('invalid_api_key')));
    $generation = pressGenerate();

    reconcileResends(20);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('invalid_api_key')
        ->and($generation->submission_attempts)->toBe(20)
        ->and($generation->price)->toBeNull()
        ->and(createRequestsSent())->toBe(20);
});

it('keeps a Generation pending and reports the problem when Pixelforge refuses the API key, so a fixed key picks it up', function () {
    Exceptions::fake();
    pixelforge()->answerCreates(InMemoryPixelforgeClient::refusal('invalid_api_key'));

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submission_attempts)->toBe(1);
    Exceptions::assertReported(fn (PixelforgeError $error) => $error->refusal() === Refusal::KeyRefused);

    reconcileResends(1);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('records the Price once when an idempotent replay returns a Generation that already succeeded', function () {
    pixelforge()->answerCreates(InMemoryPixelforgeClient::refusal('overloaded', retryAfter: 2), Reported::succeeded());

    $generation = pressGenerate();

    // 12×5,000 + 765×10,000 + 6,450×40,000 + 3,184×12,500 nanodollars, then ×5/4.
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('fails a permanently rejected Generation straight away with Pixelforge\'s code and no Price', function (string $code) {
    pixelforge()->answerCreates(InMemoryPixelforgeClient::refusal($code, message: 'Rejected by Pixelforge.'));

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toBe('Rejected by Pixelforge.')
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->price)->toBeNull()
        ->and($generation->submission_attempts)->toBe(1)
        ->and(createRequestsSent())->toBe(1);
})->with([
    'content policy' => ['content_policy_violation'],
    'daily limit' => ['daily_limit_exceeded'],
]);

it('resubmits a pending Generation whose submission died, and it reaches queued', function () {
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(121)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(pixelforge()->createRequests())->toHaveCount(1)
        ->and(pixelforge()->createRequests()[0]->generationId)->toBe($generation->id)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe(InMemoryPixelforgeClient::pixelforgeIdFor($generation->id));
});

it('explains each failure in plain language in the history', function (string $code, string $reason) {
    $user = User::factory()->withTopUp()->create();
    Generation::factory()->for($user)->failed()
        ->afterMaking(fn (Generation $generation) => $generation->error_code = $code)
        ->create(['submission_attempts' => 5]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.error.code', $code)
            ->where('generations.0.error.reason', $reason)
        );
})->with([
    ['content_policy_violation', 'Blocked by content policy'],
    ['validation_failed', 'Pixelforge rejected the request as invalid'],
    ['daily_limit_exceeded', 'Daily provider limit reached'],
    ['idempotency_key_reused', 'Pixelforge rejected a conflicting resubmission'],
    ['generation_failed', "The model couldn't produce an image"],
    ['internal_error', 'Pixelforge had an internal error; trying again usually works'],
    ['retries_exhausted', 'Provider busy, gave up after 5 attempts'],
    ['provider_unreachable', "Couldn't reach Pixelforge, gave up after 5 attempts"],
    ['invalid_api_key', "PixelForge couldn't sign in to its image provider; try again later"],
    ['something_new', 'Pixelforge couldn\'t complete this Generation'],
]);

it('leaves a pending Generation alone while its submission may still be retrying', function () {
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(119)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and(createRequestsSent())->toBe(0);
});

it('renews a Generation\'s lease while it waits its turn at the concurrency limit, so reconciliation leaves it alone', function () {
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(Generation::PENDING_STALE_AFTER_SECONDS + 1)]);

    app(GenerationLifecycle::class)->recordSubmissionAttempt(
        $generation,
        Answer::refused(Refusal::NotYet, 'concurrency_limit_exceeded', 'Too many generations in progress.', 5),
    );

    expect($generation->refresh()->updated_at?->equalTo(now()))->toBeTrue()
        ->and($generation->submission_attempts)->toBe(0);
});

it('queues the retry Pixelforge asks for, after the delay the submission rules decide', function () {
    Queue::fake();
    pixelforge()->answerCreates(InMemoryPixelforgeClient::refusal('rate_limit_exceeded', retryAfter: 12));
    $generation = Generation::factory()->create();

    app()->call([new SubmitGenerationToPixelforge($generation), 'handle']);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->delay === 12
        && $job->generation->is($generation));
    expect($generation->refresh()->submission_attempts)->toBe(1);
});
