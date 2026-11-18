<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * The token counts Pixelforge reports for a succeeded Generation.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /**
     * Build from a Generation's `usage` object.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromPixelforge(array $usage): self
    {
        return new self(
            self::tokens($usage, 'input_text_tokens'),
            self::tokens($usage, 'input_image_tokens'),
            self::tokens($usage, 'output_tokens'),
            self::tokens($usage, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function tokens(array $usage, string $key): int
    {
        $tokens = $usage[$key] ?? null;

        if (! is_int($tokens) || $tokens < 0) {
            throw new InvalidArgumentException("Pixelforge usage \"{$key}\" is missing or not a token count.");
        }

        return $tokens;
    }
}
