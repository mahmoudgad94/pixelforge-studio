<?php

namespace Tests\Feature\Pixelforge;

use App\Http\Controllers\GenerationController;
use App\Jobs\SubmitGeneration;
use App\Mcp\AdminServer;
use App\Mcp\Tools\GetGenerationTimeline;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\PixelforgeActivity;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_markup_comes_from_config_and_is_frozen_on_each_generation(): void
    {
        $user = $this->userWithCredits();
        $quotedAt25 = $this->createGeneration($user);
        $this->assertSame(25, $quotedAt25->markup_percent);

        // The setting changes while that image is still running.
        config(['services.pixelforge.markup_percent' => 50]);
        $this->app->forgetInstance(Pricing::class);
        $quotedAt50 = $this->createGeneration($user, 'a blue fox');
        $this->assertSame(50, $quotedAt50->markup_percent);

        $this->sendWebhook('evt_25', 'generation.succeeded', $this->succeeded($quotedAt25))->assertNoContent();
        $this->sendWebhook('evt_50', 'generation.succeeded', $this->succeeded($quotedAt50))->assertNoContent();

        $this->assertSame(self::PRICE, Money::toUsdString($quotedAt25->fresh()->price_picos)); // cost + 25%
        $this->assertSame('0.084840000000', Money::toUsdString($quotedAt50->fresh()->price_picos)); // cost + 50%
    }

    public function test_the_generate_page_shows_the_configured_markup(): void
    {
        config(['services.pixelforge.markup_percent' => 40]);
        $this->app->forgetInstance(Pricing::class);

        $this->actingAs($this->userWithCredits())->get('/generate')
            ->assertInertia(fn ($page) => $page->where('markupPercent', 40));
        $this->get('/')->assertInertia(fn ($page) => $page->where('markupPercent', 40));
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

    /**
     * Pixelforge accepted the request but our side lost the answer (crash or DB error right after
     * the POST): the row is still pending with no Pixelforge id. The webhook still finds it by our
     * own uuid in metadata, settles the charge once, and a later retry of the lost job does nothing.
     */
    public function test_accepted_but_not_recorded_then_webhook_arrives_charges_once(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);
        $this->assertNull($g->pixelforge_id);

        $this->sendWebhook('evt_lost_1', 'generation.succeeded', $this->succeeded($g));

        $g->refresh();
        $this->assertSame(Generation::SUCCEEDED, $g->status);
        $this->assertSame('gen_'.$g->id, $g->pixelforge_id);
        $this->assertSame(Money::fromUsd('10') - $g->price_picos, $user->fresh()->balance_picos);

        // The reconciler or the queue retries the lost job: it sees the generation is no longer pending.
        Http::fake();
        $this->travel(6)->minutes();
        $this->artisan('generations:reconcile')->assertSuccessful();
        Queue::assertPushed(SubmitGeneration::class, 1); // only the original submit, nothing re-dispatched
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));
        Http::assertNothingSent();
        $this->assertSame(Money::fromUsd('10') - $g->price_picos, $user->fresh()->balance_picos);
        $this->assertSame(1, Generation::count());
    }

    /**
     * Same loss, but no webhook yet: after 5 minutes the reconciler sends it again with the same
     * Idempotency-Key, Pixelforge replays the original generation (no second image, no second
     * charge), and the webhook then settles it once.
     */
    public function test_accepted_but_not_recorded_is_recovered_by_the_reconciler_without_a_duplicate(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $this->travel(6)->minutes();
        $this->artisan('generations:reconcile')->assertSuccessful();
        Queue::assertPushed(SubmitGeneration::class, 2); // the original submit and the reconciler re-dispatch

        Http::fake(['*/v1/generations' => Http::response($this->remote($g, 'processing'), 200, ['Idempotent-Replayed' => 'true'])]);
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $g->uuid));

        $this->sendWebhook('evt_lost_2', 'generation.succeeded', $this->succeeded($g));
        $this->sendWebhook('evt_lost_2', 'generation.succeeded', $this->succeeded($g)); // duplicate delivery

        $g->refresh();
        $this->assertSame(Generation::SUCCEEDED, $g->status);
        $this->assertSame(Money::fromUsd('10') - $g->price_picos, $user->fresh()->balance_picos);
        $this->assertSame(1, Generation::count());
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

    public function test_ping_is_acknowledged_with_2xx_and_changes_nothing(): void
    {
        $body = json_encode(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => ['object' => null]]);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, self::SECRET),
        ], $body)->assertNoContent();

        // A ping without an id (shape not guaranteed) is still acknowledged, never redirected.
        $bare = json_encode(['type' => 'ping']);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($bare, self::SECRET),
        ], $bare)->assertNoContent();

        $this->assertSame(0, Generation::count());
    }

    public function test_webhook_endpoint_never_redirects_even_for_a_signed_in_browser_or_bad_input(): void
    {
        $user = $this->userWithCredits();
        $this->actingAs($user);

        foreach (['', 'not json', json_encode(['id' => 'evt_z'])] as $body) {
            $status = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, self::SECRET),
            ], $body)->getStatusCode();
            $this->assertTrue($status < 300 || $status >= 400, "redirected with {$status}");
        }
    }

    public function test_final_failure_without_completed_at_still_gets_a_completion_time(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $this->sendWebhook('evt_f', 'generation.failed', $this->remote($g, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'oops']]));

        $this->assertNotNull($g->fresh()->completed_at);
    }

    /** @return array<string, array{int, string, string}> */
    public static function permanentErrors(): array
    {
        return [
            'daily limit' => [429, 'daily_limit_exceeded', 'rate_limit_error'],
            'bad key' => [401, 'invalid_api_key', 'authentication_error'],
            'validation' => [422, 'validation_failed', 'invalid_request_error'],
            'key reused' => [409, 'idempotency_key_reused', 'idempotency_error'],
        ];
    }

    #[DataProvider('permanentErrors')]
    public function test_permanent_errors_are_not_retried_and_cost_nothing(int $status, string $code, string $type): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        Http::fake(['*/v1/generations' => Http::response(['error' => ['type' => $type, 'code' => $code, 'message' => 'no']], $status, ['Retry-After' => '5'])]);
        $job = (new SubmitGeneration($g->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $job->assertNotReleased();
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/v1/generations'))); // one create, no retry
        $this->assertSame(Generation::FAILED, $g->fresh()->status);
        $this->assertSame($code, $g->fresh()->error_code);
        $this->assertSame(Money::fromUsd('10'), $user->fresh()->balance_picos);
    }

    /** @return array<string, array{int, ?string}> */
    public static function temporaryErrors(): array
    {
        return [
            'rate limit' => [429, 'rate_limit_exceeded'],
            'concurrency' => [429, 'concurrency_limit_exceeded'],
            'overloaded' => [503, 'overloaded'],
            'provider 500' => [500, 'internal_error'],
            'bare 502' => [502, null],
        ];
    }

    #[DataProvider('temporaryErrors')]
    public function test_temporary_errors_are_retried_after_retry_after(int $status, ?string $code): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        $body = $code ? ['error' => ['type' => 'x', 'code' => $code, 'message' => 'later']] : 'Bad gateway';
        Http::fake(['*/v1/generations' => Http::response($body, $status, ['Retry-After' => '7'])]);
        $job = (new SubmitGeneration($g->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $job->assertReleased();
        $this->assertSame(Generation::PENDING, $g->fresh()->status);
    }

    public function test_idempotency_conflict_is_not_final_if_pixelforge_reports_the_generation(): void
    {
        $user = $this->userWithCredits();
        $g = $this->createGeneration($user);

        Http::fake(['*/v1/generations' => Http::response(['error' => ['type' => 'idempotency_error', 'code' => 'idempotency_key_reused', 'message' => 'reused']], 409)]);
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));
        $this->assertSame(Generation::FAILED, $g->fresh()->status);

        $this->sendWebhook('evt_s', 'generation.succeeded', $this->succeeded($g));

        $g->refresh();
        $this->assertSame(Generation::SUCCEEDED, $g->status);
        $this->assertSame(Money::fromUsd('10') - $g->price_picos, $user->fresh()->balance_picos);
    }

    public function test_a_user_cannot_queue_more_than_the_in_flight_cap(): void
    {
        $user = $this->userWithCredits('100');
        Queue::fake();
        for ($i = 0; $i < GenerationController::MAX_IN_FLIGHT; $i++) {
            $this->actingAs($user)->post('/generations', ['prompt' => "fox {$i}", 'size' => '1024x1024', 'quality' => 'low'])->assertSessionHasNoErrors();
        }

        $balance = $user->fresh()->balance_picos;
        $this->actingAs($user)->post('/generations', ['prompt' => 'one too many', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(GenerationController::MAX_IN_FLIGHT, Generation::count());
        $this->assertSame($balance, $user->fresh()->balance_picos);
    }

    public function test_every_call_and_webhook_is_logged_and_shown_on_the_timeline_web_mcp_and_api(): void
    {
        $user = $this->userWithCredits();
        $user->forceFill(['is_admin' => true, 'mcp_token_hash' => hash('sha256', 'pfs_admin')])->save();
        $g = $this->createGeneration($user);

        Http::fake(['*/v1/generations' => Http::sequence()
            ->push(['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'busy']], 429, ['Retry-After' => '5'])
            ->push($this->remote($g, 'queued'), 202)]);
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationSync::class));

        $headers = ['HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '2', 'REMOTE_ADDR' => '203.0.113.7'];
        $body = json_encode(['id' => 'evt_9', 'object' => 'event', 'type' => 'generation.succeeded', 'created_at' => now()->toIso8601String(), 'data' => ['object' => $this->succeeded($g)]]);
        foreach ([1, 2] as $_) {
            $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers + ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, self::SECRET)], $body)->assertNoContent();
        }

        $log = PixelforgeActivity::where('generation_id', $g->id)->orderBy('id')->get();
        $this->assertSame(['retryable_error', 'ok', 'processed', 'duplicate'], $log->pluck('outcome')->all());
        $this->assertSame('concurrency_limit_exceeded', $log[0]->error_code);
        $this->assertSame([1, 2], [$log[0]->attempt, $log[1]->attempt]);
        $this->assertSame('203.0.113.7', $log[2]->ip);
        $this->assertNull($log[0]->ip); // faked API responses carry no server IP; real ones store curl's primary_ip
        $this->assertSame(2, $log[2]->attempt);
        $this->assertStringContainsString('-> succeeded', $log[2]->detail);

        $this->actingAs($user)->get("/admin/generations/{$g->id}")->assertInertia(fn ($page) => $page
            ->component('Admin/Generation')->has('activity', 4)->where('summary.duplicates_ignored', 1)->where('summary.sender_ips', ['203.0.113.7']));

        $this->withToken('pfs_admin')->getJson("/api/admin/generations/gen_{$g->id}")->assertOk()->assertJsonPath('summary.webhooks', 2);
        $this->withToken('pfs_admin')->getJson('/api/admin/report?start_date='.now()->toDateString().'&end_date='.now()->toDateString())->assertOk()->assertJsonPath('totals.generations', 1);
        $this->withToken('pfs_admin')->getJson('/api/admin/report/days/'.now()->utc()->toDateString())->assertOk()->assertJsonCount(1, 'generations');
        $this->withToken('pfs_admin')->getJson('/api/admin/report/days/2026-02-31')->assertNotFound();
        $this->flushHeaders()->getJson("/api/admin/generations/{$g->id}")->assertUnauthorized();

        AdminServer::actingAs($user)->tool(GetGenerationTimeline::class, ['id' => (string) $g->id])
            ->assertOk()->assertStructuredContentPath('summary.api_calls', 2);
    }
}
