<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Safe to run any number of times: the local UUID is the Idempotency-Key, so a
 * retry after a timeout returns the generation Pixelforge already created.
 * Rate, concurrency and overload errors are retried with Retry-After plus
 * exponential backoff; permanent errors fail the generation and release the hold.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 12;

    public int $tries = 0; // attempts are counted on the generation row instead

    public function __construct(public int $generationId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(PixelforgeClient $client, GenerationSync $sync): void
    {
        $generation = Generation::find($this->generationId);
        if (! $generation || $generation->status !== Generation::PENDING) {
            return; // already accepted (maybe a webhook beat us), or failed
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => [
                    'generation_uuid' => $generation->uuid,
                    'user_id' => (string) $generation->user_id,
                ],
            ], $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $sync, $e);

            return;
        } catch (ConnectionException $e) {
            // We don't know if Pixelforge received it. Retrying with the same key is safe.
            $this->retryLater($generation, $sync, null, 'connection: '.$e->getMessage());

            return;
        }

        $generation->forceFill(['submitted_at' => now()])->save();
        $sync->apply($generation, $remote);
    }

    private function handleError(Generation $generation, GenerationSync $sync, PixelforgeException $e): void
    {
        if ($e->isRetryable()) {
            $this->retryLater($generation, $sync, $e->retryAfter, $e->errorCode ?? (string) $e->status);

            return;
        }

        $message = match ($e->errorCode) {
            'content_policy_violation' => 'Blocked by the content policy. You were not charged.',
            'daily_limit_exceeded' => 'Daily generation limit reached. Try again tomorrow. You were not charged.',
            'validation_failed' => 'Pixelforge rejected the request: '.$e->getMessage(),
            default => 'Pixelforge error: '.$e->getMessage(),
        };

        $sync->failLocally($generation, $e->errorCode ?? 'http_'.$e->status, $message);
    }

    private function retryLater(Generation $generation, GenerationSync $sync, ?int $retryAfter, string $reason): void
    {
        $attempt = $generation->submit_attempts;

        if ($attempt >= self::MAX_ATTEMPTS) {
            Log::warning('Giving up submitting generation', ['generation' => $generation->id, 'reason' => $reason]);
            // Marked as a local-only failure: if Pixelforge did create it after all,
            // the webhook or the reconciler will still record it and charge correctly.
            $sync->failLocally($generation, 'submission_failed', 'Pixelforge is busy right now. Please try again. You were not charged.');

            return;
        }

        // Respect Retry-After, back off exponentially, add jitter, cap at 2 minutes.
        $backoff = min(120, (2 ** min($attempt, 7)));
        $delay = max($retryAfter ?? 0, $backoff) + random_int(0, 3);

        $this->release($delay);
    }

    public function failed(?Throwable $e): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation && $generation->status === Generation::PENDING) {
            app(GenerationSync::class)->failLocally($generation, 'submission_failed', 'Could not reach Pixelforge. You were not charged.');
        }
    }
}
