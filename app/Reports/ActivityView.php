<?php

namespace App\Reports;

use App\Models\PixelforgeActivity;
use App\Pixelforge\ActivityPayload;

/**
 * The "advanced" view of one logged call, in two strengths. The single place that decides what a
 * person may see of a stored payload:
 *
 *  - admin: everything that was stored. Secrets were redacted on the way in and are scrubbed again
 *    here, for rows written before that existed.
 *  - owner: an allow-list of headers and a deny-list of internal money fields (Pixelforge's cost
 *    and billed amounts, rates, token usage, margin), applied on the server.
 */
class ActivityView
{
    /** Header names an owner may see. Everything else is dropped. */
    private const USER_HEADERS = [
        'content-type', 'accept', 'date', 'retry-after', 'idempotent-replayed',
        'pixelforge-event-id', 'pixelforge-event-type', 'pixelforge-delivery-attempt',
    ];

    /** A body key is internal if any word in its name is one of these (cost, output_tokens, billed_amount, ...). */
    private const USER_DENIED_WORDS = [
        'cost', 'costs', 'price', 'prices', 'pricing', 'billed', 'billing', 'bill', 'charge', 'charged', 'charges',
        'amount', 'amounts', 'rate', 'rates', 'usage', 'token', 'tokens', 'margin', 'markup', 'provider', 'fee', 'fees',
        'balance', 'credit', 'credits', 'picos', 'usd', 'currency', 'metadata',
    ];

    public static function admin(PixelforgeActivity $a): array
    {
        $secrets = [config('services.pixelforge.key'), config('services.pixelforge.webhook_secret')];

        return self::shape($a, [
            'ip' => $a->ip,
            'event_id' => $a->event_id,
            'attempt' => $a->attempt,
        ], false, $secrets);
    }

    public static function user(PixelforgeActivity $a): array
    {
        return self::shape($a, [], true, []);
    }

    /** Remove internal money fields, at any depth. */
    public static function strip(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isInternalKey($key)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::strip($value) : $value;
        }

        return $out;
    }

    public static function isInternalKey(string $key): bool
    {
        $snake = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) $snake), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($words, self::USER_DENIED_WORDS) !== [];
    }

    private static function headers(?array $headers, bool $user, array $secrets): ?array
    {
        if ($headers === null) {
            return null;
        }
        $clean = ActivityPayload::headers($headers, $secrets);

        return $user ? array_filter($clean, fn ($name) => in_array(strtolower((string) $name), self::USER_HEADERS, true), ARRAY_FILTER_USE_KEY) : $clean;
    }

    /**
     * @return array{value: mixed, truncated: bool, shown: bool} value is the parsed JSON, or the text when it is not JSON
     */
    private static function body(?string $text, bool $truncated, bool $user, array $secrets): array
    {
        if ($text === null) {
            return ['value' => null, 'truncated' => false, 'shown' => true];
        }
        // An owner only gets a body we can parse and filter field by field: not one cut at the cap, not plain text.
        $json = $truncated ? null : json_decode($text, true);
        if ($user) {
            return is_array($json)
                ? ['value' => self::strip($json), 'truncated' => false, 'shown' => true]
                : ['value' => null, 'truncated' => $truncated, 'shown' => false];
        }
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, ActivityPayload::REDACTED, $text);
            }
        }
        $json = $truncated ? null : json_decode($text, true);

        return ['value' => is_array($json) ? $json : $text, 'truncated' => $truncated, 'shown' => true];
    }

    private static function shape(PixelforgeActivity $a, array $internal, bool $user, array $secrets): array
    {
        $error = $a->error_code || $a->error_class || $a->error_message;

        return [
            'id' => $a->id,
            'channel' => $a->channel,
            'action' => $a->action,
            'outcome' => $a->outcome,
            'started_at' => ($a->started_at ?? $a->created_at)?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'finished_at' => $a->finished_at?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'duration_ms' => $a->duration_ms,
            'method' => $a->request_method,
            'url' => $a->request_url,
            'request' => [
                'headers' => self::headers($a->request_headers, $user, $secrets),
                'body' => self::body($a->request_body, (bool) $a->request_body_truncated, $user, $secrets),
            ],
            'response' => [
                'status' => $a->status_code,
                'headers' => self::headers($a->response_headers, $user, $secrets),
                'body' => self::body($a->response_body, (bool) $a->response_body_truncated, $user, $secrets),
            ],
            'error' => $error ? ['code' => $a->error_code, 'class' => $user ? null : $a->error_class, 'message' => $a->error_message] : null,
        ] + $internal;
    }
}
