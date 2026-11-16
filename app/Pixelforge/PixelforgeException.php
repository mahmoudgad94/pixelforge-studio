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

    /** Worth retrying later: nothing was created and the same request may succeed. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true)
            || ($this->errorCode === null && ($this->status >= 500 || $this->status === 429));
    }
}
