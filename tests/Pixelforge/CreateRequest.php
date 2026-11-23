<?php

namespace Tests\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;

/**
 * One create request InMemoryPixelforgeClient received.
 */
final readonly class CreateRequest
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        /** The local Generation id, sent as the Idempotency-Key. */
        public string $generationId,
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public array $referenceImages,
    ) {}
}
