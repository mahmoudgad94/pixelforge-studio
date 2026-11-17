<?php

namespace App\Pixelforge;

/**
 * What we are allowed to keep of a request or response: secrets replaced with "[redacted]" and
 * each body capped. Everything stored in pixelforge_activity goes through here first.
 */
final class ActivityPayload
{
    public const REDACTED = '[redacted]';

    /** Largest body kept, in bytes. */
    public const MAX_BODY_BYTES = 65536;

    /** Header or JSON key names that hold a secret: Authorization, X-Api-Key, the webhook signature, cookies, tokens. */
    private const SECRET_KEY = '/(authorization|api[-_]?key|secret|signature|token|password|cookie)/i';

    /**
     * @param  array<string, string|array<int, string>>  $headers
     * @return array<string, string> name => value, secrets redacted
     */
    public static function headers(array $headers, array $secrets = []): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $out[(string) $name] = preg_match(self::SECRET_KEY, (string) $name) ? self::REDACTED : self::scrub($value, $secrets);
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * @return array{0: ?string, 1: bool} the stored text and whether it was cut at the cap
     */
    public static function body(?string $raw, array $secrets = []): array
    {
        if ($raw === null || $raw === '') {
            return [null, false];
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return ['[binary body, '.strlen($raw).' bytes]', false];
        }

        $text = self::scrub($raw, $secrets);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $clean = self::redactKeys($decoded, $changed);
            if ($changed) {
                $text = (string) json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if (strlen($text) > self::MAX_BODY_BYTES) {
            return [mb_strcut($text, 0, self::MAX_BODY_BYTES, 'UTF-8'), true];
        }

        return [$text, false];
    }

    /** Replace every known secret value that appears anywhere in the text. */
    private static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, self::REDACTED, $text);
            }
        }

        return $text;
    }

    private static function redactKeys(array $data, ?bool &$changed): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) && (is_scalar($value) || is_array($value))) {
                // "usage.output_tokens" style counters are not secrets: only redact string values.
                if (is_string($value)) {
                    $data[$key] = self::REDACTED;
                    $changed = true;
                }
            } elseif (is_array($value)) {
                $data[$key] = self::redactKeys($value, $changed);
            }
        }

        return $data;
    }
}
