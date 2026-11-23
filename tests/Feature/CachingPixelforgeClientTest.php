<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\CachingPixelforgeClient;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\PixelforgeUnreachable;
use App\Pixelforge\PricingUnavailable;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use App\Pricing\Rates;
use Illuminate\Support\Facades\Exceptions;

/**
 * An adapter that quotes each rates() call from a script: Rates, or the
 * failure to throw. It records how often it was asked.
 */
function scriptedRates(Rates|Throwable ...$answers): PixelforgeClient
{
    return new class($answers) implements PixelforgeClient
    {
        public int $ratesCalls = 0;

        /** @param  list<Rates|Throwable>  $answers */
        public function __construct(private array $answers) {}

        public function rates(): Rates
        {
            $answer = $this->answers[$this->ratesCalls++] ?? throw new LogicException('No more rates scripted.');

            return $answer instanceof Throwable ? throw $answer : $answer;
        }

        public function createGeneration(string $generationId, string $prompt, Size $size, Quality $quality, array $referenceImages): GenerationSnapshot
        {
            throw new LogicException('Not scripted.');
        }

        public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot
        {
            throw new LogicException('Not scripted.');
        }
    };
}

function quotedRates(): Rates
{
    return Rates::fromPixelforge([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);
}

function changedRates(): Rates
{
    return Rates::fromPixelforge([
        'input_text_tokens' => '1.00',
        'input_image_tokens' => '1.00',
        'output_tokens' => '1.00',
        'reasoning_tokens' => '1.00',
    ]);
}

function cachingClientOver(PixelforgeClient $pixelforge): CachingPixelforgeClient
{
    return new CachingPixelforgeClient($pixelforge, cache()->store());
}

it('caches the rates for about an hour', function () {
    $pixelforge = scriptedRates(quotedRates(), changedRates());
    $client = cachingClientOver($pixelforge);

    expect($client->rates())->toEqual(quotedRates());
    $this->travel(59)->minutes();
    expect($client->rates())->toEqual(quotedRates())
        ->and($pixelforge->ratesCalls)->toBe(1);

    $this->travel(2)->minutes();

    expect($client->rates())->toEqual(changedRates())
        ->and($pixelforge->ratesCalls)->toBe(2);
});

it('prices with the last rates quoted while they can\'t be quoted, asking again after a minute', function (Throwable $failure) {
    Exceptions::fake();
    $pixelforge = scriptedRates(quotedRates(), $failure, changedRates());
    $client = cachingClientOver($pixelforge);

    $client->rates();
    $this->travel(61)->minutes();

    expect($client->rates())->toEqual(quotedRates())
        ->and($client->rates())->toEqual(quotedRates())
        ->and($pixelforge->ratesCalls)->toBe(2);
    Exceptions::assertReported($failure::class);

    $this->travel(61)->seconds();

    expect($client->rates())->toEqual(changedRates())
        ->and($pixelforge->ratesCalls)->toBe(3);
})->with([
    'unreachable' => fn () => new PixelforgeUnreachable('Connection refused.'),
    'refused' => fn () => new PixelforgeError('overloaded', 'Overloaded.', 503),
    'unreadable' => fn () => new UnexpectedPixelforgeResponse('No rates.'),
]);

it('never caches rates it can\'t read, so the next call asks again', function () {
    $pixelforge = scriptedRates(new UnexpectedPixelforgeResponse('No rates.'), quotedRates());
    $client = cachingClientOver($pixelforge);

    expect(fn () => $client->rates())->toThrow(PricingUnavailable::class)
        ->and($client->rates())->toEqual(quotedRates())
        ->and($pixelforge->ratesCalls)->toBe(2);
});

it('is unavailable when no rates have ever been quoted', function (Throwable $failure) {
    $client = cachingClientOver(scriptedRates($failure));

    expect(fn () => $client->rates())->toThrow(PricingUnavailable::class);
})->with([
    'unreachable' => fn () => new PixelforgeUnreachable('Connection refused.'),
    'refused' => fn () => new PixelforgeError('overloaded', 'Overloaded.', 503),
    'unreadable' => fn () => new UnexpectedPixelforgeResponse('No rates.'),
]);
