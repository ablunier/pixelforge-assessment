<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\Rates;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

final readonly class HttpPixelforgeClient implements PixelforgeClient
{
    private const string RATES_CACHE_KEY = 'pixelforge.rates';

    private const int RATES_CACHE_SECONDS = 3600;

    /** The last rates GET /v1/pricing sent, kept with no expiry for when it fails. */
    private const string LAST_KNOWN_RATES_CACHE_KEY = 'pixelforge.rates.last-known';

    private const int LAST_KNOWN_RATES_RETRY_SECONDS = 60;

    public function __construct(
        private Http $http,
        private Cache $cache,
        private string $baseUrl,
        private ?string $apiKey,
    ) {}

    public function rates(): Rates
    {
        $quoted = $this->cache->get(self::RATES_CACHE_KEY);

        if (is_array($quoted)) {
            return Rates::fromPixelforge($quoted);
        }

        try {
            $quoted = (array) $this->request()->get('pricing')->throw()->json('rates');
            $rates = Rates::fromPixelforge($quoted); // Only rates this app can read are cached.
        } catch (RequestException|ConnectionException|InvalidArgumentException $exception) {
            return $this->lastKnownRates($exception);
        }

        $this->cache->put(self::RATES_CACHE_KEY, $quoted, self::RATES_CACHE_SECONDS);
        $this->cache->forever(self::LAST_KNOWN_RATES_CACHE_KEY, $quoted);

        return $rates;
    }

    /**
     * While GET /v1/pricing fails, price with the last rates it sent, asking
     * again after a minute rather than on every page.
     */
    private function lastKnownRates(Throwable $failure): Rates
    {
        $quoted = $this->cache->get(self::LAST_KNOWN_RATES_CACHE_KEY);

        if (! is_array($quoted)) {
            throw PricingUnavailable::because($failure);
        }

        report($failure);
        $this->cache->put(self::RATES_CACHE_KEY, $quoted, self::LAST_KNOWN_RATES_RETRY_SECONDS);

        return Rates::fromPixelforge($quoted);
    }

    public function createGeneration(string $generationId, string $prompt, Size $size, Quality $quality, array $referenceImages): GenerationSnapshot
    {
        // Pixelforge rejects unknown params, so send exactly these.
        $params = array_filter([
            'prompt' => $prompt,
            'size' => $size->value,
            'quality' => $quality->value,
            'reference_images' => $referenceImages,
            'metadata' => ['generation_id' => $generationId],
        ], fn (mixed $value): bool => $value !== []);

        return $this->generation(
            $this->request()->withHeader('Idempotency-Key', $generationId)->post('generations', $params),
        );
    }

    public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot
    {
        return $this->generation($this->request()->get('generations/'.rawurlencode($pixelforgeId)));
    }

    private function generation(Response $response): GenerationSnapshot
    {
        if ($response->failed()) {
            throw PixelforgeError::fromResponse($response);
        }

        try {
            return GenerationSnapshot::fromPixelforge((array) $response->json());
        } catch (InvalidArgumentException $exception) {
            throw UnexpectedPixelforgeResponse::because($exception);
        }
    }

    private function request(): PendingRequest
    {
        $request = $this->http->baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);

        return $this->apiKey === null ? $request : $request->withToken($this->apiKey);
    }
}
