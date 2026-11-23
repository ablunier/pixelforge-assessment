<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\HttpPixelforgeClient;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\PixelforgeUnreachable;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use App\Pricing\Rates;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The wire contract with Pixelforge's API: what HttpPixelforgeClient sends and
 * how it reads what comes back. Everything else talks to Pixelforge through the
 * in-memory adapter the base test case binds.
 */

function httpPixelforge(?string $apiKey = 'pf_test_key'): HttpPixelforgeClient
{
    return new HttpPixelforgeClient(app(Factory::class), 'https://pixelforge.test/v1', $apiKey);
}

/**
 * A Pixelforge Generation object, shaped like the docs' example.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationObject(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => ['generation_id' => '01JC2Z8PZ0LOCAL0000000000A'],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * A Pixelforge usage object for 2026-11-01 to 2026-11-02, shaped like the docs'
 * example but with a cost a float would lose.
 *
 * @return array<string, mixed>
 */
function usageObject(string|float $firstDayCost = '0.169080001'): array
{
    $tokens = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];
    $none = ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];

    return [
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, ...$tokens, 'cost_usd' => $firstDayCost],
            ['date' => '2026-11-02', 'generations' => 0, ...$none, 'cost_usd' => '0.000000000'],
        ],
        'total' => ['generations' => 3, ...$tokens, 'cost_usd' => $firstDayCost],
    ];
}

/**
 * @param  list<string>  $referenceImages
 */
function createLighthouse(HttpPixelforgeClient $pixelforge, array $referenceImages = ['https://example.com/style.png']): GenerationSnapshot
{
    return $pixelforge->createGeneration('01JC2Z8PZ0LOCAL0000000000A', 'A lighthouse on a cliff at dawn, oil painting', Size::from('1536x1024'), Quality::High, $referenceImages);
}

it('creates a Generation with exactly the documented params, its local id as the Idempotency-Key and in metadata, and the API key', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(generationObject(), 202)]);

    $snapshot = createLighthouse(httpPixelforge());

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', '01JC2Z8PZ0LOCAL0000000000A')
        && $request->data() === [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => '01JC2Z8PZ0LOCAL0000000000A'],
        ]);
    expect($snapshot->pixelforgeId)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($snapshot->status)->toBe(GenerationStatus::Queued)
        ->and($snapshot->generationId)->toBe('01JC2Z8PZ0LOCAL0000000000A');
});

it('leaves out reference images when there are none', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(generationObject(), 202)]);

    createLighthouse(httpPixelforge(), referenceImages: []);

    Http::assertSent(fn (Request $request) => ! array_key_exists('reference_images', $request->data()));
});

it('sends no Authorization header while no API key is configured', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(generationObject(), 202)]);

    createLighthouse(httpPixelforge(apiKey: null));

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

it('retrieves a Generation by its Pixelforge id', function () {
    Http::fake(['pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(generationObject([
        'status' => 'succeeded',
        'image_url' => 'https://images.pixelforge.test/lighthouse.png',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => '2026-11-04T14:03:29Z',
    ]))]);

    $snapshot = httpPixelforge()->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    expect($snapshot->status)->toBe(GenerationStatus::Succeeded)
        ->and($snapshot->usage?->reasoningTokens)->toBe(3_184)
        ->and($snapshot->imageUrl)->toBe('https://images.pixelforge.test/lighthouse.png')
        ->and($snapshot->imageUrlExpiresAt?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($snapshot->completedAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
});

it('reads the current rates from GET /v1/pricing', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response([
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ])]);

    $rates = httpPixelforge()->rates();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/pricing');
    expect($rates)->toEqual(Rates::fromPixelforge(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']));
});

it('reads what Pixelforge billed per UTC day from GET /v1/usage, exactly', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usageObject())]);

    $billed = httpPixelforge()->billedCosts(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-02'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    expect($billed->on('2026-11-01')->nanodollars())->toBe(169_080_001)
        ->and($billed->on('2026-11-02')->nanodollars())->toBe(0)
        // A day the answer leaves out counts as nothing billed.
        ->and($billed->on('2026-11-03')->nanodollars())->toBe(0)
        ->and($billed->total->nanodollars())->toBe(169_080_001);
});

it('downloads an image from its signed URL as is, without the API key', function () {
    Http::fake(['images.pixelforge.test/lighthouse.png*' => Http::response("\x89PNG\r\n\x1a\nlighthouse", 200, ['Content-Type' => 'image/png'])]);

    $png = httpPixelforge()->downloadImage('https://images.pixelforge.test/lighthouse.png?expires=1762351409&signature=abc');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://images.pixelforge.test/lighthouse.png?expires=1762351409&signature=abc'
        && ! $request->hasHeader('Authorization'));
    expect($png)->toBe("\x89PNG\r\n\x1a\nlighthouse");
});

it('turns an error body into a PixelforgeError with its code, message, status and Retry-After', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(
        ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Pixelforge is temporarily overloaded.']],
        503,
        ['Retry-After' => '2'],
    )]);

    expect(fn () => createLighthouse(httpPixelforge()))->toThrow(function (PixelforgeError $error) {
        expect($error->errorCode)->toBe('overloaded')
            ->and($error->getMessage())->toBe('Pixelforge is temporarily overloaded.')
            ->and($error->httpStatus)->toBe(503)
            ->and($error->retryAfter)->toBe(2);
    });
});

it('names an error without a body by its HTTP status', function (Closure $call) {
    Http::fake(['pixelforge.test/*' => Http::response('Bad gateway', 502)]);

    expect(fn () => $call(httpPixelforge()))->toThrow(function (PixelforgeError $error) {
        expect($error->errorCode)->toBe('http_502')
            ->and($error->getMessage())->toBe('Pixelforge answered HTTP 502.')
            ->and($error->retryAfter)->toBeNull();
    });
})->with([
    'create' => [fn (HttpPixelforgeClient $pixelforge) => createLighthouse($pixelforge)],
    'retrieve' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')],
    'rates' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->rates()],
    'billed costs' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->billedCosts(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'))],
    'image download' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->downloadImage('https://pixelforge.test/images/lighthouse.png')],
]);

it('throws PixelforgeUnreachable when no response comes back', function (Closure $call) {
    Http::fake(['pixelforge.test/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    expect(fn () => $call(httpPixelforge()))->toThrow(PixelforgeUnreachable::class);
})->with([
    'create' => [fn (HttpPixelforgeClient $pixelforge) => createLighthouse($pixelforge)],
    'retrieve' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')],
    'rates' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->rates()],
    'billed costs' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->billedCosts(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'))],
    'image download' => [fn (HttpPixelforgeClient $pixelforge) => $pixelforge->downloadImage('https://pixelforge.test/images/lighthouse.png')],
]);

it('throws UnexpectedPixelforgeResponse for a success body it can\'t read', function (string $url, array $body, Closure $call) {
    Http::fake([$url => Http::response($body)]);

    expect(fn () => $call(httpPixelforge()))->toThrow(UnexpectedPixelforgeResponse::class);
})->with([
    'a Generation in an unknown status' => [
        'pixelforge.test/v1/generations',
        generationObject(['status' => 'canceled']),
        fn (HttpPixelforgeClient $pixelforge) => createLighthouse($pixelforge),
    ],
    'a success without usage' => [
        'pixelforge.test/v1/generations/*',
        generationObject(['status' => 'succeeded']),
        fn (HttpPixelforgeClient $pixelforge) => $pixelforge->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'),
    ],
    'pricing without rates' => [
        'pixelforge.test/v1/pricing',
        ['object' => 'pricing'],
        fn (HttpPixelforgeClient $pixelforge) => $pixelforge->rates(),
    ],
    'usage without a total' => [
        'pixelforge.test/v1/usage*',
        ['object' => 'usage', 'data' => usageObject()['data']],
        fn (HttpPixelforgeClient $pixelforge) => $pixelforge->billedCosts(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02')),
    ],
    'usage with a rounded cost' => [
        'pixelforge.test/v1/usage*',
        usageObject(firstDayCost: 0.16908),
        fn (HttpPixelforgeClient $pixelforge) => $pixelforge->billedCosts(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02')),
    ],
]);

it('is what the app talks to Pixelforge through, behind the rate cache', function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1', 'services.pixelforge.api_key' => 'pf_test_key']);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response([
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ])]);
    // Undo the base test case's in-memory binding, back to the app's own.
    app()->forgetInstance(PixelforgeClient::class);

    app(PixelforgeClient::class)->rates();
    app(PixelforgeClient::class)->rates();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/pricing'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});
