<?php

namespace App\Reports;

use App\Models\Generation;
use App\Models\PixelforgeActivity;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Everything that happened to one generation: how long each stage took, every
 * call we made to Pixelforge and every webhook it sent us (with IP, attempt and our answer).
 */
class GenerationTimeline
{
    /** @param bool $payloads  add each call's redacted request and response (the admin page; the API and MCP stay lean) */
    public function build(Generation $g, bool $payloads = false): array
    {
        $g->loadMissing('user:id,email');
        $ok = $g->status === Generation::SUCCEEDED;

        $stages = [];
        $add = function (string $label, ?Carbon $from, ?Carbon $to) use (&$stages) {
            $stages[] = ['label' => $label, 'ms' => $from && $to ? (int) $from->diffInMilliseconds($to) : null];
        };
        $add('Waiting in our queue, then accepted by Pixelforge', $g->created_at, $g->submitted_at);
        $add('Queued at Pixelforge until processing started', $g->submitted_at, $g->started_at);
        $add('Processing until finished', $g->started_at, $g->completed_at);
        $add('Total, click to result', $g->created_at, $g->completed_at);

        $activity = PixelforgeActivity::query()->where('generation_id', $g->id)->orderBy('id')->get();

        return [
            'generation' => [
                'id' => $g->id,
                'pixelforge_id' => $g->pixelforge_id,
                'user' => $g->user?->email,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status,
                'error' => $g->error_message,
                'usage' => $g->usage,
                'estimate' => Money::format($g->estimated_price_picos),
                'estimate_range' => [Money::format($g->estimated_min_price_picos), Money::format($g->estimated_max_price_picos)],
                'cost' => $ok ? Money::toUsdString($g->provider_cost_picos, 9) : '0.000000000',
                'price' => $ok ? Money::format($g->price_picos) : '$0.00',
                'profit' => $ok ? Money::format($g->price_picos - $g->provider_cost_picos) : '$0.00',
                'submit_attempts' => $g->submit_attempts,
                'created_at' => $g->created_at?->toIso8601String(),
                'submitted_at' => $g->submitted_at?->toIso8601String(),
                'started_at' => $g->started_at?->toIso8601String(),
                'completed_at' => $g->completed_at?->toIso8601String(),
            ],
            'stages' => $stages,
            'summary' => [
                'api_calls' => $activity->where('channel', 'api')->count(),
                'webhooks' => $activity->where('channel', 'webhook')->count(),
                'duplicates_ignored' => $activity->where('outcome', 'duplicate')->count(),
                'sender_ips' => $activity->where('channel', 'webhook')->pluck('ip')->filter()->unique()->values()->all(),
            ],
            'activity' => $activity->map(fn (PixelforgeActivity $a) => $a->toFrontend() + ($payloads ? ['advanced' => ActivityView::admin($a)] : []))->all(),
        ];
    }
}
