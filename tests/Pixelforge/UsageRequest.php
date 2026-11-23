<?php

namespace Tests\Pixelforge;

/**
 * One usage request InMemoryPixelforgeClient received, its days as `Y-m-d`.
 */
final readonly class UsageRequest
{
    public function __construct(
        public string $startDate,
        public string $endDate,
    ) {}
}
