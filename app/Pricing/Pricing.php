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
 * a range per quality.
 */
final class Pricing
{
    public const int CHARACTERS_PER_TEXT_TOKEN = 4;

    /** Text token variance, as a percentage either way. */
    public const int TEXT_TOKEN_VARIANCE_PERCENT = 15;

    public const int TOKENS_PER_REFERENCE_IMAGE = 765;

    /**
     * The lowest and highest Price (Markup included) a Generation with these
     * parameters can reach. `$promptLength` counts Unicode characters.
     */
    public static function priceRange(int $promptLength, Size $size, Quality $quality, int $referenceImageCount, Rates $rates): PriceRange
    {
        $estimatedTextTokens = self::ceilDiv($promptLength, self::CHARACTERS_PER_TEXT_TOKEN);
        $reasoning = self::reasoningTokens($quality);

        $fixed = $rates->inputImageToken->times($referenceImageCount * self::TOKENS_PER_REFERENCE_IMAGE)
            ->plus($rates->outputToken->times(self::outputTokens($quality, $size)));

        $minCost = $fixed
            ->plus($rates->inputTextToken->times(intdiv($estimatedTextTokens * (100 - self::TEXT_TOKEN_VARIANCE_PERCENT), 100)))
            ->plus($rates->reasoningToken->times($reasoning['min']));

        $maxCost = $fixed
            ->plus($rates->inputTextToken->times(self::ceilDiv($estimatedTextTokens * (100 + self::TEXT_TOKEN_VARIANCE_PERCENT), 100)))
            ->plus($rates->reasoningToken->times($reasoning['max']));

        return new PriceRange(self::price($minCost), self::price($maxCost));
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
     * The Price of a Provider Cost: the cost plus the 25% Markup.
     */
    public static function price(Money $providerCost): Money
    {
        return $providerCost->withMarkup();
    }

    public static function outputTokens(Quality $quality, Size $size): int
    {
        return match ($quality) {
            Quality::Low => $size === Size::Square ? 280 : 420,
            Quality::Medium => $size === Size::Square ? 1_100 : 1_650,
            Quality::High => $size === Size::Square ? 4_300 : 6_450,
        };
    }

    /**
     * @return array{min: int, max: int}
     */
    public static function reasoningTokens(Quality $quality): array
    {
        return match ($quality) {
            Quality::Low => ['min' => 0, 'max' => 500],
            Quality::Medium => ['min' => 200, 'max' => 2_000],
            Quality::High => ['min' => 1_000, 'max' => 8_000],
        };
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
        $output = [];
        $reasoning = [];

        foreach (Quality::cases() as $quality) {
            foreach (Size::cases() as $size) {
                $output[$quality->value][$size->value] = self::outputTokens($quality, $size);
            }

            $reasoning[$quality->value] = self::reasoningTokens($quality);
        }

        return [
            'characters_per_text_token' => self::CHARACTERS_PER_TEXT_TOKEN,
            'text_token_variance_percent' => self::TEXT_TOKEN_VARIANCE_PERCENT,
            'per_reference_image' => self::TOKENS_PER_REFERENCE_IMAGE,
            'output' => $output,
            'reasoning' => $reasoning,
        ];
    }

    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }
}
