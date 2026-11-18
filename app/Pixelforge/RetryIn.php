<?php

namespace App\Pixelforge;

/** Send the create request again after this many seconds. */
final readonly class RetryIn
{
    public function __construct(public int $seconds) {}
}
