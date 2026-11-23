<?php

namespace App\Generations;

use App\Enums\Quality;
use App\Enums\Size;
use App\Money\Money;
use App\Pricing\PriceRange;
use App\Pricing\Pricing;
use App\Pricing\Rates;

/**
 * What the user chooses for a Generation: its prompt, size, quality and
 * reference images. They set its Price Range and Maximum Price, and Try again
 * reuses them.
 */
final readonly class GenerationParameters
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public array $referenceImages,
    ) {}

    public function priceRange(Rates $rates): PriceRange
    {
        return Pricing::priceRange(mb_strlen($this->prompt), $this->size, $this->quality, count($this->referenceImages), $rates);
    }

    /**
     * The most a Generation with these parameters can be charged at `$rates`.
     */
    public function maximumPrice(Rates $rates): Money
    {
        return $this->priceRange($rates)->max;
    }
}
