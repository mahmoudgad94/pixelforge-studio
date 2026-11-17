<?php

namespace App\Pixelforge;

use App\Models\PixelforgeActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PixelforgeClient
{
    /** Local generation the next calls belong to, for the activity log. */
    private ?int $generationId = null;

    private ?int $attempt = null;

    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
    ) {}

    public function forGeneration(?int $generationId, ?int $attempt = null): static
    {
        $client = clone $this;
        $client->generationId = $generationId;
        $client->attempt = $attempt;

        return $client;
    }

    /** Send a request, timing it and writing one activity row whatever happens. */
    private function send(string $action, callable $request): Response
    {
        $started = hrtime(true);
        $ms = fn () => (int) round((hrtime(true) - $started) / 1e6);

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            PixelforgeActivity::record([
                'generation_id' => $this->generationId, 'channel' => 'api', 'action' => $action, 'outcome' => 'connection_error',
                'duration_ms' => $ms(), 'attempt' => $this->attempt, 'detail' => mb_substr($e->getMessage(), 0, 250),
            ]);

            throw $e;
        }

        $code = $response->json('error.code');
        $retryAfter = $response->header('Retry-After');
        PixelforgeActivity::record([
            'generation_id' => $this->generationId,
            'channel' => 'api',
            'action' => $action,
            'status_code' => $response->status(),
            'outcome' => match (true) {
                $response->header('Idempotent-Replayed') === 'true' => 'replayed',
                $response->successful() => 'ok',
                in_array($response->status(), [429, 503], true) && $code !== 'daily_limit_exceeded' => 'retryable_error',
                default => 'error',
            },
            'error_code' => is_string($code) ? $code : null,
            'duration_ms' => $ms(),
            'attempt' => $this->attempt,
            'detail' => collect([
                $retryAfter !== '' ? 'Retry-After '.$retryAfter : null,
                $response->header('X-RateLimit-Remaining') !== '' ? 'rate limit left '.$response->header('X-RateLimit-Remaining') : null,
                $response->json('status') ? 'status '.$response->json('status') : null,
            ])->filter()->implode(', ') ?: null,
        ]);

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * POST /v1/generations. The idempotency key makes this safe to retry after
     * a timeout: Pixelforge replays the same generation instead of creating a second one.
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        $response = $this->send('POST /generations', fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload));

        if ($response->status() !== 202 && ! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    public function getGeneration(string $id): array
    {
        $response = $this->send('GET /generations/'.$id, fn () => $this->http()->get('/generations/'.$id));

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    public function usage(string $startDate, string $endDate): array
    {
        $response = $this->http()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]);

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    /** Current price table (public endpoint), cached; falls back to the documented table. */
    public function pricingTable(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(10), function () {
            try {
                $response = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson()->timeout(5)->get('/pricing');
                if ($response->successful() && is_array($response->json('rates')) && is_array($response->json('tokens'))) {
                    return ['rates' => $response->json('rates'), 'tokens' => $response->json('tokens')];
                }
            } catch (Throwable) {
                // fall through
            }

            return Pricing::defaultTable();
        });
    }
}
