<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Spend, provider cost and profit per UTC day and per user.
 *
 * Days are the UTC day the generation completed, the same rule Pixelforge
 * uses for GET /v1/usage, so the provider cost per day can be compared
 * line by line with Pixelforge's own records.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $end = Carbon::parse($request->query('end', now()->utc()->toDateString()))->utc()->startOfDay();
        $start = Carbon::parse($request->query('start', $end->copy()->subDays(13)->toDateString()))->utc()->startOfDay();
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
            $usage = collect($client->usage($start->toDateString(), $end->toDateString())['data'] ?? [])->keyBy('date');
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

        return Inertia::render('Admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'perUser' => collect($byDayUser)->sortByDesc('date')->values()->map(fn ($r) => [
                ...$r,
                'spend' => Money::format($r['spend']),
                'cost' => Money::toUsdString($r['cost'], 9),
                'profit' => Money::format($r['spend'] - $r['cost']),
            ]),
            'totals' => [
                'generations' => $totals['generations'],
                'spend' => Money::format($totals['spend']),
                'cost' => Money::toUsdString($totals['cost'], 9),
                'profit' => Money::format($totals['spend'] - $totals['cost']),
            ],
            'usageError' => $usageError,
        ]);
    }
}
