<?php

namespace App\Pricing;

use App\Enums\Quality;
use App\Enums\Size;
use App\Money\Money;

/**
 * The only place pricing rules live. Pure: token rules and rates in, Money out.
 *
 * Token rules follow Pixelforge's docs: input text is ceil(characters / 4)
 * tokens, give or take 15%; each reference image is 765 input image tokens;
 * output tokens are fixed per quality and size; reasoning tokens fall within
 * a range per quality. The Markup is 25% (×5/4).
 */
final class Pricing
{
    public const int CHARACTERS_PER_TEXT_TOKEN = 4;

    /** Text token variance, as a percentage either way. */
    public const int TEXT_TOKEN_VARIANCE_PERCENT = 15;

    public const int TOKENS_PER_REFERENCE_IMAGE = 765;

    /** The Markup: Price = Provider Cost × 5/4. */
    private const int MARKUP_NUMERATOR = 5;

    private const int MARKUP_DENOMINATOR = 4;

    /**
     * The lowest and highest Price (Markup included) a Generation with these
     * parameters can reach. `$promptLength` counts Unicode characters.
     */
    public static function priceRange(int $promptLength, Size $size, Quality $quality, int $referenceImageCount, Rates $rates): PriceRange
    {
        $estimatedTextTokens = self::ceilDiv($promptLength, self::CHARACTERS_PER_TEXT_TOKEN);
        $reasoningTokens = self::reasoningTokens($quality);

        $fixed = $rates->inputImageToken->times($referenceImageCount * self::TOKENS_PER_REFERENCE_IMAGE)
            ->plus($rates->outputToken->times(self::outputTokens($quality, $size)));

        $minCost = $fixed
            ->plus($rates->inputTextToken->times(intdiv($estimatedTextTokens * (100 - self::TEXT_TOKEN_VARIANCE_PERCENT), 100)))
            ->plus($rates->reasoningToken->times($reasoningTokens['min']));

        $maxCost = $fixed
            ->plus($rates->inputTextToken->times(self::ceilDiv($estimatedTextTokens * (100 + self::TEXT_TOKEN_VARIANCE_PERCENT), 100)))
            ->plus($rates->reasoningToken->times($reasoningTokens['max']));

        return new PriceRange(self::withMarkup($minCost), self::withMarkup($maxCost));
    }

    /**
     * The Price Range the generate form starts at: an empty prompt at the
     * default size and quality, with no reference images.
     */
    public static function startingPriceRange(Rates $rates): PriceRange
    {
        return self::priceRange(0, Size::DEFAULT, Quality::DEFAULT, 0, $rates);
    }

    /**
     * What Pixelforge bills for a succeeded Generation's usage at its locked rates.
     */
    public static function providerCost(Usage $usage, Rates $lockedRates): Money
    {
        return $lockedRates->inputTextToken->times($usage->inputTextTokens)
            ->plus($lockedRates->inputImageToken->times($usage->inputImageTokens))
            ->plus($lockedRates->outputToken->times($usage->outputTokens))
            ->plus($lockedRates->reasoningToken->times($usage->reasoningTokens));
    }

    /**
     * The Price of a succeeded Generation: its Provider Cost plus the 25%
     * Markup, capped at the Maximum Price it was shown (ADR-0004). The cap
     * only bites when Pixelforge locked higher rates than the estimate used;
     * the business absorbs the difference.
     */
    public static function price(Money $providerCost, Money $maximumPrice): Money
    {
        $price = self::withMarkup($providerCost);

        return $price->isGreaterThan($maximumPrice) ? $maximumPrice : $price;
    }

    /**
     * The token rules, for the display-only TypeScript mirror of priceRange().
     *
     * @return array{
     *     characters_per_text_token: int,
     *     text_token_variance_percent: int,
     *     per_reference_image: int,
     *     output: array<string, array<string, int>>,
     *     reasoning: array<string, array{min: int, max: int}>,
     * }
     */
    public static function tokenRules(): array
    {
        return [
            'characters_per_text_token' => self::CHARACTERS_PER_TEXT_TOKEN,
            'text_token_variance_percent' => self::TEXT_TOKEN_VARIANCE_PERCENT,
            'per_reference_image' => self::TOKENS_PER_REFERENCE_IMAGE,
            'output' => self::byQuality(fn (Quality $quality): array => self::bySize(
                fn (Size $size): int => self::outputTokens($quality, $size),
            )),
            'reasoning' => self::byQuality(fn (Quality $quality): array => self::reasoningTokens($quality)),
        ];
    }

    /**
     * The Markup as a fraction of the Provider Cost, for the display-only
     * TypeScript mirror of priceRange().
     *
     * @return array{numerator: int, denominator: int}
     */
    public static function markup(): array
    {
        return ['numerator' => self::MARKUP_NUMERATOR, 'denominator' => self::MARKUP_DENOMINATOR];
    }

    /**
     * Output tokens are fixed per quality and size; landscape and portrait cost the same.
     */
    private static function outputTokens(Quality $quality, Size $size): int
    {
        $square = $size === Size::Square;

        return match ($quality) {
            Quality::Low => $square ? 280 : 420,
            Quality::Medium => $square ? 1_100 : 1_650,
            Quality::High => $square ? 4_300 : 6_450,
        };
    }

    /**
     * The range reasoning tokens fall within, per quality.
     *
     * @return array{min: int, max: int}
     */
    private static function reasoningTokens(Quality $quality): array
    {
        return match ($quality) {
            Quality::Low => ['min' => 0, 'max' => 500],
            Quality::Medium => ['min' => 200, 'max' => 2_000],
            Quality::High => ['min' => 1_000, 'max' => 8_000],
        };
    }

    /**
     * @template T
     *
     * @param  callable(Quality): T  $value
     * @return array<string, T>
     */
    private static function byQuality(callable $value): array
    {
        return array_combine(array_column(Quality::cases(), 'value'), array_map($value, Quality::cases()));
    }

    /**
     * @template T
     *
     * @param  callable(Size): T  $value
     * @return array<string, T>
     */
    private static function bySize(callable $value): array
    {
        return array_combine(array_column(Size::cases(), 'value'), array_map($value, Size::cases()));
    }

    /**
     * Add the 25% Markup. Exact whenever the cost is divisible by 4, which
     * holds for every cost at today's Pixelforge rates; otherwise rounded half
     * up to the nanodollar (ADR-0001).
     */
    private static function withMarkup(Money $cost): Money
    {
        return $cost->timesFraction(self::MARKUP_NUMERATOR, self::MARKUP_DENOMINATOR);
    }

    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }
}
