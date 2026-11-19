<?php

namespace App\Services\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix ts>,v1=<hex hmac>", where the HMAC-SHA256
 * is computed over "<t>.<raw body>" with the webhook signing secret (whsec_...).
 */
final class WebhookSignature
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $rawBody, ?string $header, ?int $now = null): bool
    {
        if (blank($this->secret) || blank($header)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        // Replay protection: each delivery attempt is signed with a fresh timestamp.
        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $this->secret);

        return hash_equals($expected, $signature); // constant-time comparison
    }

    /** Used by tests (and handy for local debugging) to sign a body like Pixelforge does. */
    public static function sign(string $rawBody, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
