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

    /**
     * Per quality: the output tokens for each size and the reasoning token range.
     *
     * @var array<string, array{output: array<string, int>, reasoning: array{min: int, max: int}}>
     */
    private const array TOKENS_BY_QUALITY = [
        'low' => [
            'output' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
            'reasoning' => ['min' => 0, 'max' => 500],
        ],
        'medium' => [
            'output' => ['1024x1024' => 1_100, '1536x1024' => 1_650, '1024x1536' => 1_650],
            'reasoning' => ['min' => 200, 'max' => 2_000],
        ],
        'high' => [
            'output' => ['1024x1024' => 4_300, '1536x1024' => 6_450, '1024x1536' => 6_450],
            'reasoning' => ['min' => 1_000, 'max' => 8_000],
        ],
    ];

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
        $tokens = self::TOKENS_BY_QUALITY[$quality->value];

        $fixed = $rates->inputImageToken->times($referenceImageCount * self::TOKENS_PER_REFERENCE_IMAGE)
            ->plus($rates->outputToken->times($tokens['output'][$size->value]));

        $minCost = $fixed
            ->plus($rates->inputTextToken->times(intdiv($estimatedTextTokens * (100 - self::TEXT_TOKEN_VARIANCE_PERCENT), 100)))
            ->plus($rates->reasoningToken->times($tokens['reasoning']['min']));

        $maxCost = $fixed
            ->plus($rates->inputTextToken->times(self::ceilDiv($estimatedTextTokens * (100 + self::TEXT_TOKEN_VARIANCE_PERCENT), 100)))
            ->plus($rates->reasoningToken->times($tokens['reasoning']['max']));

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
     * The Price of a Provider Cost: the cost plus the 25% Markup.
     */
    public static function price(Money $providerCost): Money
    {
        return self::withMarkup($providerCost);
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
            'output' => array_map(fn (array $tokens): array => $tokens['output'], self::TOKENS_BY_QUALITY),
            'reasoning' => array_map(fn (array $tokens): array => $tokens['reasoning'], self::TOKENS_BY_QUALITY),
        ];
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
