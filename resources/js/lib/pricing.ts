/**
 * Display-only mirror of App\Pricing\Pricing::priceRange(). The server stays
 * the source of truth: it recomputes every Price it records.
 */
import { withMarkup } from '@/lib/money';
import type { PricingData, Quality, Size } from '@/types';

export type BigPriceRange = { min: bigint; max: bigint };

/** Prompt length in Unicode characters (code points), as Pixelforge and the server count it. */
export function promptLength(prompt: string): number {
    // Code points are exactly what's wanted here (PHP's mb_strlen), not graphemes.
    // eslint-disable-next-line typescript/no-misused-spread
    return [...prompt].length;
}

function ceilDiv(dividend: bigint, divisor: bigint): bigint {
    return (dividend + divisor - 1n) / divisor;
}

export function priceRange(
    characters: number,
    size: Size,
    quality: Quality,
    referenceImageCount: number,
    { rates, tokens, markup }: PricingData,
): BigPriceRange {
    const estimatedTextTokens = ceilDiv(
        BigInt(characters),
        BigInt(tokens.characters_per_text_token),
    );
    const variance = BigInt(tokens.text_token_variance_percent);
    const reasoning = tokens.reasoning[quality];

    const fixed =
        BigInt(rates.input_image_tokens) *
            BigInt(referenceImageCount * tokens.per_reference_image) +
        BigInt(rates.output_tokens) * BigInt(tokens.output[quality][size]);

    const minCost =
        fixed +
        BigInt(rates.input_text_tokens) *
            ((estimatedTextTokens * (100n - variance)) / 100n) +
        BigInt(rates.reasoning_tokens) * BigInt(reasoning.min);

    const maxCost =
        fixed +
        BigInt(rates.input_text_tokens) *
            ceilDiv(estimatedTextTokens * (100n + variance), 100n) +
        BigInt(rates.reasoning_tokens) * BigInt(reasoning.max);

    return {
        min: withMarkup(minCost, markup),
        max: withMarkup(maxCost, markup),
    };
}
