<?php

namespace App\Reports;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pixelforge GET /v1/usage, cached per date range. A range that ends before today (UTC) can never
 * change, so it is kept for a day; a range that includes today is kept for a minute. A forced
 * refresh bypasses the cache but sits behind a short lock so it cannot be spammed.
 */
class UsageCache
{
    public const PAST_TTL_SECONDS = 86_400;

    public const TODAY_TTL_SECONDS = 60;

    public const REFRESH_LOCK_SECONDS = 10;

    public function __construct(private PixelforgeClient $client) {}

    /**
     * data is keyed by date; null with an error when usage could not be loaded (failures are never cached).
     *
     * @return array{data: ?array, error: ?string, checked_at: ?string, refresh_throttled: bool}
     */
    public function get(Carbon $start, Carbon $end, bool $refresh = false): array
    {
        $key = sprintf('pixelforge.usage:%s:%s', $start->toDateString(), $end->toDateString());
        $throttled = false;

        if ($refresh) {
            $lock = Cache::lock($key.':refresh', self::REFRESH_LOCK_SECONDS);
            if ($lock->get()) {
                // Deliberately not released: the lock expiring is the throttle.
                Cache::forget($key);
            } else {
                $throttled = true;
            }
        }

        if (is_array($hit = Cache::get($key))) {
            return [...$hit, 'error' => null, 'refresh_throttled' => $throttled];
        }

        try {
            $rows = $this->client->usage($start->toDateString(), $end->toDateString())['data'] ?? [];
        } catch (Throwable $e) {
            return ['data' => null, 'error' => $e->getMessage(), 'checked_at' => null, 'refresh_throttled' => $throttled];
        }

        $entry = [
            'data' => collect($rows)->keyBy('date')->all(),
            'checked_at' => now()->utc()->format('H:i:s'),
        ];
        Cache::put($key, $entry, $end->lt(now()->utc()->startOfDay()) ? self::PAST_TTL_SECONDS : self::TODAY_TTL_SECONDS);

        return [...$entry, 'error' => null, 'refresh_throttled' => $throttled];
    }
}
