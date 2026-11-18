<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\Rates;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

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
