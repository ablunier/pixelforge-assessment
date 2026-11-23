<?php

namespace App\Pixelforge;

use App\Money\Money;
use InvalidArgumentException;
use JsonSerializable;

/**
 * What Pixelforge billed our API key, per UTC day and in total, as
 * GET /v1/usage reports it: the source of truth for our Provider Costs.
 */
final readonly class BilledCosts implements JsonSerializable
{
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @param  array<string, Money>  $byDate  keyed by UTC day as `Y-m-d`
     */
    public function __construct(
        private array $byDate,
        public Money $total,
    ) {}

    /**
     * Build from Pixelforge's usage object, whose `cost_usd` values are exact
     * nine-decimal strings.
     *
     * @param  array<mixed>  $usage
     *
     * @throws InvalidArgumentException when it isn't a usable usage object
     */
    public static function fromPixelforge(array $usage): self
    {
        if (! is_array($usage['data'] ?? null) || ! is_array($usage['total'] ?? null)) {
            throw new InvalidArgumentException('A usage object needs `data` and `total`.');
        }

        $byDate = [];
        foreach ($usage['data'] as $day) {
            $date = is_array($day) ? ($day['date'] ?? null) : null;

            if (! is_string($date) || preg_match(self::DATE_PATTERN, $date) !== 1) {
                throw new InvalidArgumentException('Each day of usage needs a `date` as YYYY-MM-DD.');
            }

            $byDate[$date] = self::cost($day);
        }

        return new self($byDate, self::cost($usage['total']));
    }

    /**
     * Rebuild from jsonSerialize()'s integer nanodollars, or null when the
     * value isn't exactly that shape.
     */
    public static function tryFromNanodollars(mixed $billed): ?self
    {
        if (! is_array($billed) || ! is_int($billed['total'] ?? null) || ! is_array($billed['days'] ?? null)) {
            return null;
        }

        $byDate = [];
        foreach ($billed['days'] as $date => $nanodollars) {
            if (! is_string($date) || ! is_int($nanodollars)) {
                return null;
            }

            $byDate[$date] = Money::ofNanodollars($nanodollars);
        }

        return new self($byDate, Money::ofNanodollars($billed['total']));
    }

    /**
     * What Pixelforge billed on UTC day `$date` (`Y-m-d`). A day its report
     * leaves out counts as nothing billed.
     */
    public function on(string $date): Money
    {
        return $this->byDate[$date] ?? Money::zero();
    }

    /**
     * @return array{days: array<string, int>, total: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'days' => array_map(fn (Money $cost): int => $cost->nanodollars(), $this->byDate),
            'total' => $this->total->nanodollars(),
        ];
    }

    /**
     * @param  array<mixed>  $figures
     */
    private static function cost(array $figures): Money
    {
        $cost = $figures['cost_usd'] ?? null;

        if (! is_string($cost)) {
            throw new InvalidArgumentException('Usage needs `cost_usd` as an exact decimal string.');
        }

        return Money::fromDecimal($cost);
    }
}
