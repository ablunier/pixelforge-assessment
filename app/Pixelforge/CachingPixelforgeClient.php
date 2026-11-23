<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Money\Money;
use App\Pricing\Rates;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Wraps any PixelforgeClient to cache its rates for about an hour and, while
 * they can't be quoted, fall back on the last rates it quoted. Everything else
 * passes straight through.
 */
final readonly class CachingPixelforgeClient implements PixelforgeClient
{
    /**
     * Rates are cached as nanodollars per token (Rates::jsonSerialize()), under
     * keys of their own: the HTTP adapter once cached Pixelforge's quotes as sent.
     */
    private const string RATES_CACHE_KEY = 'pixelforge.rates-per-token';

    private const int RATES_CACHE_SECONDS = 3600;

    /** The last rates quoted, kept with no expiry for when they can't be quoted. */
    private const string LAST_KNOWN_RATES_CACHE_KEY = 'pixelforge.rates-per-token.last-known';

    private const int LAST_KNOWN_RATES_RETRY_SECONDS = 60;

    public function __construct(
        private PixelforgeClient $pixelforge,
        private Cache $cache,
    ) {}

    public function rates(): Rates
    {
        $cached = self::cachedRates($this->cache->get(self::RATES_CACHE_KEY));

        if ($cached !== null) {
            return $cached;
        }

        try {
            $rates = $this->pixelforge->rates();
        } catch (PixelforgeUnreachable|PixelforgeError|UnexpectedPixelforgeResponse $failure) {
            return $this->lastKnownRates($failure);
        }

        $this->cache->put(self::RATES_CACHE_KEY, $rates->jsonSerialize(), self::RATES_CACHE_SECONDS);
        $this->cache->forever(self::LAST_KNOWN_RATES_CACHE_KEY, $rates->jsonSerialize());

        return $rates;
    }

    /**
     * While rates can't be quoted, price with the last rates quoted, asking
     * again after a minute rather than on every page.
     */
    private function lastKnownRates(PixelforgeUnreachable|PixelforgeError|UnexpectedPixelforgeResponse $failure): Rates
    {
        $rates = self::cachedRates($this->cache->get(self::LAST_KNOWN_RATES_CACHE_KEY));

        if ($rates === null) {
            throw PricingUnavailable::because($failure);
        }

        report($failure);
        $this->cache->put(self::RATES_CACHE_KEY, $rates->jsonSerialize(), self::LAST_KNOWN_RATES_RETRY_SECONDS);

        return $rates;
    }

    private static function cachedRates(mixed $perToken): ?Rates
    {
        if (! is_array($perToken)) {
            return null;
        }

        return new Rates(
            Money::ofNanodollars((int) $perToken['input_text_tokens']),
            Money::ofNanodollars((int) $perToken['input_image_tokens']),
            Money::ofNanodollars((int) $perToken['output_tokens']),
            Money::ofNanodollars((int) $perToken['reasoning_tokens']),
        );
    }

    public function createGeneration(string $generationId, string $prompt, Size $size, Quality $quality, array $referenceImages): GenerationSnapshot
    {
        return $this->pixelforge->createGeneration($generationId, $prompt, $size, $quality, $referenceImages);
    }

    public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot
    {
        return $this->pixelforge->retrieveGeneration($pixelforgeId);
    }
}
