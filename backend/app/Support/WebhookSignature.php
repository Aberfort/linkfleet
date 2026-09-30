<?php

namespace App\Support;

/**
 * `LinkFleet-Signature: t=<unix time>,v1=<hex>` where v1 is
 * HMAC-SHA256(secret, "<t>.<raw body>"). Signing the timestamp along with the
 * body is what lets a receiver reject a captured request replayed later.
 */
final class WebhookSignature
{
    public static function header(string $secret, int $timestamp, string $body): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /** What a receiver does; here so the docs' recipe is exercised by tests. */
    public static function verify(string $header, string $secret, string $body, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $parts['t']) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$parts['t']}.{$body}", $secret), $parts['v1']);
    }
}
