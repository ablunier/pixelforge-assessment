<?php

use App\Actions\GenerationLifecycle;
use App\Console\Commands\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Answer;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\Refusal;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'pf_test_key',
    ]);
    fakePixelforgePricing();

    // Before the fixture's image URL expires, so a replayed success downloads its image.
    $this->travelTo('2026-11-04T14:03:00Z');
});

/**
 * Press Generate. The queue runs synchronously, so this also runs the submit
 * job and every retry it schedules.
 */
function pressGenerate(string $prompt = 'A lighthouse on a cliff at dawn, oil painting'): Generation
{
    test()->actingAs(User::factory()->create())
        ->post(route('generations.store'), [
            'prompt' => $prompt,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
        ])
        ->assertRedirect(route('dashboard'));

    return Generation::query()->latest('id')->firstOrFail();
}

/**
 * Pixelforge answers successive create requests with these responses.
 *
 * @param  list<PromiseInterface|Closure>  $responses
 */
function fakeCreateResponses(array $responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $sequence->pushResponse($response);
    }

    Http::fake(['pixelforge.test/v1/generations' => $sequence]);
}

/**
 * @param  array<string, string>  $headers
 */
function pixelforgeRejects(int $status, string $type, string $code, string $message, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => $type, 'code' => $code, 'message' => $message]], $status, $headers);
}

function overloaded(): PromiseInterface
{
    return pixelforgeRejects(503, 'api_error', 'overloaded', 'Pixelforge is temporarily overloaded.', ['Retry-After' => '2']);
}

function accepted(string $status = 'queued'): PromiseInterface
{
    return Http::response(pixelforgeGeneration($status), 202);
}

function createRequestsSent(): int
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST')->count();
}

it('never queues a second submission for a Generation whose retry is still waiting in the queue', function () {
    Queue::fake();
    $generation = pressGenerate();

    // The queue is so busy the job hasn't started by the time reconciliation looks.
    $this->travel(ReconcileGenerations::PENDING_STALE_AFTER_SECONDS + 1)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

function unreadableAcceptance(): PromiseInterface
{
    return Http::response(pixelforgeGeneration('queued', ['status' => 'canceled']), 202);
}

it('resends a submission Pixelforge accepted in a response this app can\'t read, and reports it', function () {
    Exceptions::fake();
    fakeCreateResponses([unreadableAcceptance(), accepted()]);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submission_attempts)->toBe(2)
        ->and(createRequestsSent())->toBe(2);
    Exceptions::assertReported(UnexpectedPixelforgeResponse::class);
});

it('resends a submission whose response was lost, with the same Idempotency-Key, and applies the replayed Generation', function () {
    fakeCreateResponses([
        Http::failedConnection('cURL error 28: Operation timed out'),
        Http::response(pixelforgeGeneration('queued'), 202, ['Idempotent-Replayed' => 'true']),
    ]);

    $generation = pressGenerate();

    $keys = Http::recorded(fn (Request $request) => $request->method() === 'POST')
        ->map(fn (array $exchange) => $exchange[0]->header('Idempotency-Key'))
        ->values()
        ->all();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submission_attempts)->toBe(2)
        ->and($keys)->toBe([[$generation->id], [$generation->id]]);
});

function responseLost(): Closure
{
    return Http::failedConnection('cURL error 28: Operation timed out');
}

it('resends a Generation left pending after lost responses from reconciliation, and applies the replay', function () {
    fakeCreateResponses([...array_fill(0, 5, responseLost()), Http::response(pixelforgeGeneration('queued'), 202, ['Idempotent-Replayed' => 'true'])]);
    $generation = pressGenerate();

    $this->travel(ReconcileGenerations::PENDING_STALE_AFTER_SECONDS + 1)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and(createRequestsSent())->toBe(6);
});

/**
 * Let reconciliation resend a stuck `pending` Generation `$times` times.
 */
function reconcileResends(int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->travel(ReconcileGenerations::PENDING_STALE_AFTER_SECONDS + 1)->seconds();
        test()->artisan('generations:reconcile')->assertSuccessful();
    }
}

function apiKeyRefused(): PromiseInterface
{
    return pixelforgeRejects(401, 'authentication_error', 'invalid_api_key', 'Missing or invalid API key.');
}

it('keeps a Generation pending and reports the problem when Pixelforge refuses the API key, so a fixed key picks it up', function () {
    Exceptions::fake();
    fakeCreateResponses([apiKeyRefused(), accepted()]);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submission_attempts)->toBe(1);
    Exceptions::assertReported(fn (PixelforgeError $error) => $error->refusal() === Refusal::KeyRefused);

    reconcileResends(1);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('records the Price once when an idempotent replay returns a Generation that already succeeded', function () {
    fakeCreateResponses([
        overloaded(),
        Http::response(pixelforgeGeneration('succeeded'), 202, ['Idempotent-Replayed' => 'true']),
    ]);

    $generation = pressGenerate();

    // 12×5,000 + 765×10,000 + 6,450×40,000 + 3,184×12,500 nanodollars, then ×5/4.
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('fails a permanently rejected Generation straight away with Pixelforge\'s code and no Price', function (int $status, string $type, string $code) {
    fakeCreateResponses([pixelforgeRejects($status, $type, $code, 'Rejected by Pixelforge.')]);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toBe('Rejected by Pixelforge.')
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->price)->toBeNull()
        ->and($generation->submission_attempts)->toBe(1)
        ->and(createRequestsSent())->toBe(1);
})->with([
    'content policy' => [400, 'invalid_request_error', 'content_policy_violation'],
    'daily limit' => [429, 'rate_limit_error', 'daily_limit_exceeded'],
]);

it('resubmits a pending Generation whose submission died, and it reaches queued', function () {
    fakeCreateResponses([accepted()]);
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(121)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->hasHeader('Idempotency-Key', $generation->id));
    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
});

it('explains each failure in plain language in the history', function (string $code, string $reason) {
    $user = User::factory()->create();
    Generation::factory()->for($user)->failed()
        ->afterMaking(fn (Generation $generation) => $generation->error_code = $code)
        ->create(['submission_attempts' => 5]);

    $this->actingAs($user)
        ->get(route('dashboard'))
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
    fakeCreateResponses([accepted()]);
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(119)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and(createRequestsSent())->toBe(0);
});

it('renews a Generation\'s lease while it waits its turn at the concurrency limit, so reconciliation leaves it alone', function () {
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(ReconcileGenerations::PENDING_STALE_AFTER_SECONDS + 1)]);

    app(GenerationLifecycle::class)->recordSubmissionAttempt(
        $generation,
        Answer::refused(Refusal::NotYet, 'concurrency_limit_exceeded', 'Too many generations in progress.', 5),
    );

    expect($generation->refresh()->updated_at?->equalTo(now()))->toBeTrue()
        ->and($generation->submission_attempts)->toBe(0);
});

it('queues the retry Pixelforge asks for, after the delay the submission rules decide', function () {
    Queue::fake();
    fakeCreateResponses([pixelforgeRejects(429, 'rate_limit_error', 'rate_limit_exceeded', 'Too many requests.', ['Retry-After' => '12'])]);
    $generation = Generation::factory()->create();

    app()->call([new SubmitGenerationToPixelforge($generation), 'handle']);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->delay === 12
        && $job->generation->is($generation));
    expect($generation->refresh()->submission_attempts)->toBe(1);
});
