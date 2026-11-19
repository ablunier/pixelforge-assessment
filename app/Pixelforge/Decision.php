<?php

namespace App\Pixelforge;

/**
 * What one create request's answer means for its Generation: the attempts
 * it has had, whether any of them left the outcome unknown, and what
 * happens next.
 */
final readonly class Decision
{
    public function __construct(
        public int $attempts,
        public bool $outcomeUnknown,
        public Done|RetryIn|AwaitResend|Fail $next,
    ) {}
}
