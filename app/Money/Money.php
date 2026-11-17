<?php

namespace App\Money;

use InvalidArgumentException;

/**
 * An exact amount of US dollars, held as a signed integer count of
 * nanodollars (1 USD = 1,000,000,000). See ADR-0001.
 */
final readonly class Money
{
    private const int SCALE = 9;

    private function __construct(private int $nanodollars) {}

    public static function ofNanodollars(int $nanodollars): self
    {
        return new self($nanodollars);
    }

    /**
     * Parse a decimal USD string with up to 9 fractional digits, such as
     * Pixelforge's "0.169080000" costs or "12.50" rates.
     */
    public static function fromDecimal(string $decimal): self
    {
        if (preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,9}))?$/', $decimal, $parts) !== 1) {
            throw new InvalidArgumentException("Not a decimal USD amount: \"{$decimal}\".");
        }

        $fraction = str_pad($parts[3] ?? '', self::SCALE, '0');
        $nanodollars = (int) $parts[2] * 10 ** self::SCALE + (int) $fraction;

        return new self($parts[1] === '-' ? -$nanodollars : $nanodollars);
    }

    public function nanodollars(): int
    {
        return $this->nanodollars;
    }

    public function plus(self $other): self
    {
        return new self($this->nanodollars + $other->nanodollars);
    }

    public function minus(self $other): self
    {
        return new self($this->nanodollars - $other->nanodollars);
    }

    /**
     * Multiply by a whole number of units, e.g. a per-token cost by a token count.
     */
    public function times(int $units): self
    {
        return new self($this->nanodollars * $units);
    }

    /**
     * Add the 25% Markup (×5/4). Exact whenever the amount is divisible by 4,
     * which holds for every amount priced at today's Pixelforge rates; any
     * other amount is rounded half up (away from zero) to the nanodollar.
     */
    public function withMarkup(): self
    {
        return new self(self::divideRoundingHalfUp($this->nanodollars * 5, 4));
    }

    public function compareTo(self $other): int
    {
        return $this->nanodollars <=> $other->nanodollars;
    }

    public function equals(self $other): bool
    {
        return $this->nanodollars === $other->nanodollars;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanodollars > $other->nanodollars;
    }

    public function isLessThan(self $other): bool
    {
        return $this->nanodollars < $other->nanodollars;
    }

    /**
     * The exact amount to 9 decimals, e.g. "0.373875000".
     */
    public function formatExact(): string
    {
        return self::format($this->nanodollars, self::SCALE);
    }

    /**
     * The amount rounded half up to 4 decimals for display, e.g. "0.3739".
     */
    public function formatForDisplay(): string
    {
        $decimals = 4;

        return self::format(self::divideRoundingHalfUp($this->nanodollars, 10 ** (self::SCALE - $decimals)), $decimals);
    }

    private static function divideRoundingHalfUp(int $dividend, int $divisor): int
    {
        $quotient = intdiv(abs($dividend), $divisor);

        if (abs($dividend) % $divisor * 2 >= $divisor) {
            $quotient++;
        }

        return $dividend < 0 ? -$quotient : $quotient;
    }

    private static function format(int $amount, int $decimals): string
    {
        $digits = str_pad((string) abs($amount), $decimals + 1, '0', STR_PAD_LEFT);
        $sign = $amount < 0 ? '-' : '';

        return $sign.substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }
}
