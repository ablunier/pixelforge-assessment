<?php

use App\Pixelforge\PixelforgeError;
use App\Pixelforge\Refusal;

it('tells what kind of refusal each Pixelforge answer is', function (string $code, int $status, Refusal $refusal) {
    $error = new PixelforgeError($code, 'Refused by Pixelforge.', $status);

    expect($error->refusal())->toBe($refusal);
})->with([
    'rate limit' => ['rate_limit_exceeded', 429, Refusal::Busy],
    'overloaded' => ['overloaded', 503, Refusal::Busy],
    'concurrency limit' => ['concurrency_limit_exceeded', 429, Refusal::NotYet],
    'bad gateway without a code' => ['http_502', 502, Refusal::NoVerdict],
    'gateway timeout without a code' => ['http_504', 504, Refusal::NoVerdict],
    'undocumented code on a 500' => ['something_new', 500, Refusal::NoVerdict],
    'refused API key' => ['invalid_api_key', 401, Refusal::KeyRefused],
    'content policy' => ['content_policy_violation', 400, Refusal::Permanent],
    'validation' => ['validation_failed', 422, Refusal::Permanent],
    'daily limit' => ['daily_limit_exceeded', 429, Refusal::Permanent],
    'idempotency key reused' => ['idempotency_key_reused', 409, Refusal::Permanent],
    'undocumented 4xx' => ['http_418', 418, Refusal::Permanent],
]);
