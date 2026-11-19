<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Pixelforge rejected a request. Branch on refusal(), never on the HTTP
 * status.
 */
final class PixelforgeError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        /** Seconds to wait before retrying, from the `Retry-After` header. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');
        $retryAfter = $response->header('Retry-After');

        return new self(
            is_string($code) ? $code : 'http_'.$response->status(),
            is_string($message) ? $message : "Pixelforge answered HTTP {$response->status()}.",
            $response->status(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * What kind of refusal this is. The error code decides; the HTTP status
     * only tells an undocumented server error from a permanent rejection.
     */
    public function refusal(): Refusal
    {
        return match (true) {
            $this->errorCode === 'invalid_api_key' => Refusal::KeyRefused,
            $this->errorCode === 'concurrency_limit_exceeded' => Refusal::NotYet,
            in_array($this->errorCode, ['rate_limit_exceeded', 'overloaded'], true) => Refusal::Busy,
            $this->httpStatus >= 500 => Refusal::NoVerdict,
            default => Refusal::Permanent,
        };
    }
}
