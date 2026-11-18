<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Money\Money;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use App\Pricing\Usage;

function docsRates(): Rates
{
    return Rates::fromPixelforge([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);
}

it('prices the docs\' worked maximum: Provider Cost $0.373875000 plus Markup', function () {
    $range = Pricing::priceRange(400, Size::Landscape, Quality::High, 2, docsRates());

    // Provider Cost 0.373875000 × 1.25
    expect($range->max->formatExact())->toBe('0.467343750');
});

it('prices the lowest end of the same Generation', function () {
    $range = Pricing::priceRange(400, Size::Landscape, Quality::High, 2, docsRates());

    // 85 text, 1,530 image, 6,450 output and 1,000 reasoning tokens: Provider Cost 0.286225000 × 1.25
    expect($range->min->formatExact())->toBe('0.357781250');
});

/**
 * Rates charging $1.00 per 1M tokens (1,000 nanodollars a token) for one kind
 * of token and nothing for the rest, so a Price isolates that kind: tokens × 1,250.
 */
function onlyCharging(string $kind): Rates
{
    return Rates::fromPixelforge(array_merge([
        'input_text_tokens' => '0.00',
        'input_image_tokens' => '0.00',
        'output_tokens' => '0.00',
        'reasoning_tokens' => '0.00',
    ], [$kind => '1.00']));
}

it('charges the exact output tokens for every size and quality', function (Size $size, Quality $quality, int $price) {
    $range = Pricing::priceRange(0, $size, $quality, 0, onlyCharging('output_tokens'));

    expect($range->min->nanodollars())->toBe($price)
        ->and($range->max->nanodollars())->toBe($price);
})->with([
    'low square' => [Size::Square, Quality::Low, 350_000],              // 280 tokens
    'low landscape' => [Size::Landscape, Quality::Low, 525_000],        // 420
    'low portrait' => [Size::Portrait, Quality::Low, 525_000],          // 420
    'medium square' => [Size::Square, Quality::Medium, 1_375_000],      // 1,100
    'medium landscape' => [Size::Landscape, Quality::Medium, 2_062_500], // 1,650
    'medium portrait' => [Size::Portrait, Quality::Medium, 2_062_500],  // 1,650
    'high square' => [Size::Square, Quality::High, 5_375_000],          // 4,300
    'high landscape' => [Size::Landscape, Quality::High, 8_062_500],    // 6,450
    'high portrait' => [Size::Portrait, Quality::High, 8_062_500],      // 6,450
]);

it('bounds input text tokens at 15% either side of ceil(characters / 4)', function (int $characters, int $min, int $max) {
    $range = Pricing::priceRange($characters, Size::Square, Quality::Low, 0, onlyCharging('input_text_tokens'));

    expect($range->min->nanodollars())->toBe($min)
        ->and($range->max->nanodollars())->toBe($max);
})->with([
    'empty prompt' => [0, 0, 0],
    '1 character: estimate 1, 0 to 2 tokens' => [1, 0, 2_500],
    '7 characters: estimate 2, 1 to 3 tokens' => [7, 1_250, 3_750],
    '400 characters: estimate 100, 85 to 115 tokens' => [400, 106_250, 143_750],
    '4,000 characters: estimate 1,000, 850 to 1,150 tokens' => [4_000, 1_062_500, 1_437_500],
]);

it('spans the reasoning token range for each quality', function (Quality $quality, int $min, int $max) {
    $range = Pricing::priceRange(0, Size::Square, $quality, 0, onlyCharging('reasoning_tokens'));

    expect($range->min->nanodollars())->toBe($min)
        ->and($range->max->nanodollars())->toBe($max);
})->with([
    'low: 0 to 500' => [Quality::Low, 0, 625_000],
    'medium: 200 to 2,000' => [Quality::Medium, 250_000, 2_500_000],
    'high: 1,000 to 8,000' => [Quality::High, 1_250_000, 10_000_000],
]);

it('charges 765 input image tokens per reference image', function () {
    $range = Pricing::priceRange(0, Size::Square, Quality::Low, 4, onlyCharging('input_image_tokens'));

    // 3,060 tokens
    expect($range->min->nanodollars())->toBe(3_825_000)
        ->and($range->max->nanodollars())->toBe(3_825_000);
});

it('computes the Provider Cost of real usage at the locked rates', function () {
    $usage = Usage::fromPixelforge([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
        'total_tokens' => 10411,
    ]);

    expect(Pricing::providerCost($usage, docsRates())->formatExact())->toBe('0.305510000');
});

/** A Maximum Price no test cost comes near. */
function noCap(): Money
{
    return Money::fromDecimal('1000.00');
}

it('prices a Provider Cost with the Markup, exact to the nanodollar', function () {
    expect(Pricing::price(Money::fromDecimal('0.305510000'), noCap())->formatExact())->toBe('0.381887500')
        ->and(Pricing::price(Money::fromDecimal('0.169080000'), noCap())->formatExact())->toBe('0.211350000');
});

it('never prices a Provider Cost above the Maximum Price', function (string $maximumPrice, string $price) {
    expect(Pricing::price(Money::fromDecimal('0.305510000'), Money::fromDecimal($maximumPrice))->formatExact())->toBe($price);
})->with([
    'below the cap' => ['0.381887501', '0.381887500'],
    'exactly at the cap' => ['0.381887500', '0.381887500'],
    'above the cap: the Maximum Price' => ['0.300000000', '0.300000000'],
]);

it('rounds the Markup half up to the nanodollar when the cost is not divisible by 4', function (int $cost, int $price) {
    expect(Pricing::price(Money::ofNanodollars($cost), noCap())->nanodollars())->toBe($price);
})->with([
    [1, 1],   // 1.25
    [2, 3],   // 2.5
    [3, 4],   // 3.75
    [4, 5],
]);

it('rejects rates that are not a whole number of nanodollars per token', function () {
    Rates::fromPixelforge([
        'input_text_tokens' => '5.0001',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);
})->throws(InvalidArgumentException::class);
