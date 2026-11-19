<?php

namespace App\Pixelforge;

use Carbon\CarbonInterface;

/**
 * What each answer to a create request means (ADR-0005), decided from the
 * answer and the Generation's attempts so far, with no database, queue or HTTP.
 *
 * Rate limits, overloads, server errors and missing answers are retried
 * after `Retry-After` (or an exponential backoff), capped at a minute, for up
 * to MAX_ATTEMPTS. Concurrency-limit refusals are free for a while after
 * Generate. Permanent rejections and exhausted retries fail the Generation.
 * One Pixelforge may already hold (no answer, or an undocumented 5xx) or
 * can't take yet (our API key refused) awaits reconciliation's resends
 * instead, up to MAX_ATTEMPTS_AWAITING_PIXELFORGE.
 */
final class SubmissionRules
{
    /** Create requests a Generation gets before it fails with `retries_exhausted`. */
    public const int MAX_ATTEMPTS = 5;

    /**
     * Create requests a Generation gets while Pixelforge may already hold it or
     * can't take it yet (lost responses, refused API key), resent by
     * reconciliation every couple of minutes, before it fails for good.
     */
    public const int MAX_ATTEMPTS_AWAITING_PIXELFORGE = 20;

    /**
     * The longest wait before a retry, `Retry-After` included: below
     * Generation::PENDING_STALE_AFTER_SECONDS. A retry that comes too early
     * is only refused again.
     */
    public const int MAX_RETRY_DELAY_SECONDS = 60;

    /**
     * How long after Generate concurrency-limit refusals don't count as
     * attempts. Pixelforge runs at most 3 Generations per account at once and
     * one can take 90 s or more, so those refusals mean "not yet" (story 13);
     * past this they count, so nothing waits forever.
     */
    private const int MAX_CONCURRENCY_WAIT_MINUTES = 15;

    /**
     * @param  int  $attempts  Create requests the Generation had before this one.
     * @param  bool  $outcomeUnknown  Whether any of them got no definite answer.
     */
    public static function decide(Answer $answer, int $attempts, bool $outcomeUnknown, ?CarbonInterface $createdAt, CarbonInterface $now): Decision
    {
        if ($answer->accepted) {
            return new Decision($attempts + 1, $outcomeUnknown, new Done);
        }

        if ($answer->refusal === Refusal::NotYet && self::waitingItsTurn($createdAt, $now)) {
            return new Decision($attempts, $outcomeUnknown, self::retryIn($answer->retryAfter ?? self::backoffSeconds(1)));
        }

        $attempts++;
        $message = (string) $answer->message;

        if ($answer->refusal === Refusal::Permanent) {
            return new Decision($attempts, $outcomeUnknown, new Fail((string) $answer->errorCode, $message));
        }

        // Our own misconfiguration: reconciliation resends it once the key is fixed.
        if ($answer->refusal === Refusal::KeyRefused) {
            return new Decision($attempts, $outcomeUnknown, $attempts >= self::MAX_ATTEMPTS_AWAITING_PIXELFORGE
                ? new Fail((string) $answer->errorCode, $message)
                : new AwaitResend);
        }

        // Only ever set: once Pixelforge may hold the Generation, it may hold it for good.
        $outcomeUnknown = $outcomeUnknown || $answer->refusal === null || $answer->refusal === Refusal::NoVerdict;

        return new Decision($attempts, $outcomeUnknown, match (true) {
            $attempts < self::MAX_ATTEMPTS => self::retryIn($answer->retryAfter ?? self::backoffSeconds($attempts)),
            ! $outcomeUnknown => new Fail(GenerationErrorCode::RetriesExhausted, $message),
            $attempts >= self::MAX_ATTEMPTS_AWAITING_PIXELFORGE => new Fail(GenerationErrorCode::ProviderUnreachable, $message),
            default => new AwaitResend,
        });
    }

    private static function waitingItsTurn(?CarbonInterface $createdAt, CarbonInterface $now): bool
    {
        return $createdAt !== null && $createdAt->toImmutable()->addMinutes(self::MAX_CONCURRENCY_WAIT_MINUTES)->greaterThan($now);
    }

    private static function retryIn(int $seconds): RetryIn
    {
        return new RetryIn(min($seconds, self::MAX_RETRY_DELAY_SECONDS));
    }

    /**
     * Seconds to wait after the given attempt when Pixelforge sends no
     * Retry-After: 2, 4, 8, 16…
     */
    private static function backoffSeconds(int $attempt): int
    {
        return min(2 ** $attempt, self::MAX_RETRY_DELAY_SECONDS);
    }
}
