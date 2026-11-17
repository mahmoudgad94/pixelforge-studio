<?php

namespace App\Reports;

use App\Models\Generation;
use App\Models\PixelforgeActivity;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The user-facing story of one generation: friendly steps with the time each began and how long
 * it lasted, retries in plain words, and only the data the owner may see. Nothing internal
 * (provider cost, margin, IPs, signatures, rates, tokens) is ever returned. The "advanced" details
 * of each step (what we sent, what Pixelforge answered) pass through ActivityView::user(), which
 * drops internal money fields and secrets.
 */
class GenerationProgress
{
    public function build(Generation $g): array
    {
        $activity = PixelforgeActivity::query()
            ->where('generation_id', $g->id)->where('channel', 'api')->where('action', 'POST /generations')
            ->orderBy('id')->get(['outcome', 'created_at']);
        $calls = PixelforgeActivity::query()->where('generation_id', $g->id)->orderBy('id')->get();

        $expired = $g->image_url_expires_at !== null && $g->image_url_expires_at->isPast();
        $ok = $g->status === Generation::SUCCEEDED;
        $steps = $this->steps($g, $activity->first(fn ($a) => in_array($a->outcome, ['ok', 'replayed'], true))?->created_at);
        $end = $g->completed_at;
        $steps = $this->withDetails($steps, $calls);

        return [
            'id' => $g->id,
            'status' => $g->status,
            'final' => $g->isFinal(),
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'reference_images' => $g->reference_images ?? [],
            'pixelforge_id' => $g->pixelforge_id,
            'image_url' => $ok && ! $expired ? $g->image_url : null,
            'image_expired' => $ok && $expired,
            'can_download' => $ok && ! $expired && $g->image_url !== null,
            'estimated_price' => Money::format($g->estimated_price_picos),
            'estimated_price_exact' => Money::toUsdString($g->estimated_price_picos),
            'estimated_range' => [Money::format($g->estimated_min_price_picos), Money::format($g->estimated_max_price_picos)],
            'price' => $ok ? Money::format($g->price_picos) : ($g->status === Generation::FAILED ? '$0.00' : null),
            'price_exact' => $ok ? Money::toUsdString($g->price_picos) : ($g->status === Generation::FAILED ? Money::toUsdString(0) : null),
            'error' => $g->error_message,
            'failure_kind' => $g->status === Generation::FAILED ? $this->failureKind($g->error_code) : null,
            'retries' => $this->retries($g, $activity),
            'steps' => $steps,
            'total_ms' => $end ? (int) $g->created_at->diffInMilliseconds($end) : null,
            'created_at' => $g->created_at?->toIso8601String(),
            'completed_at' => $end?->toIso8601String(),
        ];
    }

    /** Sent, Accepted by Pixelforge, Queued, Generating, then Succeeded or Failed. */
    private function steps(Generation $g, ?Carbon $firstAccepted): array
    {
        $failed = $g->status === Generation::FAILED;
        $accepted = $g->submitted_at ?? $g->provider_created_at ?? $firstAccepted;
        $reachedAccepted = $accepted !== null || $g->pixelforge_id !== null || in_array($g->status, [Generation::QUEUED, Generation::PROCESSING, Generation::SUCCEEDED], true);

        // [key, label, at, reached]
        $raw = [
            ['sent', 'Sent', $g->created_at, true],
            ['accepted', 'Accepted by Pixelforge', $accepted, $reachedAccepted],
            ['queued', 'Queued', $accepted, $reachedAccepted],
            ['generating', 'Generating', $g->started_at, $g->started_at !== null || $g->status === Generation::PROCESSING || $g->status === Generation::SUCCEEDED],
            [$failed ? 'failed' : 'succeeded', $failed ? 'Failed' : 'Succeeded', $g->completed_at, $g->isFinal()],
        ];

        // The step in progress: pending is still being sent, queued waits in line, processing is drawing.
        $current = $g->isFinal() ? null : match ($g->status) {
            Generation::PENDING => 0,
            Generation::QUEUED => 2,
            default => 3,
        };

        $steps = [];
        foreach ($raw as $i => [$key, $label, $at, $reached]) {
            $state = match (true) {
                $current !== null && $i < $current => 'done',
                $current === $i => 'current',
                $current !== null => 'upcoming',
                $reached => $key === 'failed' ? 'failed' : 'done',
                default => 'skipped',
            };
            $steps[] = ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $at?->toIso8601String(), 'ms' => null, '_at' => $at];
        }

        // How long each step lasted: from its start to the start of the next step that has a time.
        foreach ($steps as $i => &$step) {
            if (! in_array($step['state'], ['done', 'current'], true) || $step['_at'] === null || $i === count($steps) - 1) {
                continue;
            }
            if ($step['state'] === 'current') {
                continue; // still running: the page counts it live
            }
            for ($j = $i + 1; $j < count($steps); $j++) {
                if ($steps[$j]['_at'] !== null && in_array($steps[$j]['state'], ['done', 'failed', 'current'], true)) {
                    $step['ms'] = max(0, (int) $step['_at']->diffInMilliseconds($steps[$j]['_at'], false));
                    break;
                }
            }
        }
        unset($step);

        return array_map(function (array $s) {
            unset($s['_at']);

            return $s;
        }, $steps);
    }

    /**
     * Put each logged call under the step it belongs to. A submit that was accepted shows its request
     * under "Sent" and its answer under "Accepted"; webhooks go to the step their event names; polls
     * (GET) go to the latest step that had begun when they ran.
     */
    private function withDetails(array $steps, $calls): array
    {
        $keys = array_column($steps, 'key');
        $final = in_array('failed', $keys, true) ? 'failed' : 'succeeded';
        $starts = [];
        foreach ($steps as $s) {
            if ($s['at'] !== null && $s['state'] !== 'upcoming') {
                $starts[$s['key']] = Carbon::parse($s['at']);
            }
        }

        $by = array_fill_keys($keys, []);
        foreach ($calls as $call) {
            $view = ActivityView::user($call);
            $accepted = in_array($call->outcome, ['ok', 'replayed'], true);

            if ($call->action === 'POST /generations') {
                $entries = $accepted
                    ? ['sent' => $this->part($view, 'request'), 'accepted' => $this->part($view, 'response')]
                    : ['sent' => $this->part($view, 'both')];
            } else {
                $key = match (true) {
                    str_ends_with($call->action, '.queued') => 'queued',
                    str_ends_with($call->action, '.processing') => 'generating',
                    str_ends_with($call->action, '.succeeded'), str_ends_with($call->action, '.failed') => $final,
                    default => $this->stepAt($starts, $call->started_at ?? $call->created_at),
                };
                $entries = [$key => $this->part($view, 'both')];
            }

            foreach ($entries as $key => $entry) {
                $by[in_array($key, $keys, true) ? $key : end($keys)][] = $entry;
            }
        }

        foreach ($steps as &$step) {
            $step['details'] = $by[$step['key']];
        }

        return $steps;
    }

    private function part(array $view, string $part): array
    {
        if ($part === 'request') {
            $view['response'] = null;
        } elseif ($part === 'response') {
            $view['request'] = null;
        }

        return ['part' => $part] + $view;
    }

    /** The latest step that had begun by this moment. */
    private function stepAt(array $starts, ?Carbon $at): string
    {
        $found = 'accepted';
        foreach ($starts as $key => $start) {
            if ($at !== null && $start->lte($at)) {
                $found = $key;
            }
        }

        return $found;
    }

    private function retries(Generation $g, $activity): array
    {
        $count = max(0, $g->submit_attempts - 1);
        if ($count === 0) {
            return ['count' => 0, 'text' => null];
        }

        $connection = $activity->contains('outcome', 'connection_error');
        $cause = $connection && ! $activity->contains('outcome', 'retryable_error') ? 'a connection problem' : 'a temporary error';
        $times = match ($count) {
            1 => 'once',
            2 => 'twice',
            default => $count.' times',
        };

        return ['count' => $count, 'text' => "Retried {$times} after {$cause}."];
    }

    private function failureKind(?string $code): string
    {
        return match ($code) {
            'content_policy_violation' => 'blocked',
            'daily_limit_exceeded' => 'limit',
            'submission_failed', 'invalid_api_key', 'idempotency_key_reused' => 'not_sent',
            default => 'failed',
        };
    }
}
