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
        return Money::fromDecimal(match ($this) {
            self::Five => '5',
            self::Ten => '10',
            self::TwentyFive => '25',
        });
    }
}
