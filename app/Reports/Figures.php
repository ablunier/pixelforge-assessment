<?php

namespace App\Reports;

use App\Money\Money;
use JsonSerializable;

/**
 * The admin report's figures for some succeeded Generations: how many, the
 * sum of their Charges, the sum of their Provider Costs, and the Profit.
 */
final readonly class Figures implements JsonSerializable
{
    public function __construct(
        public int $generations,
        public Money $charges,
        public Money $providerCost,
    ) {}

    public static function none(): self
    {
        return new self(0, Money::ofNanodollars(0), Money::ofNanodollars(0));
    }

    /** Charges minus Provider Costs. */
    public function profit(): Money
    {
        return $this->charges->minus($this->providerCost);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->generations + $other->generations,
            $this->charges->plus($other->charges),
            $this->providerCost->plus($other->providerCost),
        );
    }

    /**
     * Amounts in integer nanodollars.
     *
     * @return array{generations: int, charges: int, providerCost: int, profit: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'generations' => $this->generations,
            'charges' => $this->charges->nanodollars(),
            'providerCost' => $this->providerCost->nanodollars(),
            'profit' => $this->profit()->nanodollars(),
        ];
    }
}
