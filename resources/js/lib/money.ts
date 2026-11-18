/**
 * Display-side mirror of App\Money\Money: amounts are integer nanodollars
 * (1 USD = 1,000,000,000), held as bigint so no float ever touches them.
 */

import type { Markup } from '@/types';

const SCALE = 9;

export function divideRoundingHalfUp(
    dividend: bigint,
    divisor: bigint,
): bigint {
    const magnitude = dividend < 0n ? -dividend : dividend;
    let quotient = magnitude / divisor;

    if ((magnitude % divisor) * 2n >= divisor) {
        quotient++;
    }

    return dividend < 0n ? -quotient : quotient;
}

/** Add the Markup, rounded half up to the nanodollar like the server. */
export function withMarkup(
    nanodollars: bigint,
    { numerator, denominator }: Markup,
): bigint {
    return divideRoundingHalfUp(
        nanodollars * BigInt(numerator),
        BigInt(denominator),
    );
}

function format(amount: bigint, decimals: number): string {
    const sign = amount < 0n ? '-' : '';
    const digits = (amount < 0n ? -amount : amount)
        .toString()
        .padStart(decimals + 1, '0');

    return `${sign}${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

/** The exact amount to 9 decimals, e.g. "0.373875000". */
export function formatExact(nanodollars: bigint): string {
    return format(nanodollars, SCALE);
}

/** The amount rounded half up to 4 decimals, e.g. "0.3739". */
export function formatForDisplay(nanodollars: bigint): string {
    const decimals = 4;

    return format(
        divideRoundingHalfUp(nanodollars, 10n ** BigInt(SCALE - decimals)),
        decimals,
    );
}
