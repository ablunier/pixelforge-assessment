<?php

namespace App\Pixelforge;

use App\Pricing\Rates;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;

final readonly class HttpPixelforgeClient implements PixelforgeClient
{
    private const string RATES_CACHE_KEY = 'pixelforge.rates';

    private const int RATES_CACHE_SECONDS = 3600;

    public function __construct(
        private Http $http,
        private Cache $cache,
        private string $baseUrl,
        private ?string $apiKey,
    ) {}

    public function rates(): Rates
    {
        /** @var array<string, mixed> $rates */
        $rates = $this->cache->remember(
            self::RATES_CACHE_KEY,
            self::RATES_CACHE_SECONDS,
            fn (): array => (array) $this->request()->get('pricing')->throw()->json('rates'),
        );

        return Rates::fromPixelforge($rates);
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
