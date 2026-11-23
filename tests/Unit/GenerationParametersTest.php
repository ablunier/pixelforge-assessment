<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\GenerationParameters;
use App\Pricing\Rates;

function listedRates(): Rates
{
    return Rates::fromPixelforge([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);
}

function lighthouseParameters(): GenerationParameters
{
    return new GenerationParameters('A lighthouse on a cliff at dawn, oil painting', Size::Landscape, Quality::High, ['https://example.com/style.png']);
}

it('prices the range from its prompt length, size, quality and reference image count', function () {
    $range = lighthouseParameters()->priceRange(listedRates());

    // 45 characters → 12 text tokens, 10 to 14; 765 image and 6,450 output tokens;
    // 1,000 to 8,000 reasoning tokens. Provider Costs $0.278200000 to $0.365720000, plus 25% Markup.
    expect($range->min->nanodollars())->toBe(347_750_000)
        ->and($range->max->nanodollars())->toBe(457_150_000);
});

it('has the top of its Price Range as its Maximum Price', function () {
    expect(lighthouseParameters()->maximumPrice(listedRates())->nanodollars())->toBe(457_150_000);
});

it('counts the prompt in characters, not bytes', function () {
    $ascii = new GenerationParameters(str_repeat('a', 400), Size::Square, Quality::Low, []);
    $multibyte = new GenerationParameters(str_repeat('é', 400), Size::Square, Quality::Low, []);

    expect($multibyte->maximumPrice(listedRates())->nanodollars())
        ->toBe($ascii->maximumPrice(listedRates())->nanodollars());
});
