<?php

namespace App\Pixelforge;

/**
 * Pixelforge's webhook signing scheme. The `Pixelforge-Signature` header is
 * `t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, keyed with
 * the API key's signing secret. Any one matching `v1` is enough, so a header
 * carrying several (as while a signing secret is rotated) still verifies.
 */
final readonly class WebhookSignature
{
    /** How far the signing time may be from now, either way, in seconds. */
    public const int TOLERANCE_SECONDS = 300;

    public function __construct(private ?string $secret) {}

    /**
     * Whether `$header` is a valid, current signature of the raw `$payload`.
     */
    public function verify(string $header, string $payload, int $now): bool
    {
        // Never verify against an empty key: anyone could sign with it.
        if ($this->secret === null || $this->secret === '') {
            return false;
        }

        $timestamp = '';
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            match ($key) {
                't' => $timestamp = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if (! ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
