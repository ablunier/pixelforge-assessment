<?php

namespace App\Pixelforge;

/**
 * Pixelforge's answer to one create request, as SubmissionRules reads it:
 * accepted, refused, or none at all (a lost response, or a `202` this app
 * couldn't read or record: Pixelforge may hold the Generation either way).
 */
final readonly class Answer
{
    private function __construct(
        public bool $accepted,
        /** Null when accepted or when there was no answer. */
        public ?Refusal $refusal,
        /** Pixelforge's error code as sent, when refused. */
        public ?string $errorCode,
        /** Why it wasn't accepted, recorded if the Generation fails. */
        public ?string $message,
        /** Seconds to wait before retrying, from the `Retry-After` header. */
        public ?int $retryAfter,
    ) {}

    public static function accepted(): self
    {
        return new self(true, null, null, null, null);
    }

    public static function refused(Refusal $refusal, string $errorCode, string $message, ?int $retryAfter = null): self
    {
        return new self(false, $refusal, $errorCode, $message, $retryAfter);
    }

    public static function none(string $reason): self
    {
        return new self(false, null, null, $reason, null);
    }
}
