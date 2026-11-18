<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\Rates;
use Illuminate\Http\Client\ConnectionException;

/**
 * The app's boundary with the Pixelforge HTTP API. Pixelforge payloads and
 * error codes stay behind this interface.
 */
interface PixelforgeClient
{
    /**
     * The current rates from GET /v1/pricing, cached for about an hour.
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
     * @throws ConnectionException when Pixelforge can't be reached
     * @throws UnexpectedPixelforgeResponse when a success response isn't a usable Generation
     */
    public function createGeneration(string $generationId, string $prompt, Size $size, Quality $quality, array $referenceImages): GenerationSnapshot;

    /**
     * GET /v1/generations/{id}.
     *
     * @throws PixelforgeError when Pixelforge rejects the request
     * @throws ConnectionException when Pixelforge can't be reached
     * @throws UnexpectedPixelforgeResponse when a success response isn't a usable Generation
     */
    public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot;
}
