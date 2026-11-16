<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSync $sync): Response
    {
        $payload = $request->getContent(); // raw body, before any JSON decoding

        if (! WebhookSignature::verify($payload, $request->header('Pixelforge-Signature'), (string) config('services.pixelforge.webhook_secret'))) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response('Malformed event', 400);
        }

        $object = $event['data']['object'] ?? null;

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
                return response()->noContent();
            }
        }

        if (str_starts_with($event['type'], 'generation.') && is_array($object)) {
            $generation = $sync->findLocal($object);
            if ($generation) {
                // Even if two copies get here at once, apply() locks the row and is idempotent.
                $sync->apply($generation, $object);
            } else {
                Log::info('Webhook for unknown generation', ['event' => $event['id'], 'generation' => $object['id'] ?? null]);
            }
        }

        $record->forceFill(['processed_at' => now()])->save();

        return response()->noContent();
    }
}
