<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Pixelforge rejected a request. Branch on `code` (or the helpers below),
 * never on the HTTP status.
 */
final class PixelforgeError extends RuntimeException
{
    private const array RETRYABLE = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

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
     * A limit or overload: the same request may succeed later.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true) || $this->httpStatus >= 500;
    }

    public function isAuthFailure(): bool
    {
        return $this->errorCode === 'invalid_api_key';
    }

    /**
     * Retrying the same request can never succeed, e.g. `content_policy_violation`.
     */
    public function isPermanent(): bool
    {
        return ! $this->isRetryable() && ! $this->isAuthFailure();
    }
}
