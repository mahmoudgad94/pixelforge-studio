<?php

namespace App\Http\Controllers;

use App\Models\PixelforgeActivity;
use App\Models\WebhookEvent;
use App\Pixelforge\ActivityPayload;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSync $sync): Response
    {
        $started = hrtime(true);
        $startedAt = Carbon::now();
        $payload = $request->getContent(); // raw body, before any JSON decoding

        // Every delivery is logged: who sent it, which attempt, what we answered and how long we took.
        $log = function (string $outcome, int $status, ?array $event = null, ?int $generationId = null, ?string $detail = null, ?string $answer = null) use ($request, $started, $startedAt, $payload) {
            PixelforgeActivity::record([
                'generation_id' => $generationId,
                'channel' => 'webhook',
                'action' => $event['type'] ?? ($request->header('Pixelforge-Event-Type') ?: 'unknown'),
                'status_code' => $status,
                'outcome' => $outcome,
                'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'attempt' => is_numeric($request->header('Pixelforge-Delivery-Attempt')) ? (int) $request->header('Pixelforge-Delivery-Attempt') : null,
                'event_id' => $event['id'] ?? ($request->header('Pixelforge-Event-Id') ?: null),
                'ip' => $request->ip(),
                'detail' => $detail,
                'started_at' => $startedAt,
                'finished_at' => Carbon::now(),
            ] + $this->payloads($request, $payload, $answer));
        };

        if (! WebhookSignature::verify($payload, $request->header('Pixelforge-Signature'), (string) config('services.pixelforge.webhook_secret'))) {
            $log('invalid_signature', 400, answer: 'Invalid signature');

            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);

        // "Send test event" from the dashboard: acknowledge, nothing to record.
        if (is_array($event) && ($event['type'] ?? null) === 'ping') {
            $log('ping', 204, $event);

            return response()->noContent();
        }

        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            $log('malformed', 400, answer: 'Malformed event');

            return response('Malformed event', 400);
        }

        $object = $event['data']['object'] ?? null;
        $generation = str_starts_with($event['type'], 'generation.') && is_array($object) ? $sync->findLocal($object) : null;

        // Record the event once. A duplicate delivery of an already processed event is acknowledged and ignored.
        try {
            $record = WebhookEvent::create([
                'id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => is_array($object) ? ($object['id'] ?? null) : null,
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            $record = WebhookEvent::findOrFail($event['id']);
            $record->increment('deliveries');
            if ($record->processed_at !== null) {
                $log('duplicate', 204, $event, $generation?->id, "delivery #{$record->deliveries} of this event, ignored");

                return response()->noContent();
            }
        }

        $detail = null;
        if ($generation) {
            // Even if two copies get here at once, apply() locks the row and is idempotent.
            $before = $generation->status;
            $sync->apply($generation, $object);
            $after = $generation->fresh()->status;
            $detail = $before === $after ? "status stays {$after}" : "status {$before} -> {$after}";
        } elseif (str_starts_with($event['type'], 'generation.')) {
            Log::info('Webhook for unknown generation', ['event' => $event['id'], 'generation' => $object['id'] ?? null]);
            $detail = 'unknown generation';
        }

        $record->forceFill(['processed_at' => now()])->save();
        $log('processed', 204, $event, $generation?->id, $detail);

        return response()->noContent();
    }

    /**
     * What came in and what we answered, redacted and capped. Logging must never break the answer,
     * so a failure here only costs the payload columns.
     */
    private function payloads(Request $request, string $payload, ?string $answer): array
    {
        try {
            $secrets = [config('services.pixelforge.webhook_secret'), config('services.pixelforge.key')];
            [$body, $cut] = ActivityPayload::body($payload, $secrets);
            [$reply, $replyCut] = ActivityPayload::body($answer, $secrets);

            return [
                'request_method' => $request->method(),
                'request_url' => $request->fullUrl(),
                'request_headers' => ActivityPayload::headers($request->headers->all(), $secrets),
                'request_body' => $body,
                'request_body_truncated' => $cut,
                'response_body' => $reply,
                'response_body_truncated' => $replyCut,
            ];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
