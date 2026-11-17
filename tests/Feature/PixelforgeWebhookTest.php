<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'pf_test_key',
        'services.pixelforge.webhook_secret' => WEBHOOK_SECRET,
    ]);
});

/**
 * A Webhook Event carrying `$generation` as `data.object`, shaped like the docs' example.
 *
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function webhookEvent(string $type, array $generation, string $id = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'): array
{
    return [
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

/**
 * The lighthouse Generation as Pixelforge would report it for `$generation`,
 * with the local id echoed in its metadata.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGenerationFor(Generation $generation, string $status, array $overrides = []): array
{
    return pixelforgeGeneration($status, ['metadata' => ['generation_id' => $generation->id], ...$overrides]);
}

/**
 * POST `$event` to the webhook endpoint the way Pixelforge does: the exact JSON
 * body, signed as `t=<timestamp>,v1=hex(HMAC-SHA256(secret, "<t>.<body>"))`.
 *
 * @param  array<string, mixed>  $event
 * @return TestResponse<Response>
 */
function deliverWebhook(array $event, ?int $timestamp = null, string $secret = WEBHOOK_SECRET, int $attempt = 1, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp ??= now()->getTimestamp();
    $signature ??= "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_USER_AGENT' => 'Pixelforge-Webhooks/1.0',
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'] ?? '',
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $attempt,
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ], content: $body);
}

it('moves a Generation to the status a valid Webhook Event reports', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    deliverWebhook(webhookEvent('generation.processing', pixelforgeGenerationFor($generation, 'processing')))
        ->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z');
});

/**
 * Deliver a `generation.succeeded` event for a fresh queued Generation that
 * `$deliver` mangles, and assert it's rejected without changing anything.
 *
 * @param  Closure(array<string, mixed>): TestResponse<Response>  $deliver
 */
function expectWebhookRejected(Closure $deliver): void
{
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    $deliver(webhookEvent('generation.succeeded', pixelforgeGenerationFor($generation, 'succeeded')))->assertStatus(400);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->price)->toBeNull();
}

it('rejects a Webhook Event signed with the wrong secret', function () {
    expectWebhookRejected(fn (array $event) => deliverWebhook($event, secret: 'whsec_forged'));
});

it('rejects a Webhook Event without a signature', function () {
    expectWebhookRejected(fn (array $event) => deliverWebhook($event, signature: ''));
});

it('rejects a Webhook Event whose body was changed after signing', function () {
    expectWebhookRejected(function (array $event) {
        $timestamp = now()->getTimestamp();
        $signed = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$signed}", WEBHOOK_SECRET);

        return deliverWebhook(array_replace_recursive($event, ['data' => ['object' => ['usage' => ['reasoning_tokens' => 0]]]]), signature: $signature);
    });
});

it('rejects a Webhook Event signed more than 300 seconds ago', function () {
    expectWebhookRejected(fn (array $event) => deliverWebhook($event, timestamp: now()->getTimestamp() - 301));
});

it('rejects a Webhook Event signed more than 300 seconds in the future', function () {
    expectWebhookRejected(fn (array $event) => deliverWebhook($event, timestamp: now()->getTimestamp() + 301));
});

it('accepts a Webhook Event signed 300 seconds ago', function () {
    $generation = Generation::factory()->accepted()->create();

    deliverWebhook(webhookEvent('generation.processing', pixelforgeGenerationFor($generation, 'processing')), timestamp: now()->getTimestamp() - 300)
        ->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects every Webhook Event while no signing secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    expectWebhookRejected(fn (array $event) => deliverWebhook($event, secret: ''));
});

it('rejects a signed body that is not a Webhook Event, and stores nothing', function (array $event) {
    deliverWebhook($event)->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
})->with([
    'no id' => [['object' => 'event', 'type' => 'ping']],
    'no type' => [['id' => 'evt_01JC3NOTYPE00000000000000', 'object' => 'event']],
    'generation event without a Generation' => [['id' => 'evt_01JC3NOOBJECT000000000000', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => []]],
]);

it('stores every Webhook Event it receives, with its Generation', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $event = webhookEvent('generation.succeeded', pixelforgeGenerationFor($generation, 'succeeded'));

    deliverWebhook($event, attempt: 3)->assertOk();

    $stored = WebhookEvent::query()->sole();

    expect($stored->event_id)->toBe('evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A')
        ->and($stored->type)->toBe('generation.succeeded')
        ->and($stored->generation_id)->toBe($generation->id)
        ->and($stored->payload)->toBe(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and($stored->delivery_attempt)->toBe(3)
        ->and($stored->received_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($stored->processed_at?->toIso8601String())->toBe(now()->toIso8601String());
});

it('acknowledges a duplicate Webhook Event without applying it again', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $event = webhookEvent('generation.succeeded', pixelforgeGenerationFor($generation, 'succeeded'));

    deliverWebhook($event, attempt: 1)->assertOk();
    $this->travel(10)->seconds();
    deliverWebhook($event, attempt: 2)->assertOk();
    $generation->refresh();

    $stored = WebhookEvent::query()->sole();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500)
        ->and($stored->delivery_attempt)->toBe(1)
        ->and($stored->processed_at?->toIso8601String())->toBe(now()->subSeconds(10)->toIso8601String());
});

it('ignores a processing event that arrives after the succeeded event', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    deliverWebhook(webhookEvent('generation.succeeded', pixelforgeGenerationFor($generation, 'succeeded'), id: 'evt_succeeded'))->assertOk();
    deliverWebhook(webhookEvent('generation.processing', pixelforgeGenerationFor($generation, 'processing'), id: 'evt_processing'), attempt: 2)->assertOk();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price?->nanodollars())->toBe(381_887_500)
        ->and(WebhookEvent::query()->count())->toBe(2);
});

it('never fails a Generation that a webhook already reported succeeded', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    deliverWebhook(webhookEvent('generation.succeeded', pixelforgeGenerationFor($generation, 'succeeded'), id: 'evt_succeeded'))->assertOk();
    deliverWebhook(webhookEvent('generation.failed', pixelforgeGenerationFor($generation, 'failed', [
        'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'],
    ]), id: 'evt_failed'))->assertOk();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->error_code)->toBeNull()
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('records a failure reported by webhook, with no Price', function () {
    $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    deliverWebhook(webhookEvent('generation.failed', pixelforgeGenerationFor($generation, 'failed', [
        'error' => ['code' => 'generation_failed', 'message' => "The model couldn't produce an image for this prompt."],
    ])))->assertOk();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->price)->toBeNull();
});

it('matches a webhook that arrives before the create response by the local id in metadata', function () {
    fakePixelforgePricing();
    $generation = Generation::factory()->create();

    // Pixelforge sends `generation.processing` while the create request is still in flight.
    Http::fake(['pixelforge.test/v1/generations' => function (Request $request) use ($generation) {
        deliverWebhook(webhookEvent('generation.processing', pixelforgeGenerationFor($generation, 'processing')))->assertOk();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        return Http::response(pixelforgeGeneration('queued', ['metadata' => $request['metadata']]), 202);
    }]);

    SubmitGenerationToPixelforge::dispatchSync($generation);
    $generation->refresh();

    // The late `queued` create response doesn't move it back.
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and(WebhookEvent::query()->sole()->generation_id)->toBe($generation->id);
});

it('treats a webhook as a no-op once polling has already found the Generation succeeded', function () {
    $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    Http::fake(['pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(pixelforgeGenerationFor($generation, 'succeeded'))]);

    $this->travel(61)->seconds();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);

    // A late webhook reporting different usage changes nothing.
    deliverWebhook(webhookEvent('generation.succeeded', pixelforgeGenerationFor($generation, 'succeeded', ['usage' => [
        'input_text_tokens' => 14,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
        'total_tokens' => 15229,
    ]])))->assertOk();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->reasoning_tokens)->toBe(3_184)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('matches a Generation by its Pixelforge id when metadata does not name it', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    deliverWebhook(webhookEvent('generation.processing', pixelforgeGeneration('processing', ['metadata' => []])))
        ->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and(WebhookEvent::query()->sole()->generation_id)->toBe($generation->id);
});

it('acknowledges and stores a Webhook Event that matches no Generation', function () {
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    deliverWebhook(webhookEvent('generation.processing', pixelforgeGeneration('processing', [
        'id' => 'gen_01JC9UNKNOWN00000000000000',
        'metadata' => ['generation_id' => '01JC9UNKNOWN00000000000000'],
    ])))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::query()->sole()->generation_id)->toBeNull();
});

it('acknowledges a ping and stores it without touching any Generation', function () {
    $generation = Generation::factory()->accepted()->create();

    deliverWebhook(['id' => 'evt_01JC3PING0000000000000000', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z'])
        ->assertOk();

    $stored = WebhookEvent::query()->sole();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($stored->type)->toBe('ping')
        ->and($stored->generation_id)->toBeNull()
        ->and($stored->processed_at)->not->toBeNull();
});
