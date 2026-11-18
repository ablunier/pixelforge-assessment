<?php

namespace App\Pixelforge;

/**
 * End the Generation as `failed`, with no Price. `$errorCode` is
 * Pixelforge's code as sent, or a GenerationErrorCode of our own.
 */
final readonly class Fail
{
    public function __construct(
        public GenerationErrorCode|string $errorCode,
        public string $errorMessage,
    ) {}
}
