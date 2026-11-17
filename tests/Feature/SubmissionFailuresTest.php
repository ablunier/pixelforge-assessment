<?php

use App\Console\Commands\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeError;
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

function rateLimited(): PromiseInterface
{
    return pixelforgeRejects(429, 'rate_limit_error', 'rate_limit_exceeded', 'Too many requests.', ['Retry-After' => '12']);
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

it('retries a rate-limited submission and records the attempts', function () {
    fakeCreateResponses([rateLimited(), accepted()]);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->submission_attempts)->toBe(2)
        ->and(createRequestsSent())->toBe(2);
});

/**
 * Run the submit job for a `pending` Generation with the queue faked, so the
 * retry it schedules is captured rather than run, and return each retry's delay.
 *
 * @return list<int|null>
 */
function retryDelaysAfterSubmitting(int $times): array
{
    Queue::fake();
    $generation = pressGenerate();

    for ($i = 0; $i < $times; $i++) {
        // With the queue faked, dispatchSync() would only be recorded, so run the job directly.
        app()->call([new SubmitGenerationToPixelforge($generation), 'handle']);
    }

    return Queue::pushed(SubmitGenerationToPixelforge::class)
        ->skip(1) // The submission pressing Generate dispatched.
        ->map(fn (SubmitGenerationToPixelforge $job) => $job->delay)
        ->values()
        ->all();
}

it('waits Retry-After seconds before retrying', function () {
    fakeCreateResponses([rateLimited(), overloaded()]);

    expect(retryDelaysAfterSubmitting(2))->toBe([12, 2]);
});

it('backs off exponentially when Pixelforge sends no Retry-After', function () {
    fakeCreateResponses(array_fill(0, 4, pixelforgeRejects(503, 'api_error', 'overloaded', 'Overloaded.')));

    expect(retryDelaysAfterSubmitting(4))->toBe([2, 4, 8, 16]);
});

it('fails a Generation with retries exhausted after 5 overloaded attempts, with no Price', function () {
    fakeCreateResponses(array_fill(0, 5, overloaded()));

    $generation = pressGenerate('A lighthouse [overloaded]');

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('retries_exhausted')
        ->and($generation->submission_attempts)->toBe(5)
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->price)->toBeNull()
        ->and(createRequestsSent())->toBe(5);
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

it('leaves a Generation pending, not failed, when every response was lost, since Pixelforge may hold it', function () {
    fakeCreateResponses(array_fill(0, 5, responseLost()));

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->error_code)->toBeNull()
        ->and($generation->submission_attempts)->toBe(5)
        ->and(createRequestsSent())->toBe(5);
});

it('treats an unexplained server error like a lost response, since Pixelforge may have created the Generation', function () {
    fakeCreateResponses(array_fill(0, 5, Http::response('<html>504 Gateway Time-out</html>', 504)));

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submission_attempts)->toBe(5)
        ->and(createRequestsSent())->toBe(5);
});

it('keeps a Generation pending once a response was lost, even if later attempts are refused as busy', function () {
    fakeCreateResponses([responseLost(), ...array_fill(0, 4, overloaded())]);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submission_attempts)->toBe(5);
});

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

it('fails a Generation whose responses were lost for 20 attempts, with no Price and a readable reason', function () {
    fakeCreateResponses(array_fill(0, 21, responseLost()));
    $generation = pressGenerate();

    reconcileResends(16);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('provider_unreachable')
        ->and($generation->submission_attempts)->toBe(20)
        ->and($generation->price)->toBeNull()
        ->and(createRequestsSent())->toBe(20);

    $this->actingAs($generation->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.error.reason', "Couldn't reach Pixelforge, gave up after 20 attempts"));
});

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
    Exceptions::assertReported(fn (PixelforgeError $error) => $error->isAuthFailure());

    reconcileResends(1);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('stops resending a Generation after 20 attempts with a refused API key, failing it with a readable reason', function () {
    Exceptions::fake();
    fakeCreateResponses(array_fill(0, 21, apiKeyRefused()));
    $generation = pressGenerate();

    reconcileResends(20);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('invalid_api_key')
        ->and($generation->submission_attempts)->toBe(20)
        ->and($generation->price)->toBeNull()
        ->and(createRequestsSent())->toBe(20);

    $this->actingAs($generation->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.error.reason', "PixelForge couldn't sign in to its image provider; try again later"));
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

function concurrencyLimited(): PromiseInterface
{
    return pixelforgeRejects(429, 'rate_limit_error', 'concurrency_limit_exceeded', 'Too many generations in progress.', ['Retry-After' => '5']);
}

it('lets a Generation wait its turn while Pixelforge is at its concurrency limit, without using up attempts', function () {
    fakeCreateResponses([...array_fill(0, 8, concurrencyLimited()), accepted()]);

    $generation = pressGenerate();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submission_attempts)->toBe(1)
        ->and(createRequestsSent())->toBe(9);
});

it('counts concurrency waits as attempts once a Generation has waited 15 minutes', function () {
    fakeCreateResponses(array_fill(0, 5, concurrencyLimited()));
    $generation = Generation::factory()->create(['created_at' => now()->subMinutes(15)]);

    SubmitGenerationToPixelforge::dispatch($generation);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('retries_exhausted')
        ->and($generation->submission_attempts)->toBe(5)
        ->and(createRequestsSent())->toBe(5);
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
    'validation' => [422, 'invalid_request_error', 'validation_failed'],
    'daily limit' => [429, 'rate_limit_error', 'daily_limit_exceeded'],
    'idempotency key reused' => [409, 'idempotency_error', 'idempotency_key_reused'],
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
    ['something_new', 'Pixelforge couldn\'t complete this Generation'],
]);

it('leaves a pending Generation alone while its submission may still be retrying', function () {
    fakeCreateResponses([accepted()]);
    $generation = Generation::factory()->create(['updated_at' => now()->subSeconds(119)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and(createRequestsSent())->toBe(0);
});
