<?php

namespace App\Reports;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Spend, provider cost and profit per UTC day and per user, with each day
 * checked against Pixelforge GET /v1/usage. Days are the UTC day the
 * generation completed, which is how Pixelforge bills.
 */
class SpendReport
{
    public function __construct(private PixelforgeClient $client) {}

    /** Token kinds Pixelforge reports per day; each must match ours, not only the cost. */
    private const TOKENS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function build(?string $start, ?string $end): array
    {
        $end = $this->day($end) ?? now()->utc()->startOfDay();
        $start = $this->day($start) ?? $end->copy()->subDays(13);
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91);
        }

        $rows = Generation::query()
            ->with('user:id,name,email')
            ->where('status', Generation::SUCCEEDED)
            ->whereBetween('completed_at', [$start, $end->copy()->endOfDay()])
            ->get(['id', 'user_id', 'completed_at', 'provider_cost_picos', 'price_picos', 'usage']);

        $byDayUser = [];
        $byDay = [];
        foreach ($rows as $g) {
            $day = $g->completed_at->copy()->utc()->toDateString();
            $key = $day.'|'.$g->user_id;
            $byDayUser[$key] ??= ['date' => $day, 'user' => $g->user?->name.' <'.$g->user?->email.'>', 'generations' => 0, 'spend' => 0, 'cost' => 0];
            $byDayUser[$key]['generations']++;
            $byDayUser[$key]['spend'] += $g->price_picos;
            $byDayUser[$key]['cost'] += $g->provider_cost_picos;

            $byDay[$day] ??= ['generations' => 0, 'spend' => 0, 'cost' => 0, 'tokens' => array_fill_keys(self::TOKENS, 0)];
            $byDay[$day]['generations']++;
            $byDay[$day]['spend'] += $g->price_picos;
            $byDay[$day]['cost'] += $g->provider_cost_picos;
            foreach (self::TOKENS as $kind) {
                $byDay[$day]['tokens'][$kind] += (int) ($g->usage[$kind] ?? 0);
            }
        }

        $usage = null;
        $usageError = null;
        try {
            $usage = collect($this->client->usage($start->toDateString(), $end->toDateString())['data'] ?? [])->keyBy('date');
        } catch (Throwable $e) {
            $usageError = $e->getMessage();
        }

        $days = [];
        for ($d = $end->copy(); $d->gte($start); $d->subDay()) {
            $date = $d->toDateString();
            $ours = $byDay[$date] ?? ['generations' => 0, 'spend' => 0, 'cost' => 0, 'tokens' => array_fill_keys(self::TOKENS, 0)];
            $theirs = $usage?->get($date);
            $days[] = [
                'date' => $date,
                'generations' => $ours['generations'],
                'spend' => Money::format($ours['spend']),
                'cost' => Money::toUsdString($ours['cost'], 9),
                'profit' => Money::format($ours['spend'] - $ours['cost']),
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'pixelforge_cost' => $theirs['cost_usd'] ?? null,
                'matches' => $this->matches($usage !== null, $ours, $theirs),
            ];
        }

        $totals = ['generations' => 0, 'spend' => 0, 'cost' => 0];
        foreach ($byDay as $day) {
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $day[$k];
            }
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'perUser' => collect($byDayUser)->sortByDesc('date')->values()->map(fn ($r) => [
                ...$r,
                'spend' => Money::format($r['spend']),
                'cost' => Money::toUsdString($r['cost'], 9),
                'profit' => Money::format($r['spend'] - $r['cost']),
            ])->all(),
            'totals' => [
                'generations' => $totals['generations'],
                'spend' => Money::format($totals['spend']),
                'cost' => Money::toUsdString($totals['cost'], 9),
                'profit' => Money::format($totals['spend'] - $totals['cost']),
            ],
            'usageError' => $usageError,
        ];
    }

    /**
     * Everything that finished on one UTC day, newest first: billed (succeeded) generations
     * with their exact numbers, and failed ones, which cost nothing.
     */
    public function dayDetail(string $date): ?array
    {
        $day = $this->day($date);
        if (! $day) {
            return null;
        }

        return Generation::query()
            ->with('user:id,name,email')
            ->whereIn('status', [Generation::SUCCEEDED, Generation::FAILED])
            ->whereBetween('completed_at', [$day, $day->copy()->endOfDay()])
            ->latest('completed_at')
            ->limit(500)
            ->get()
            ->map(function (Generation $g) {
                $billed = $g->status === Generation::SUCCEEDED;
                $expired = $g->image_url_expires_at !== null && $g->image_url_expires_at->isPast();

                return [
                    'id' => $g->id,
                    'pixelforge_id' => $g->pixelforge_id,
                    'completed_at' => $g->completed_at->copy()->utc()->format('H:i:s'),
                    'user' => $g->user?->name,
                    'email' => $g->user?->email,
                    'status' => $g->status,
                    'prompt' => $g->prompt,
                    'size' => $g->size,
                    'quality' => $g->quality,
                    'references' => count($g->reference_images ?? []),
                    'tokens' => $billed ? array_intersect_key($g->usage ?? [], array_flip(self::TOKENS)) : null,
                    'estimate' => Money::format($g->estimated_price_picos),
                    'cost' => $billed ? Money::toUsdString($g->provider_cost_picos, 9) : '0.000000000',
                    'spend' => $billed ? Money::format($g->price_picos) : '$0.00',
                    'profit' => $billed ? Money::format($g->price_picos - $g->provider_cost_picos) : '$0.00',
                    'error' => $billed ? null : $g->error_message,
                    'image_url' => $billed && ! $expired ? $g->image_url : null,
                ];
            })
            ->all();
    }

    /** A strict Y-m-d date in UTC, or null for anything else (garbage falls back to the default range). */
    private function day(?string $value): ?Carbon
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = Carbon::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * True when Pixelforge billed exactly what we recorded: same count, same exact cost, same tokens.
     * Null when usage could not be loaded. Pixelforge lists every day (zeros included), so a missing
     * row for a day we billed is a mismatch.
     */
    private function matches(bool $usageLoaded, array $ours, ?array $theirs): ?bool
    {
        if (! $usageLoaded) {
            return null;
        }
        if ($theirs === null) {
            return $ours['generations'] === 0 ? null : false;
        }
        if ((int) $theirs['generations'] !== $ours['generations'] || Money::fromUsd($theirs['cost_usd']) !== $ours['cost']) {
            return false;
        }
        foreach (self::TOKENS as $kind) {
            if (isset($theirs[$kind]) && (int) $theirs[$kind] !== $ours['tokens'][$kind]) {
                return false;
            }
        }

        return true;
    }
}
