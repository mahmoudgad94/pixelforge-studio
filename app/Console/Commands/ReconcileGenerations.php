<?php

namespace App\Console\Commands;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Safety net for missed webhooks (Pixelforge's advice: poll if all deliveries fail).
 * Polls generations that have been in flight for a while and applies the real state.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--older-than=60 : seconds since last update}';

    protected $description = 'Poll Pixelforge for generations whose webhooks have not arrived';

    public function handle(PixelforgeClient $client, GenerationSync $sync): int
    {
        $cutoff = now()->subSeconds((int) $this->option('older-than'));

        Generation::query()
            ->whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(50) // stay well under 60 requests/minute
            ->get()
            ->each(function (Generation $generation) use ($client, $sync) {
                try {
                    $sync->apply($generation, $client->forGeneration($generation->id)->getGeneration($generation->pixelforge_id));
                } catch (PixelforgeException $e) {
                    $this->warn("#{$generation->id}: {$e->getMessage()}");
                    if ($e->isRetryable()) {
                        return false; // stop this run, try next minute
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        // Pending rows whose job was lost (e.g. worker restarted): re-dispatch. Idempotency-Key keeps it safe.
        Generation::query()
            ->where('status', Generation::PENDING)
            ->where('updated_at', '<', now()->subMinutes(5))
            ->pluck('id')
            ->each(fn ($id) => SubmitGeneration::dispatch($id));

        return self::SUCCESS;
    }
}
