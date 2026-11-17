<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            $response->status(),
            $response->json('error.code'),
            (string) ($response->json('error.message') ?? 'Pixelforge returned HTTP '.$response->status()),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /** Errors that will fail the same way every time: retrying only wastes requests. */
    public const PERMANENT = [
        'invalid_api_key',
        'validation_failed',
        'content_policy_violation',
        'daily_limit_exceeded',
        'idempotency_key_reused',
        'not_found',
    ];

    /** Errors where nothing was created and the same request may succeed later. */
    public const TEMPORARY = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    /** Worth retrying later. Server errors (5xx) are retried too: the Idempotency-Key makes that safe. */
    public function isRetryable(): bool
    {
        if (in_array($this->errorCode, self::PERMANENT, true)) {
            return false;
        }

        return in_array($this->errorCode, self::TEMPORARY, true)
            || $this->status >= 500
            || ($this->errorCode === null && $this->status === 429);
    }
}
