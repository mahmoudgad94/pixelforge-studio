<?php

namespace App\Mcp;

use App\Models\McpToolCall;
use App\Models\User;
use Laravel\Passport\Passport;
use Throwable;

/**
 * The one place that writes the MCP usage log (table mcp_tool_calls).
 * Logging must never break or slow a tool call, so every failure here is reported and swallowed.
 */
class McpCallLog
{
    public const PERSONAL_TOKEN = 'personal token';

    public const UNKNOWN_CLIENT = 'unknown';

    private const MAX_VALUE = 60;

    private const MAX_SUMMARY = 300;

    private const MAX_ERROR = 300;

    /** Argument names whose value is never stored, whatever it holds. */
    private const SENSITIVE_KEY = '/pass|secret|token|key|auth|cookie|credential|bearer|signature|session/i';

    /** @var array<string, string> OAuth client id => name, for this process */
    private static array $clientNames = [];

    /** The OAuth client name (e.g. "Claude") behind this user's access token, or "personal token" for a static token. */
    public function clientFor(?User $user): string
    {
        if (! $user) {
            return self::UNKNOWN_CLIENT;
        }

        $clientId = $user->token()?->oauth_client_id ?? null;
        if (! is_string($clientId) || $clientId === '') {
            return self::PERSONAL_TOKEN;
        }

        try {
            return self::$clientNames[$clientId] ??= Passport::client()->newQuery()->whereKey($clientId)->value('name') ?: self::UNKNOWN_CLIENT;
        } catch (Throwable $e) {
            report($e);

            return self::UNKNOWN_CLIENT;
        }
    }

    /** @param  array<string, mixed>  $arguments */
    public function recordCall(?User $user, ?string $tool, array $arguments, string $outcome, ?string $error, int $startedAtNs): void
    {
        $this->write([
            'user_id' => $user?->id,
            'client' => $this->clientFor($user),
            'tool' => $tool === null ? null : mb_substr($tool, 0, 190),
            'arguments_summary' => $this->summarize($arguments),
            'outcome' => $outcome,
            'error_message' => $error === null ? null : $this->clean($error, self::MAX_ERROR),
            'duration_ms' => (int) round((hrtime(true) - $startedAtNs) / 1_000_000),
        ]);
    }

    /** A request the endpoint turned away before any tool ran (no token, bad token, not an admin). */
    public function recordRefused(?User $user, string $reason, int $startedAtNs): void
    {
        $this->write([
            'user_id' => $user?->id,
            'client' => $this->clientFor($user),
            'tool' => null,
            'arguments_summary' => null,
            'outcome' => McpToolCall::REFUSED,
            'error_message' => $this->clean($reason, self::MAX_ERROR),
            'duration_ms' => (int) round((hrtime(true) - $startedAtNs) / 1_000_000),
        ]);
    }

    /**
     * "email=ana@example.com, amount_usd=2.50, reason=Refund for a broken…". Sensitive names are hidden,
     * values are shortened, and anything that looks like a token or key is replaced.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function summarize(array $arguments): ?string
    {
        $parts = [];
        foreach ($arguments as $key => $value) {
            $key = $this->clean((string) $key, 40);
            $shown = preg_match(self::SENSITIVE_KEY, $key) ? '[hidden]' : $this->clean($this->scalar($value), self::MAX_VALUE);
            $parts[] = "{$key}={$shown}";
        }

        return $parts === [] ? null : $this->truncate(implode(', ', $parts), self::MAX_SUMMARY);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'null',
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }

    /** One line, secrets replaced, shortened. */
    private function clean(string $text, int $max): string
    {
        $text = preg_replace([
            '/\bBearer\s+\S+/i',
            '/\bpfs_[A-Za-z0-9]+/',
            '/\beyJ[A-Za-z0-9_-]{8,}(\.[A-Za-z0-9_-]+){0,2}/',
            '/[A-Za-z0-9_\-+\/=]{40,}/',
        ], '[hidden]', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $this->truncate($text, $max);
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /** @param  array<string, mixed>  $row */
    private function write(array $row): void
    {
        try {
            McpToolCall::query()->create($row);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
