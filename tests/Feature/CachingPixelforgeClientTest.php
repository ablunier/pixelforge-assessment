<?php

use App\Pixelforge\CachingPixelforgeClient;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\PixelforgeUnreachable;
use App\Pixelforge\PricingUnavailable;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use App\Pricing\Rates;
use Illuminate\Support\Facades\Exceptions;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\NoAnswer;

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

/**
 * Ways rates can't be quoted, and what each throws below the decorator.
 *
 * @return array<string, array{PixelforgeError|NoAnswer, class-string<Throwable>}>
 */
dataset('rates that can\'t be quoted', fn () => [
    'unreachable' => [NoAnswer::Unreachable, PixelforgeUnreachable::class],
    'refused' => [InMemoryPixelforgeClient::refusal('overloaded'), PixelforgeError::class],
    'unreadable' => [NoAnswer::Unreadable, UnexpectedPixelforgeResponse::class],
]);

it('caches the rates for about an hour', function () {
    $pixelforge = new InMemoryPixelforgeClient;
    $pixelforge->answerRates(quotedRates(), changedRates());
    $client = cachingClientOver($pixelforge);

    expect($client->rates())->toEqual(quotedRates());
    $this->travel(59)->minutes();
    expect($client->rates())->toEqual(quotedRates())
        ->and($pixelforge->rateRequests())->toBe(1);

    $this->travel(2)->minutes();

    expect($client->rates())->toEqual(changedRates())
        ->and($pixelforge->rateRequests())->toBe(2);
});

it('prices with the last rates quoted while they can\'t be quoted, asking again after a minute', function (PixelforgeError|NoAnswer $failure, string $reported) {
    Exceptions::fake();
    $pixelforge = new InMemoryPixelforgeClient;
    $pixelforge->answerRates(quotedRates(), $failure, changedRates());
    $client = cachingClientOver($pixelforge);

    $client->rates();
    $this->travel(61)->minutes();

    expect($client->rates())->toEqual(quotedRates())
        ->and($client->rates())->toEqual(quotedRates())
        ->and($pixelforge->rateRequests())->toBe(2);
    Exceptions::assertReported($reported);

    $this->travel(61)->seconds();

    expect($client->rates())->toEqual(changedRates())
        ->and($pixelforge->rateRequests())->toBe(3);
})->with('rates that can\'t be quoted');

it('never caches rates it can\'t read, so the next call asks again', function () {
    $pixelforge = new InMemoryPixelforgeClient;
    $pixelforge->answerRates(NoAnswer::Unreadable, quotedRates());
    $client = cachingClientOver($pixelforge);

    expect(fn () => $client->rates())->toThrow(PricingUnavailable::class)
        ->and($client->rates())->toEqual(quotedRates())
        ->and($pixelforge->rateRequests())->toBe(2);
});

it('is unavailable when no rates have ever been quoted', function (PixelforgeError|NoAnswer $failure) {
    $pixelforge = new InMemoryPixelforgeClient;
    $pixelforge->answerRates($failure);
    $client = cachingClientOver($pixelforge);

    expect(fn () => $client->rates())->toThrow(PricingUnavailable::class);
})->with('rates that can\'t be quoted');
