<?php

namespace App\Pixelforge;

use App\Pricing\Rates;

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
}
