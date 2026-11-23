<?php

namespace App\Credits;

use App\Money\Money;
use RuntimeException;

/**
 * A user's Available Balance can't cover a Generation's Maximum Price, so it
 * can't be held and the Generation isn't opened.
 */
final class InsufficientAvailableBalance extends RuntimeException
{
    public function __construct(
        public readonly Money $availableBalance,
        public readonly Money $maximumPrice,
    ) {
        parent::__construct("An Available Balance of {$availableBalance->formatExact()} can't cover a Maximum Price of {$maximumPrice->formatExact()}.");
    }
}
