<?php

namespace App\Pixelforge;

/**
 * Why a Generation failed: the Pixelforge error codes it can end with, plus
 * `retries_exhausted`, which the submission records when Pixelforge never
 * gave it a definite answer. Pixelforge's codes stay inside this boundary:
 * the rest of the app stores the code and shows reasonFor().
 */
enum GenerationErrorCode: string
{
    // Rejected at submission: nothing was created at Pixelforge.
    case ContentPolicyViolation = 'content_policy_violation';
    case ValidationFailed = 'validation_failed';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case IdempotencyKeyReused = 'idempotency_key_reused';

    // Our API key was refused for every attempt: nothing was created at Pixelforge.
    case InvalidApiKey = 'invalid_api_key';

    // Failed after Pixelforge accepted it.
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    // Local: Pixelforge stayed busy for every attempt.
    case RetriesExhausted = 'retries_exhausted';

    // Local: no create request got a definite answer from Pixelforge (ADR-0005).
    case ProviderUnreachable = 'provider_unreachable';

    /**
     * What the user reads in the history, for any recorded error code,
     * including ones Pixelforge adds later. `$submissionAttempts` is how many
     * create requests the Generation got.
     */
    public static function reasonFor(string $code, int $submissionAttempts): string
    {
        return self::tryFrom($code)?->reason($submissionAttempts) ?? "Pixelforge couldn't complete this Generation";
    }

    public function reason(int $submissionAttempts): string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Blocked by content policy',
            self::ValidationFailed => 'Pixelforge rejected the request as invalid',
            self::DailyLimitExceeded => 'Daily provider limit reached',
            self::IdempotencyKeyReused => 'Pixelforge rejected a conflicting resubmission',
            self::InvalidApiKey => "PixelForge couldn't sign in to its image provider; try again later",
            self::GenerationFailed => "The model couldn't produce an image",
            self::InternalError => 'Pixelforge had an internal error; trying again usually works',
            self::RetriesExhausted => "Provider busy, gave up after {$submissionAttempts} attempts",
            self::ProviderUnreachable => "Couldn't reach Pixelforge, gave up after {$submissionAttempts} attempts",
        };
    }
}
