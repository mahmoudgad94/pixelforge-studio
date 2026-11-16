<?php

namespace App\Pixelforge;

/**
 * Pixelforge-Signature: t=<unix>,v1=<hex hmac-sha256("<t>.<raw body>", secret)>
 */
final class WebhookSignature
{
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && ctype_digit((string) $value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false; // replay protection
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
