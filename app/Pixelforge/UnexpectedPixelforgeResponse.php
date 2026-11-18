<?php

namespace App\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * Pixelforge answered with a success status, but the body isn't a Generation
 * this app can use (an unknown status, unparseable rates, usage or dates).
 * The request may still have been carried out, e.g. a create it will bill.
 */
final class UnexpectedPixelforgeResponse extends RuntimeException
{
    public static function because(Throwable $reason): self
    {
        return new self("Pixelforge sent a response this app can't read: {$reason->getMessage()}", previous: $reason);
    }
}
