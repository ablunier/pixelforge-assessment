<?php

namespace App\Pricing;

use App\Money\Money;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Pixelforge's prices per token, as quoted by GET /v1/pricing or locked on a
 * Generation when Pixelforge accepted it.
 */
final readonly class Rates implements JsonSerializable
{
    private const int TOKENS_PER_QUOTE = 1_000_000;

    public function __construct(
        public Money $inputTextToken,
        public Money $inputImageToken,
        public Money $outputToken,
        public Money $reasoningToken,
    ) {}

    /**
     * Build from Pixelforge's `rates` object: USD per 1M tokens as decimal
     * strings, e.g. {"input_text_tokens": "5.00", ...}.
     *
     * @param  array<string, mixed>  $rates
     */
    public static function fromPixelforge(array $rates): self
    {
        return new self(
            self::perToken($rates, 'input_text_tokens'),
            self::perToken($rates, 'input_image_tokens'),
            self::perToken($rates, 'output_tokens'),
            self::perToken($rates, 'reasoning_tokens'),
        );
    }

    /**
     * Rebuild from jsonSerialize()'s integer nanodollars per token.
     *
     * @param  array<string, mixed>  $perToken
     */
    public static function fromNanodollarsPerToken(array $perToken): self
    {
        return new self(
            Money::ofNanodollars((int) $perToken['input_text_tokens']),
            Money::ofNanodollars((int) $perToken['input_image_tokens']),
            Money::ofNanodollars((int) $perToken['output_tokens']),
            Money::ofNanodollars((int) $perToken['reasoning_tokens']),
        );
    }

    /**
     * Each rate as integer nanodollars per token, keyed like Pixelforge's.
     *
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'input_text_tokens' => $this->inputTextToken->nanodollars(),
            'input_image_tokens' => $this->inputImageToken->nanodollars(),
            'output_tokens' => $this->outputToken->nanodollars(),
            'reasoning_tokens' => $this->reasoningToken->nanodollars(),
        ];
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private static function perToken(array $rates, string $key): Money
    {
        $quote = $rates[$key] ?? null;

        if (! is_string($quote)) {
            throw new InvalidArgumentException("Pixelforge rate \"{$key}\" is missing or not a decimal string.");
        }

        // A whole number of nanodollars per token needs a rate with at most 3 decimals.
        $perToken = Money::fromDecimal($quote)->dividedExactlyBy(self::TOKENS_PER_QUOTE);

        if ($perToken === null || $perToken->isLessThan(Money::ofNanodollars(0))) {
            throw new InvalidArgumentException("Pixelforge rate \"{$key}\" ({$quote}) isn't a whole number of nanodollars per token.");
        }

        return $perToken;
    }
}
