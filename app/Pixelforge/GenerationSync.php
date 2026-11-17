<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a Pixelforge generation object (from a webhook, a POST response or a
 * poll) to our local row. This is the only place a generation changes state.
 *
 * Rules:
 *  - Row lock on the generation, so concurrent webhooks are serialised.
 *  - State only moves forward (queued < processing < succeeded|failed). An event
 *    that arrives late or out of order can fill in missing data but never moves
 *    the status back, and a final state is never changed.
 *  - Money is settled in the same transaction as the final status.
 */
class GenerationSync
{
    public function __construct(private Ledger $ledger, private Pricing $pricing) {}

    public function findLocal(array $remote): ?Generation
    {
        $uuid = $remote['metadata']['generation_uuid'] ?? null;

        return Generation::query()
            ->when($uuid, fn ($q) => $q->where('uuid', $uuid))
            ->when(! $uuid, fn ($q) => $q->where('pixelforge_id', $remote['id'] ?? '__none__'))
            ->first();
    }

    public function apply(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            /** @var Generation $local */
            $local = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            $local->pixelforge_id ??= $remote['id'] ?? null;
            $local->last_synced_at = now();

            $incoming = $remote['status'] ?? null;
            if (! isset(Generation::RANK[$incoming])) {
                $local->save();

                return $local;
            }

            // A local-only failure (we gave up submitting, Pixelforge never confirmed)
            // is not authoritative: if Pixelforge says it actually exists, believe Pixelforge.
            $localOnlyFailure = $local->status === Generation::FAILED && in_array($local->error_code, Generation::LOCAL_ONLY_FAILURES, true);

            if ($local->isFinal() && ! $localOnlyFailure) {
                if ($incoming !== $local->status && Generation::RANK[$incoming] === 3) {
                    Log::warning('Pixelforge reported a different final state', ['generation' => $local->id, 'local' => $local->status, 'remote' => $incoming]);
                }
                // Refresh the image URL only (same expiry), nothing else changes.
                if ($local->status === Generation::SUCCEEDED && ! empty($remote['image_url'])) {
                    $local->image_url = $remote['image_url'];
                }
                $local->save();

                return $local;
            }

            if ($localOnlyFailure) {
                $local->completed_at = null;
                $local->error_code = null;
                $local->error_message = null;
            }

            $this->fillTimestamps($local, $remote);

            if (Generation::RANK[$incoming] > Generation::RANK[$local->status] || $localOnlyFailure) {
                $local->status = $incoming;
            }

            if ($local->status === Generation::SUCCEEDED) {
                $local->rates = $remote['rates'];
                $local->usage = $remote['usage'];
                $local->provider_cost_picos = Pricing::providerCost($remote['usage'], $remote['rates']);
                $local->price_picos = $this->pricing->priceFor($local->provider_cost_picos);
                $local->image_url = $remote['image_url'] ?? null;
                $local->image_url_expires_at = $this->date($remote['image_url_expires_at'] ?? null);
                $local->error_code = null;
                $local->error_message = null;
            } elseif ($local->status === Generation::FAILED) {
                $local->error_code = $remote['error']['code'] ?? 'generation_failed';
                $local->error_message = $remote['error']['message'] ?? 'Generation failed';
                $local->rates = $remote['rates'] ?? $local->rates;
            }

            if ($local->isFinal()) {
                $local->completed_at ??= now();
            }

            $local->save();

            if ($local->isFinal()) {
                $this->ledger->settle($local);
            }

            return $local;
        });
    }

    /** Fail a generation that Pixelforge never accepted, and give the user their hold back. */
    public function failLocally(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $local = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if ($local->status !== Generation::PENDING) {
                return $local;
            }
            $local->status = Generation::FAILED;
            $local->error_code = $code;
            $local->error_message = $message;
            $local->completed_at = now();
            $local->save();
            $this->ledger->settle($local);

            return $local;
        });
    }

    private function fillTimestamps(Generation $local, array $remote): void
    {
        $local->provider_created_at ??= $this->date($remote['created_at'] ?? null);
        $local->started_at ??= $this->date($remote['started_at'] ?? null);
        $local->completed_at ??= $this->date($remote['completed_at'] ?? null);
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
