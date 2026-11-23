<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\Rates;

/**
 * The app's boundary with Pixelforge: its HTTP API and its image host. Pixelforge payloads and
 * error codes stay behind this interface.
 */
interface PixelforgeClient
{
    /** The longest an image download may take before it gives up. */
    public const int IMAGE_DOWNLOAD_TIMEOUT_SECONDS = 30;

    /**
     * The current rates from GET /v1/pricing.
     *
     * @throws PixelforgeError when Pixelforge rejects the request
     * @throws PixelforgeUnreachable when Pixelforge can't be reached
     * @throws UnexpectedPixelforgeResponse when a success response isn't usable rates
     * @throws PricingUnavailable when no rates can be had, fresh or earlier ones
     */
    public function rates(): Rates;

    /**
     * POST /v1/generations. The local Generation id is sent as the
     * Idempotency-Key and as `metadata.generation_id`, so repeating the call
     * returns the same Generation rather than creating (and billing) another.
     *
     * @param  list<string>  $referenceImages
     *
     * @throws PixelforgeError when Pixelforge rejects the request
     * @throws PixelforgeUnreachable when Pixelforge can't be reached
     * @throws UnexpectedPixelforgeResponse when a success response isn't a usable Generation
     */
    public function createGeneration(string $generationId, string $prompt, Size $size, Quality $quality, array $referenceImages): GenerationSnapshot;

    /**
     * GET /v1/generations/{id}.
     *
     * @throws PixelforgeError when Pixelforge rejects the request
     * @throws PixelforgeUnreachable when Pixelforge can't be reached
     * @throws UnexpectedPixelforgeResponse when a success response isn't a usable Generation
     */
    public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot;

    /**
     * The bytes behind a Generation's signed image URL, as Pixelforge's image
     * host serves them. Taking at most IMAGE_DOWNLOAD_TIMEOUT_SECONDS.
     *
     * @throws PixelforgeError when the image host answers with an error status
     * @throws PixelforgeUnreachable when the image host can't be reached
     */
    public function downloadImage(string $url): string;
}
