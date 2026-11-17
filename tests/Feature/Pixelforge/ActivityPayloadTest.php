<?php

namespace Tests\Feature\Pixelforge;

use App\Models\Generation;
use App\Models\PixelforgeActivity;
use App\Models\User;
use App\Pixelforge\ActivityPayload;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivityPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_super_secret_value';

    private const KEY = 'pf_live_super_secret_key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => self::SECRET, 'services.pixelforge.key' => self::KEY]);
        Http::preventStrayRequests();
    }

    private function client(?Generation $g = null): PixelforgeClient
    {
        return (new PixelforgeClient('https://pf.test/v1', self::KEY))->forGeneration($g?->id, 1);
    }

    private function generation(User $user, array $extra = []): Generation
    {
        $g = new Generation(array_merge([
            'uuid' => (string) Str::uuid(), 'status' => Generation::SUCCEEDED, 'prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'high', 'reference_images' => [],
            'pixelforge_id' => 'gen_1', 'estimated_price_picos' => Money::fromUsd('0.05'), 'estimated_min_price_picos' => Money::fromUsd('0.01'),
            'estimated_max_price_picos' => Money::fromUsd('0.2'), 'hold_picos' => Money::fromUsd('0.2'),
            'created_at' => now()->subMinute(), 'submitted_at' => now()->subSeconds(50), 'started_at' => now()->subSeconds(40), 'completed_at' => now()->subSeconds(10),
            'price_picos' => Money::fromUsd('0.07'), 'provider_cost_picos' => Money::fromUsd('0.05'),
        ], $extra));
        $g->user()->associate($user);
        $g->save();

        return $g;
    }

    /** A real-looking Pixelforge answer, with the internal money fields the owner must never see. */
    private function remote(): array
    {
        return [
            'id' => 'gen_1', 'object' => 'generation', 'status' => 'succeeded', 'prompt' => 'A red fox',
            'image_url' => 'https://img.test/x.png',
            'cost' => ['amount' => '0.05656', 'currency' => 'usd'],
            'billed_amount' => '0.0707',
            'rates' => ['output_per_million' => 40],
            'usage' => ['input_text_tokens' => 12, 'output_tokens' => 1100],
            'provider_margin' => 0.25,
            'metadata' => ['generation_uuid' => 'u-1', 'user_id' => '7'],
        ];
    }

    private function webhook(array $event, array $server = []): TestResponse
    {
        $body = json_encode($event);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, self::SECRET),
            'HTTP_AUTHORIZATION' => 'Bearer leaked-bearer',
            'HTTP_COOKIE' => 'session=abc',
        ], $body);
    }

    public function test_an_api_call_stores_both_bodies_and_headers_with_secrets_redacted(): void
    {
        $g = $this->generation(User::factory()->create(), ['status' => Generation::QUEUED]);
        Http::fake(['*/generations' => Http::response($this->remote() + ['api_key' => self::KEY, 'note' => 'key is '.self::KEY], 202, ['X-Api-Key' => self::KEY, 'X-Request-Id' => 'req_1'])]);

        $this->client($g)->createGeneration(['prompt' => 'A red fox', 'quality' => 'high'], 'idem-1');

        $row = PixelforgeActivity::firstOrFail();
        $this->assertSame('POST', $row->request_method);
        $this->assertSame('https://pf.test/v1/generations', $row->request_url);
        $this->assertSame(['prompt' => 'A red fox', 'quality' => 'high'], json_decode($row->request_body, true));
        $this->assertSame(ActivityPayload::REDACTED, $row->request_headers['Authorization']);
        $this->assertSame('idem-1', $row->request_headers['Idempotency-Key']);
        $this->assertSame(ActivityPayload::REDACTED, $row->response_headers['X-Api-Key']);
        $this->assertSame('req_1', $row->response_headers['X-Request-Id']);
        $this->assertSame('gen_1', json_decode($row->response_body, true)['id']);
        $this->assertSame(ActivityPayload::REDACTED, json_decode($row->response_body, true)['api_key']);
        $this->assertSame(202, $row->status_code);
        $this->assertNotNull($row->started_at);
        $this->assertTrue($row->finished_at->gte($row->started_at));

        $everything = json_encode($row->getAttributes());
        $this->assertStringNotContainsString(self::KEY, $everything);
        $this->assertStringNotContainsString(self::SECRET, $everything);
    }

    public function test_a_webhook_stores_the_incoming_headers_and_raw_body_with_the_signature_redacted(): void
    {
        $g = $this->generation(User::factory()->create(), ['status' => Generation::PROCESSING]);
        $event = ['id' => 'evt_1', 'object' => 'event', 'type' => 'generation.processing', 'data' => ['object' => $this->remote() + ['uuid' => 'x'] + ['metadata' => ['generation_uuid' => $g->uuid]]], 'signing_secret' => self::SECRET];

        $this->webhook($event, ['HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '3'])->assertNoContent();

        $row = PixelforgeActivity::where('channel', 'webhook')->firstOrFail();
        $this->assertSame('POST', $row->request_method);
        $this->assertStringEndsWith('/webhooks/pixelforge', $row->request_url);
        $this->assertSame(ActivityPayload::REDACTED, $row->request_headers['pixelforge-signature']);
        $this->assertSame(ActivityPayload::REDACTED, $row->request_headers['authorization']);
        $this->assertSame(ActivityPayload::REDACTED, $row->request_headers['cookie']);
        $this->assertSame('3', $row->request_headers['pixelforge-delivery-attempt']);
        $this->assertSame('evt_1', json_decode($row->request_body, true)['id']);
        $this->assertSame(ActivityPayload::REDACTED, json_decode($row->request_body, true)['signing_secret']);
        $this->assertNotNull($row->started_at);
        $this->assertStringNotContainsString(self::SECRET, json_encode($row->getAttributes()));
        $this->assertStringNotContainsString('leaked-bearer', json_encode($row->getAttributes()));
    }

    public function test_a_rejected_webhook_keeps_what_we_answered(): void
    {
        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => 't=1,v1=bad'], '{"id":"evt_x"}')->assertStatus(400);

        $row = PixelforgeActivity::firstOrFail();
        $this->assertSame('invalid_signature', $row->outcome);
        $this->assertSame('Invalid signature', $row->response_body);
        $this->assertSame('{"id":"evt_x"}', $row->request_body);
        $this->assertSame(ActivityPayload::REDACTED, $row->request_headers['pixelforge-signature']);
    }

    public function test_a_body_over_the_cap_is_cut_and_marked_truncated(): void
    {
        Http::fake(['*/generations/*' => Http::response(['id' => 'gen_1', 'blob' => str_repeat('é', 40000)], 200)]); // about 80 KB

        $this->client()->getGeneration('gen_1');

        $row = PixelforgeActivity::firstOrFail();
        $this->assertTrue($row->response_body_truncated);
        $this->assertSame(ActivityPayload::MAX_BODY_BYTES, strlen($row->response_body));
        $this->assertTrue(mb_check_encoding($row->response_body, 'UTF-8'));
        $this->assertFalse($row->request_body_truncated);
    }

    public function test_a_connection_error_stores_the_exception_class_and_message(): void
    {
        Http::fake(['*/generations' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        try {
            $this->client()->createGeneration(['prompt' => 'x'], 'k');
            $this->fail('expected a ConnectionException');
        } catch (ConnectionException) {
        }

        $row = PixelforgeActivity::firstOrFail();
        $this->assertSame('connection_error', $row->outcome);
        $this->assertSame(ConnectionException::class, $row->error_class);
        $this->assertSame('cURL error 28: Operation timed out', $row->error_message);
        $this->assertSame('{"prompt":"x"}', $row->request_body);
        $this->assertNull($row->response_body);
        $this->assertNotNull($row->finished_at);
    }

    public function test_the_user_page_shows_request_and_response_but_no_cost_fields_or_secrets(): void
    {
        $user = User::factory()->create();
        $g = $this->generation($user);
        Http::fake(['*/generations' => Http::response($this->remote(), 202, ['X-Api-Key' => self::KEY, 'X-RateLimit-Remaining' => '9', 'X-Request-Id' => 'req_1', 'Content-Type' => 'application/json'])]);
        $this->client($g)->createGeneration(['prompt' => 'A red fox', 'quality' => 'high', 'metadata' => ['generation_uuid' => 'u-1']], 'idem-1');
        $this->webhook(['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => ['object' => array_merge($this->remote(), ['metadata' => ['generation_uuid' => $g->uuid]])]])->assertNoContent();

        $props = $this->actingAs($user)->get("/history/{$g->id}")->assertOk()->viewData('page')['props']['generation'];
        $steps = collect($props['steps'])->keyBy('key');

        // Sent: our request; Accepted: Pixelforge's answer; the final step: the webhook.
        $sent = $steps['sent']['details'][0];
        $this->assertSame('request', $sent['part']);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('A red fox', $sent['request']['body']['value']['prompt']);
        $this->assertNull($sent['response']);
        $accepted = $steps['accepted']['details'][0];
        $this->assertSame(202, $accepted['response']['status']);
        $this->assertSame('gen_1', $accepted['response']['body']['value']['id']);
        $this->assertSame('https://img.test/x.png', $accepted['response']['body']['value']['image_url']);
        $this->assertNotNull($accepted['started_at']);
        $this->assertSame('application/json', $accepted['response']['headers']['Content-Type']);
        $this->assertSame('gen_1', $steps['succeeded']['details'][0]['request']['body']['value']['data']['object']['id']);

        $json = json_encode($props);
        foreach (['cost', 'billed', 'rates', 'usage', 'margin', 'tokens', 'output_per_million', '0.05656', '0.0707', 'generation_uuid', 'user_id', 'X-RateLimit', 'X-Api-Key', 'Authorization', 'Idempotency', 'pixelforge-signature', self::KEY, self::SECRET, 'leaked-bearer'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json, "leaked to the user: {$needle}");
        }
    }

    public function test_a_truncated_or_unparseable_body_is_not_shown_to_the_user_but_is_marked_for_the_admin(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $g = $this->generation($user);
        Http::fake(['*/generations' => Http::response(['id' => 'gen_1', 'cost' => '0.05', 'blob' => str_repeat('x', 70000)], 202)]);
        $this->client($g)->createGeneration(['prompt' => 'A red fox'], 'k');

        $accepted = collect($this->actingAs($user)->get("/history/{$g->id}")->viewData('page')['props']['generation']['steps'])->firstWhere('key', 'accepted')['details'][0];
        $this->assertFalse($accepted['response']['body']['shown']);
        $this->assertTrue($accepted['response']['body']['truncated']);
        $this->assertStringNotContainsString('0.05', json_encode($accepted));

        $this->get("/admin/generations/{$g->id}")->assertInertia(fn (Assert $page) => $page
            ->where('activity.0.advanced.response.body.truncated', true)
            ->where('activity.0.advanced.response.body.shown', true));
    }

    public function test_admin_props_include_the_full_redacted_payloads(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $g = $this->generation($admin);
        Http::fake(['*/generations' => Http::response($this->remote(), 202, ['X-Api-Key' => self::KEY, 'X-RateLimit-Remaining' => '9'])]);
        $this->client($g)->createGeneration(['prompt' => 'A red fox'], 'idem-1');
        $this->webhook(['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => ['object' => array_merge($this->remote(), ['metadata' => ['generation_uuid' => $g->uuid]])]], ['REMOTE_ADDR' => '203.0.113.7'])->assertNoContent();

        $response = $this->actingAs($admin)->get("/admin/generations/{$g->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Generation')->has('activity', 2)
            ->where('activity.0.advanced.method', 'POST')
            ->where('activity.0.advanced.request.headers.Authorization', '[redacted]')
            ->where('activity.0.advanced.request.headers.Idempotency-Key', 'idem-1')
            ->where('activity.0.advanced.request.body.value.prompt', 'A red fox')
            ->where('activity.0.advanced.response.status', 202)
            ->where('activity.0.advanced.response.headers.X-RateLimit-Remaining', '9')
            ->where('activity.0.advanced.response.headers.X-Api-Key', '[redacted]')
            ->where('activity.0.advanced.response.body.value.cost.amount', '0.05656')
            ->where('activity.0.advanced.response.body.value.usage.output_tokens', 1100)
            ->where('activity.1.advanced.ip', '203.0.113.7')
            ->where('activity.1.advanced.event_id', 'evt_1')
            ->where('activity.1.advanced.request.headers.pixelforge-signature', '[redacted]')
            ->where('activity.1.advanced.request.body.value.id', 'evt_1')
            ->whereNot('activity.0.advanced.started_at', null)
            ->whereNot('activity.0.advanced.finished_at', null));

        $json = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString(self::SECRET, $json);
    }

    public function test_logging_failures_never_break_the_call_or_the_webhook(): void
    {
        $g = $this->generation(User::factory()->create(), ['status' => Generation::QUEUED]);
        Http::fake(['*/generations/*' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 200)]);
        Schema::drop('pixelforge_activity');

        $this->assertSame('gen_1', $this->client($g)->getGeneration('gen_1')['id']);
        $this->webhook(['id' => 'evt_1', 'type' => 'ping'])->assertNoContent();
    }
}
