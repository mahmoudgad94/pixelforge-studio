<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PixelforgeClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
    ) {}

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
        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload);

        if ($response->status() !== 202 && ! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    public function getGeneration(string $id): array
    {
        $response = $this->http()->get('/generations/'.$id);

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
