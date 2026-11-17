<?php

namespace App\Pricing;

use App\Money\Money;
use JsonSerializable;

/**
 * The lowest and highest Price a Generation can reach. `max` is the Maximum Price.
 */
final readonly class PriceRange implements JsonSerializable
{
    public function __construct(
        public Money $min,
        public Money $max,
    ) {}

    /**
     * @return array{min: int, max: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'min' => $this->min->nanodollars(),
            'max' => $this->max->nanodollars(),
        ];
    }
}
