<?php

namespace App\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\Rates;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

final readonly class HttpPixelforgeClient implements PixelforgeClient
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private ?string $apiKey,
    ) {}

    public function rates(): Rates
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('pricing'));

        if ($response->failed()) {
            throw PixelforgeError::fromResponse($response);
        }

        try {
            return Rates::fromPixelforge((array) $response->json('rates'));
        } catch (InvalidArgumentException $exception) {
            throw UnexpectedPixelforgeResponse::because($exception);
        }
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

        return $this->generation($this->send(
            fn (PendingRequest $request): Response => $request->withHeader('Idempotency-Key', $generationId)->post('generations', $params),
        ));
    }

    public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot
    {
        return $this->generation($this->send(
            fn (PendingRequest $request): Response => $request->get('generations/'.rawurlencode($pixelforgeId)),
        ));
    }

    public function downloadImage(string $url): string
    {
        // The signed URL is its own credential: the API key stays with the API.
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get($url),
            $this->http->timeout(self::IMAGE_DOWNLOAD_TIMEOUT_SECONDS),
        );

        if ($response->failed()) {
            throw PixelforgeError::fromResponse($response);
        }

        return $response->body();
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

    /**
     * Makes `$call` with `$request`, by default one to the API.
     *
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws PixelforgeUnreachable when no response came back
     */
    private function send(Closure $call, ?PendingRequest $request = null): Response
    {
        try {
            return $call($request ?? $this->request());
        } catch (ConnectionException $exception) {
            throw PixelforgeUnreachable::because($exception);
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
