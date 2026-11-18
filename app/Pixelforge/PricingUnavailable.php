<?php

namespace App\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * Pixelforge's rates can't be had: GET /v1/pricing failed or sent rates this
 * app can't read, and no earlier rates are known to fall back on.
 */
final class PricingUnavailable extends RuntimeException
{
    public static function because(Throwable $reason): self
    {
        return new self("Pixelforge's rates are unavailable: {$reason->getMessage()}", previous: $reason);
    }
}
