<?php

namespace App\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * Pixelforge couldn't be reached, or its response was lost: no answer came
 * back. A request may still have been carried out, e.g. a create it will bill.
 */
final class PixelforgeUnreachable extends RuntimeException
{
    public static function because(Throwable $reason): self
    {
        return new self("Pixelforge can't be reached: {$reason->getMessage()}", previous: $reason);
    }
}
