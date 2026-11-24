<?php

namespace App\Enums;

use App\Money\Money;

/**
 * A pack of Credits on the Buy credits page. The server sets each pack's
 * amount; the client only ever names a pack.
 */
enum CreditPack: string
{
    case Five = 'five';
    case Ten = 'ten';
    case TwentyFive = 'twenty_five';

    public function amount(): Money
    {
        return Money::ofNanodollars(match ($this) {
            self::Five => 5_000_000_000,
            self::Ten => 10_000_000_000,
            self::TwentyFive => 25_000_000_000,
        });
    }
}
