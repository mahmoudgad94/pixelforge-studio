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

    public function build(?string $start, ?string $end): array
    {
        $end = Carbon::parse($end ?? now()->utc()->toDateString(), 'UTC')->startOfDay();
        $start = Carbon::parse($start ?? $end->copy()->subDays(13)->toDateString(), 'UTC')->startOfDay();
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
            ->get(['id', 'user_id', 'completed_at', 'provider_cost_picos', 'price_picos']);

        $byDayUser = [];
        $byDay = [];
        foreach ($rows as $g) {
            $day = $g->completed_at->copy()->utc()->toDateString();
            $key = $day.'|'.$g->user_id;
            $byDayUser[$key] ??= ['date' => $day, 'user' => $g->user?->name.' <'.$g->user?->email.'>', 'generations' => 0, 'spend' => 0, 'cost' => 0];
            $byDayUser[$key]['generations']++;
            $byDayUser[$key]['spend'] += $g->price_picos;
            $byDayUser[$key]['cost'] += $g->provider_cost_picos;

            $byDay[$day] ??= ['generations' => 0, 'spend' => 0, 'cost' => 0];
            $byDay[$day]['generations']++;
            $byDay[$day]['spend'] += $g->price_picos;
            $byDay[$day]['cost'] += $g->provider_cost_picos;
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
            $ours = $byDay[$date] ?? ['generations' => 0, 'spend' => 0, 'cost' => 0];
            $theirs = $usage?->get($date);
            $theirCost = $theirs ? Money::fromUsd($theirs['cost_usd']) : null;
            $days[] = [
                'date' => $date,
                'generations' => $ours['generations'],
                'spend' => Money::format($ours['spend']),
                'cost' => Money::toUsdString($ours['cost'], 9),
                'profit' => Money::format($ours['spend'] - $ours['cost']),
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'pixelforge_cost' => $theirs['cost_usd'] ?? null,
                'matches' => $theirs === null ? null : ($theirCost === $ours['cost'] && (int) $theirs['generations'] === $ours['generations']),
            ];
        }

        $totals = ['generations' => 0, 'spend' => 0, 'cost' => 0];
        foreach ($byDay as $day) {
            foreach ($totals as $k => $_) {
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
}
