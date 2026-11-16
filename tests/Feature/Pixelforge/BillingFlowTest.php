<?php

namespace Tests\Feature\Pixelforge;

use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSync;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use App\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => self::SECRET, 'services.pixelforge.key' => 'pf_test']);
        Http::preventStrayRequests();
        Http::fake(['*/v1/pricing' => Http::response(['rates' => Pricing::defaultTable()['rates'], 'tokens' => Pricing::defaultTable()['tokens']])]);
    }

    private function userWithCredits(string $usd = '10'): User
    {
        $user = User::factory()->create(['balance_picos' => Money::fromUsd($usd)]);

        return $user;
    }

    private function remote(Generation $g, string $status, array $extra = []): array
    {
        return array_merge([
            'id' => 'gen_'.$g->id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'reference_images' => $g->reference_images,
            'metadata' => ['generation_uuid' => $g->uuid],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => Pricing::defaultTable()['rates'],
            'error' => null,
            'created_at' => now()->toIso8601String(),
            'started_at' => null,
            'completed_at' => null,
        ], $extra);
    }

    private function succeeded(Generation $g): array
    {
        return $this->remote($g, 'succeeded', [
            'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
            'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2112],
            'started_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    private function sendWebhook(string $eventId, string $type, array $object, ?string $secret = null)
    {
        $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['object' => $object]]);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, $secret ?? self::SECRET),
        ], $body);
    }

    private function createGeneration(User $user, string $prompt = 'a red fox'): Generation
    {
        Queue::fake();
        $this->actingAs($user)->post('/generations', ['prompt' => $prompt, 'size' => '1024x1024', 'quality' => 'medium'])->assertRedirect('/history');

        return Generation::latest('id')->firstOrFail();
    }

    // 12*5 + 1100*40 + 1000*12.5 = 60 + 44000 + 12500 = 56560 per 1M => $0.056560 cost, $0.0707 price
    private const COST = '0.056560000000';

    private const PRICE = '0.070700000000';

    public function test_store_holds_max_estimate_and_queues_submission(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        Queue::assertPushed(SubmitGeneration::class);
        $this->assertSame(Generation::PENDING, $g->status);
        $this->assertSame(Money::fromUsd('10') - $g->hold_picos, $user->fresh()->balance_picos);
    }

    public function test_cannot_submit_without_enough_credits(): void
    {
        $user = $this->userWithCredits('0.01');
        Queue::fake();

        $this->actingAs($user)->post('/generations', ['prompt' => 'x', 'size' => '1536x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_success_charges_exact_actual_price_once_even_with_duplicate_webhooks(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $this->sendWebhook('evt_1', 'generation.succeeded', $this->succeeded($g))->assertNoContent();
        $this->sendWebhook('evt_1', 'generation.succeeded', $this->succeeded($g))->assertNoContent(); // same event again
        $this->sendWebhook('evt_2', 'generation.succeeded', $this->succeeded($g))->assertNoContent(); // different event, same final state

        $g->refresh();
        $this->assertSame(Generation::SUCCEEDED, $g->status);
        $this->assertSame(self::COST, Money::toUsdString($g->provider_cost_picos));
        $this->assertSame(self::PRICE, Money::toUsdString($g->price_picos));
        $this->assertSame(1, CreditTransaction::where('generation_id', $g->id)->where('type', 'charge')->count());
        $this->assertSame(Money::fromUsd('10') - $g->price_picos, $user->fresh()->balance_picos);
    }

    public function test_out_of_order_events_never_move_backwards(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $this->sendWebhook('evt_s', 'generation.succeeded', $this->succeeded($g));
        $this->sendWebhook('evt_p', 'generation.processing', $this->remote($g, 'processing', ['started_at' => now()->toIso8601String()]));

        $this->assertSame(Generation::SUCCEEDED, $g->fresh()->status);
    }

    public function test_webhook_before_post_response_is_matched_by_metadata(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);
        $this->assertNull($g->pixelforge_id);

        $this->sendWebhook('evt_p', 'generation.processing', $this->remote($g, 'processing'));

        $g->refresh();
        $this->assertSame('gen_'.$g->id, $g->pixelforge_id);
        $this->assertSame(Generation::PROCESSING, $g->status);
    }

    public function test_failed_generation_is_free(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $this->sendWebhook('evt_f', 'generation.failed', $this->remote($g, 'failed', ['error' => ['code' => 'generation_failed', 'message' => 'nope'], 'completed_at' => now()->toIso8601String()]));
        $this->sendWebhook('evt_f2', 'generation.succeeded', $this->succeeded($g)); // bogus late success is ignored

        $g->refresh();
        $this->assertSame(Generation::FAILED, $g->status);
        $this->assertNull($g->price_picos);
        $this->assertSame(Money::fromUsd('10'), $user->fresh()->balance_picos);
    }

    public function test_bad_or_stale_signature_is_rejected(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $this->sendWebhook('evt_x', 'generation.succeeded', $this->succeeded($g), 'whsec_wrong')->assertStatus(400);

        $body = json_encode(['id' => 'evt_y', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeeded($g)]]);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, self::SECRET, time() - 600),
        ], $body)->assertStatus(400);

        $this->assertSame(Generation::PENDING, $g->fresh()->status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_submit_job_uses_idempotency_key_and_applies_response(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        Http::fake(['*/v1/generations' => Http::response($this->remote($g, 'queued'), 202)]);
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $g->uuid) && $r['metadata']['generation_uuid'] === $g->uuid);
        $this->assertSame(Generation::QUEUED, $g->fresh()->status);
    }

    public function test_content_policy_rejection_releases_hold(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user, '[blocked] x');

        Http::fake(['*/v1/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'blocked']], 400)]);
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $this->assertSame(Generation::FAILED, $g->fresh()->status);
        $this->assertSame(Money::fromUsd('10'), $user->fresh()->balance_picos);
    }

    public function test_local_give_up_is_corrected_if_pixelforge_did_create_it(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);
        app(GenerationSync::class)->failLocally($g, 'submission_failed', 'busy');
        $this->assertSame(Money::fromUsd('10'), $user->fresh()->balance_picos);

        $this->sendWebhook('evt_s', 'generation.succeeded', $this->succeeded($g));

        $g->refresh();
        $this->assertSame(Generation::SUCCEEDED, $g->status);
        $this->assertSame(Money::fromUsd('10') - $g->price_picos, $user->fresh()->balance_picos);
    }
}
