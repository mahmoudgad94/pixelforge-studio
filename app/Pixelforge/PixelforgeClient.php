<?php

namespace App\Pixelforge;

use App\Models\PixelforgeActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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

    /**
     * Send a request, timing it and writing one activity row whatever happens. The row also keeps
     * what went out and what came back (redacted and capped); writing it can never break the call.
     *
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(string $action, callable $request): Response
    {
        $started = hrtime(true);
        $startedAt = Carbon::now();
        $ms = fn () => (int) round((hrtime(true) - $started) / 1e6);
        $sent = null;
        $http = $this->http()->beforeSending(function ($outgoing) use (&$sent) {
            $sent = $outgoing;
        });

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            $this->log($sent, null, [
                'generation_id' => $this->generationId, 'channel' => 'api', 'action' => $action, 'outcome' => 'connection_error',
                'duration_ms' => $ms(), 'attempt' => $this->attempt, 'detail' => mb_substr($e->getMessage(), 0, 250),
                'error_class' => $e::class, 'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'started_at' => $startedAt, 'finished_at' => Carbon::now(),
            ]);

            throw $e;
        }

        try {
            $code = $response->json('error.code');
            $retryAfter = $response->header('Retry-After');
            $attributes = [
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
                // The Pixelforge server that answered (curl's primary_ip); empty for faked responses.
                'ip' => ($response->handlerStats()['primary_ip'] ?? null) ?: null,
                'detail' => collect([
                    $retryAfter !== '' ? 'Retry-After '.$retryAfter : null,
                    $response->header('X-RateLimit-Remaining') !== '' ? 'rate limit left '.$response->header('X-RateLimit-Remaining') : null,
                    $response->json('status') ? 'status '.$response->json('status') : null,
                ])->filter()->implode(', ') ?: null,
                'started_at' => $startedAt,
                'finished_at' => Carbon::now(),
            ];
        } catch (Throwable $e) {
            report($e);

            return $response;
        }

        $this->log($sent, $response, $attributes);

        return $response;
    }

    /** Add the redacted request and response to the row and save it. Never throws. */
    private function log(?object $sent, ?Response $response, array $attributes): void
    {
        try {
            $secrets = [$this->apiKey, config('services.pixelforge.webhook_secret')];
            if ($sent !== null) {
                [$body, $cut] = ActivityPayload::body($sent->body(), $secrets);
                $attributes += [
                    'request_method' => $sent->method(),
                    'request_url' => $sent->url(),
                    'request_headers' => ActivityPayload::headers($sent->headers(), $secrets),
                    'request_body' => $body,
                    'request_body_truncated' => $cut,
                ];
            }
            if ($response !== null) {
                [$body, $cut] = ActivityPayload::body($response->body(), $secrets);
                $attributes += [
                    'response_headers' => ActivityPayload::headers($response->headers(), $secrets),
                    'response_body' => $body,
                    'response_body_truncated' => $cut,
                ];
            }
        } catch (Throwable $e) {
            report($e);
        }

        PixelforgeActivity::record($attributes);
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
        $response = $this->send('POST /generations', fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload));

        if ($response->status() !== 202 && ! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    public function getGeneration(string $id): array
    {
        $response = $this->send('GET /generations/'.$id, fn (PendingRequest $http) => $http->get('/generations/'.$id));

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
