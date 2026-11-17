<?php

namespace App\Enums;

use App\Jobs\SubmitGenerationToPixelforge;

/**
 * Why a Generation failed: Pixelforge's error codes, plus `retries_exhausted`,
 * which is ours.
 */
enum GenerationErrorCode: string
{
    // Rejected at submission: nothing was created at Pixelforge.
    case ContentPolicyViolation = 'content_policy_violation';
    case ValidationFailed = 'validation_failed';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case IdempotencyKeyReused = 'idempotency_key_reused';

    // Failed after Pixelforge accepted it.
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    // Local: Pixelforge stayed busy for every attempt.
    case RetriesExhausted = 'retries_exhausted';

    /**
     * What the user reads in the history, for any recorded error code,
     * including ones Pixelforge adds later.
     */
    public static function reasonFor(string $code): string
    {
        return self::tryFrom($code)?->reason() ?? "Pixelforge couldn't complete this Generation";
    }

    public function reason(): string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Blocked by content policy',
            self::ValidationFailed => 'Pixelforge rejected the request as invalid',
            self::DailyLimitExceeded => 'Daily provider limit reached',
            self::IdempotencyKeyReused => 'Pixelforge rejected a conflicting resubmission',
            self::GenerationFailed => "The model couldn't produce an image",
            self::InternalError => 'Pixelforge had an internal error; trying again usually works',
            self::RetriesExhausted => 'Provider busy, gave up after '.SubmitGenerationToPixelforge::MAX_ATTEMPTS.' attempts',
        };
    }
}
