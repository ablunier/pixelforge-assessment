<?php

use App\Pixelforge\Answer;
use App\Pixelforge\AwaitResend;
use App\Pixelforge\Decision;
use App\Pixelforge\Done;
use App\Pixelforge\Fail;
use App\Pixelforge\GenerationErrorCode;
use App\Pixelforge\Refusal;
use App\Pixelforge\RetryIn;
use App\Pixelforge\SubmissionRules;
use Carbon\CarbonImmutable;

function busy(?int $retryAfter = null): Answer
{
    return Answer::refused(Refusal::Busy, 'overloaded', 'Pixelforge is temporarily overloaded.', $retryAfter);
}

function notYet(?int $retryAfter = null): Answer
{
    return Answer::refused(Refusal::NotYet, 'concurrency_limit_exceeded', 'Too many generations in progress.', $retryAfter);
}

function noVerdict(): Answer
{
    return Answer::refused(Refusal::NoVerdict, 'http_504', 'Pixelforge answered HTTP 504.');
}

function keyRefused(): Answer
{
    return Answer::refused(Refusal::KeyRefused, 'invalid_api_key', 'Missing or invalid API key.');
}

function lost(): Answer
{
    return Answer::none('cURL error 28: Operation timed out');
}

/**
 * Decide what the next create request's answer means for a Generation that
 * has had `$attemptsSoFar` attempts, `$minutesSinceGenerate` after Generate.
 */
function decide(Answer $answer, int $attemptsSoFar, bool $outcomeUnknown = false, int $minutesSinceGenerate = 1): Decision
{
    $now = CarbonImmutable::parse('2026-11-04T14:30:00Z');

    return SubmissionRules::decide($answer, $attemptsSoFar, $outcomeUnknown, $now->subMinutes($minutesSinceGenerate), $now);
}

it('decides what each answer to a create request means (ADR-0005)', function (Closure $decision, Decision $expected) {
    expect($decision())->toEqual($expected);
})->with([
    'accepted counts the attempt and is done' => [
        fn () => decide(Answer::accepted(), attemptsSoFar: 2),
        new Decision(3, false, new Done),
    ],
    'accepted after a lost response keeps the unknown outcome' => [
        fn () => decide(Answer::accepted(), attemptsSoFar: 1, outcomeUnknown: true),
        new Decision(2, true, new Done),
    ],

    'busy at attempt 1 retries after 2 s' => [
        fn () => decide(busy(), attemptsSoFar: 0),
        new Decision(1, false, new RetryIn(2)),
    ],
    'busy at attempt 4 retries after 16 s' => [
        fn () => decide(busy(), attemptsSoFar: 3),
        new Decision(4, false, new RetryIn(16)),
    ],
    'busy waits Retry-After when given' => [
        fn () => decide(busy(retryAfter: 12), attemptsSoFar: 0),
        new Decision(1, false, new RetryIn(12)),
    ],
    'Retry-After 300 is capped at a minute' => [
        fn () => decide(busy(retryAfter: 300), attemptsSoFar: 0),
        new Decision(1, false, new RetryIn(60)),
    ],
    'busy at attempt 5 with no unknown outcome fails as retries exhausted' => [
        fn () => decide(busy(), attemptsSoFar: 4),
        new Decision(5, false, new Fail(GenerationErrorCode::RetriesExhausted, 'Pixelforge is temporarily overloaded.')),
    ],
    'busy at attempt 5 after a lost response awaits a resend' => [
        fn () => decide(busy(), attemptsSoFar: 4, outcomeUnknown: true),
        new Decision(5, true, new AwaitResend),
    ],
    'busy at attempt 20 after a lost response fails as unreachable' => [
        fn () => decide(busy(), attemptsSoFar: 19, outcomeUnknown: true),
        new Decision(20, true, new Fail(GenerationErrorCode::ProviderUnreachable, 'Pixelforge is temporarily overloaded.')),
    ],

    'no verdict at attempt 1 retries and marks the outcome unknown' => [
        fn () => decide(noVerdict(), attemptsSoFar: 0),
        new Decision(1, true, new RetryIn(2)),
    ],
    'no verdict at attempt 5 awaits a resend' => [
        fn () => decide(noVerdict(), attemptsSoFar: 4),
        new Decision(5, true, new AwaitResend),
    ],
    'no verdict at attempt 20 fails as unreachable' => [
        fn () => decide(noVerdict(), attemptsSoFar: 19, outcomeUnknown: true),
        new Decision(20, true, new Fail(GenerationErrorCode::ProviderUnreachable, 'Pixelforge answered HTTP 504.')),
    ],

    'no answer at attempt 3 retries after 8 s and marks the outcome unknown' => [
        fn () => decide(lost(), attemptsSoFar: 2),
        new Decision(3, true, new RetryIn(8)),
    ],
    'no answer at attempt 5 awaits a resend' => [
        fn () => decide(lost(), attemptsSoFar: 4),
        new Decision(5, true, new AwaitResend),
    ],
    'no answer at attempt 20 fails as unreachable' => [
        fn () => decide(lost(), attemptsSoFar: 19, outcomeUnknown: true),
        new Decision(20, true, new Fail(GenerationErrorCode::ProviderUnreachable, 'cURL error 28: Operation timed out')),
    ],

    'not yet at 14 minutes is not counted and retries after 2 s' => [
        fn () => decide(notYet(), attemptsSoFar: 3, minutesSinceGenerate: 14),
        new Decision(3, false, new RetryIn(2)),
    ],
    'not yet waits Retry-After when given' => [
        fn () => decide(notYet(retryAfter: 5), attemptsSoFar: 1, minutesSinceGenerate: 14),
        new Decision(1, false, new RetryIn(5)),
    ],
    'not yet at 16 minutes counts like busy' => [
        fn () => decide(notYet(), attemptsSoFar: 3, minutesSinceGenerate: 16),
        new Decision(4, false, new RetryIn(16)),
    ],
    'not yet at 16 minutes and attempt 5 fails as retries exhausted' => [
        fn () => decide(notYet(), attemptsSoFar: 4, minutesSinceGenerate: 16),
        new Decision(5, false, new Fail(GenerationErrorCode::RetriesExhausted, 'Too many generations in progress.')),
    ],

    'refused key at attempt 1 awaits a resend' => [
        fn () => decide(keyRefused(), attemptsSoFar: 0),
        new Decision(1, false, new AwaitResend),
    ],
    'refused key at attempt 19 still awaits a resend' => [
        fn () => decide(keyRefused(), attemptsSoFar: 18),
        new Decision(19, false, new AwaitResend),
    ],
    'refused key at attempt 20 fails with invalid_api_key' => [
        fn () => decide(keyRefused(), attemptsSoFar: 19),
        new Decision(20, false, new Fail('invalid_api_key', 'Missing or invalid API key.')),
    ],
    'refused key after a lost response keeps the unknown outcome' => [
        fn () => decide(keyRefused(), attemptsSoFar: 2, outcomeUnknown: true),
        new Decision(3, true, new AwaitResend),
    ],

    'permanent fails at once with Pixelforge\'s code' => [
        fn () => decide(Answer::refused(Refusal::Permanent, 'content_policy_violation', 'Blocked by content policy.'), attemptsSoFar: 0),
        new Decision(1, false, new Fail('content_policy_violation', 'Blocked by content policy.')),
    ],
    'permanent after a lost response keeps the unknown outcome' => [
        fn () => decide(Answer::refused(Refusal::Permanent, 'validation_failed', 'Invalid size.'), attemptsSoFar: 1, outcomeUnknown: true),
        new Decision(2, true, new Fail('validation_failed', 'Invalid size.')),
    ],
]);
