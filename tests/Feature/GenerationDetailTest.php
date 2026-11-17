<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\PixelforgeActivity;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationDetailTest extends TestCase
{
    use RefreshDatabase;

    private function generation(User $user, string $status, array $extra = []): Generation
    {
        $g = new Generation(array_merge([
            'uuid' => (string) Str::uuid(),
            'status' => $status,
            'prompt' => 'A red fox in the snow, painted in watercolor with soft light',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
            'estimated_min_price_picos' => Money::fromUsd('0.01'),
            'estimated_price_picos' => Money::fromUsd('0.05'),
            'estimated_max_price_picos' => Money::fromUsd('0.2'),
            'hold_picos' => Money::fromUsd('0.2'),
        ], $extra));
        $g->user()->associate($user);
        $g->save();

        return $g;
    }

    private function succeeded(User $user, array $extra = []): Generation
    {
        $t = now()->subMinutes(10)->startOfSecond();

        return $this->generation($user, Generation::SUCCEEDED, array_merge([
            'pixelforge_id' => 'gen_'.Str::random(8),
            'created_at' => $t, 'submitted_at' => $t->copy()->addSeconds(2), 'started_at' => $t->copy()->addSeconds(7),
            'completed_at' => $t->copy()->addSeconds(37),
            'price_picos' => Money::fromUsd('0.070700000001'), 'provider_cost_picos' => Money::fromUsd('0.05656'),
            'rates' => ['secret' => 'rates'], 'usage' => ['output_tokens' => 1100],
            'image_url' => 'https://img.example.com/x.png', 'image_url_expires_at' => now()->addDay(),
        ], $extra));
    }

    public function test_the_owner_sees_the_detail_page_with_exact_prices_and_a_timeline(): void
    {
        $user = User::factory()->create();
        $g = $this->succeeded($user, ['submit_attempts' => 1]);

        $this->actingAs($user)->get("/history/{$g->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Show')
            ->where('generation.id', $g->id)
            ->where('generation.prompt', $g->prompt)
            ->where('generation.quality', 'high')
            ->where('generation.size', '1536x1024')
            ->where('generation.reference_images', ['https://example.com/a.png'])
            ->where('generation.pixelforge_id', $g->pixelforge_id)
            ->where('generation.image_url', 'https://img.example.com/x.png')
            ->where('generation.estimated_price', '$0.05')
            ->where('generation.price', '$0.0707')
            ->where('generation.price_exact', '0.070700000001')
            ->where('generation.can_download', true)
            ->where('generation.total_ms', 37000)
            ->where('generation.retries.text', null));
    }

    public function test_timeline_steps_are_built_from_the_generation_timestamps(): void
    {
        $user = User::factory()->create();
        $g = $this->succeeded($user);

        $steps = $this->actingAs($user)->get("/history/{$g->id}")->viewData('page')['props']['generation']['steps'];

        $this->assertSame(['sent', 'accepted', 'queued', 'generating', 'succeeded'], array_column($steps, 'key'));
        $this->assertSame(['done', 'done', 'done', 'done', 'done'], array_column($steps, 'state'));
        // Sent took 2 s, Accepted and Queued share a moment, Queued waited 5 s, Generating took 30 s.
        $this->assertSame([2000, 0, 5000, 30000, null], array_column($steps, 'ms'));
        $this->assertSame($g->created_at->toIso8601String(), $steps[0]['at']);
        $this->assertSame($g->completed_at->toIso8601String(), $steps[4]['at']);
    }

    public function test_a_running_generation_has_a_current_step_and_upcoming_ones(): void
    {
        $user = User::factory()->create();
        $g = $this->generation($user, Generation::QUEUED, ['pixelforge_id' => 'gen_q', 'submitted_at' => now()->subSeconds(5)]);

        $props = $this->actingAs($user)->get("/history/{$g->id}")->viewData('page')['props']['generation'];

        $this->assertSame(['done', 'done', 'current', 'upcoming', 'upcoming'], array_column($props['steps'], 'state'));
        $this->assertFalse($props['final']);
        $this->assertNull($props['total_ms']);
        $this->assertNull($props['price']);
    }

    public function test_a_failure_before_pixelforge_took_it_skips_the_later_steps_and_explains_why(): void
    {
        $user = User::factory()->create();
        $g = $this->generation($user, Generation::FAILED, [
            'error_code' => 'content_policy_violation', 'error_message' => 'Blocked by the content policy. You were not charged.',
            'completed_at' => now(), 'submit_attempts' => 1,
        ]);

        $props = $this->actingAs($user)->get("/history/{$g->id}")->viewData('page')['props']['generation'];

        $this->assertSame(['done', 'skipped', 'skipped', 'skipped', 'failed'], array_column($props['steps'], 'state'));
        $this->assertSame('Failed', $props['steps'][4]['label']);
        $this->assertSame('blocked', $props['failure_kind']);
        $this->assertSame('Blocked by the content policy. You were not charged.', $props['error']);
        $this->assertSame('$0.00', $props['price']);
    }

    public function test_retries_are_described_in_plain_words(): void
    {
        $user = User::factory()->create();
        $once = $this->succeeded($user, ['submit_attempts' => 2]);
        PixelforgeActivity::create(['generation_id' => $once->id, 'channel' => 'api', 'action' => 'POST /generations', 'outcome' => 'retryable_error', 'status_code' => 503]);
        PixelforgeActivity::create(['generation_id' => $once->id, 'channel' => 'api', 'action' => 'POST /generations', 'outcome' => 'ok', 'status_code' => 201]);
        $conn = $this->succeeded($user, ['submit_attempts' => 4]);
        PixelforgeActivity::create(['generation_id' => $conn->id, 'channel' => 'api', 'action' => 'POST /generations', 'outcome' => 'connection_error']);

        $get = fn (Generation $g) => $this->actingAs($user)->get("/history/{$g->id}")->viewData('page')['props']['generation']['retries'];

        $this->assertSame(['count' => 1, 'text' => 'Retried once after a temporary error.'], $get($once));
        $this->assertSame(['count' => 3, 'text' => 'Retried 3 times after a connection problem.'], $get($conn));
    }

    public function test_internal_and_admin_data_never_reaches_the_page(): void
    {
        $user = User::factory()->create();
        $g = $this->succeeded($user);
        PixelforgeActivity::create(['generation_id' => $g->id, 'channel' => 'webhook', 'action' => 'generation.succeeded', 'outcome' => 'processed', 'ip' => '203.0.113.9', 'detail' => 'secret-detail']);

        $json = json_encode($this->actingAs($user)->get("/history/{$g->id}")->viewData('page')['props']['generation']);

        foreach (['provider_cost', 'profit', 'margin', 'rates', 'usage', 'uuid', 'signature', '203.0.113.9', 'secret-detail', 'ip', 'idempotency', '0.05656', 'request_body', 'response_body'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "leaked: {$needle}");
        }
        $this->assertStringNotContainsString('secret', $json);
    }

    public function test_someone_elses_generation_is_not_found_and_a_guest_goes_to_login(): void
    {
        $g = $this->succeeded(User::factory()->create());

        $this->actingAs(User::factory()->create())->get("/history/{$g->id}")->assertNotFound();
        $this->actingAs(User::factory()->create())->get("/history/{$g->id}/download")->assertNotFound();

        auth()->logout();
        $this->get("/history/{$g->id}")->assertRedirect('/login');
        $this->get("/history/{$g->id}/download")->assertRedirect('/login');
    }

    public function test_history_cards_carry_the_total_time_and_download_flag(): void
    {
        $user = User::factory()->create();
        $this->succeeded($user);
        $this->generation($user, Generation::PROCESSING);

        $this->actingAs($user)->get('/history')->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.1.duration_ms', 37000)
            ->where('generations.data.1.can_download', true)
            ->where('generations.data.0.duration_ms', null)
            ->where('generations.data.0.can_download', false));
    }

    public function test_the_owner_downloads_the_image_as_an_attachment(): void
    {
        Http::fake(['img.example.com/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        $user = User::factory()->create();
        $g = $this->succeeded($user);

        $res = $this->actingAs($user)->get("/history/{$g->id}/download")->assertOk();

        $this->assertSame('PNGDATA', $res->getContent());
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString("pixelforge-{$g->id}-a-red-fox-in-the-snow.png", $res->headers->get('Content-Disposition'));
    }

    public function test_download_needs_a_succeeded_generation_with_an_image(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/history/'.$this->generation($user, Generation::FAILED)->id.'/download')->assertNotFound();
        $this->actingAs($user)->get('/history/'.$this->generation($user, Generation::PENDING)->id.'/download')->assertNotFound();
        $this->actingAs($user)->get('/history/'.$this->succeeded($user, ['image_url' => null])->id.'/download')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_an_upstream_failure_gives_a_friendly_error_not_a_500(): void
    {
        $user = User::factory()->create();
        $g = $this->succeeded($user);

        Http::fake(['img.example.com/*' => Http::response('gone', 404)]);
        $this->actingAs($user)->get("/history/{$g->id}/download")->assertStatus(502)->assertJsonPath('message', 'Could not download the image right now. Try again in a moment.');

        Http::fake(['img.example.com/*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($user)->get("/history/{$g->id}/download")->assertStatus(502);

        Http::fake(['img.example.com/*' => fn () => throw new ConnectionException('timeout')]);
        $this->actingAs($user)->get("/history/{$g->id}/download")->assertStatus(502);

        $expired = $this->succeeded($user, ['image_url_expires_at' => now()->subHour()]);
        $this->actingAs($user)->get("/history/{$expired->id}/download")->assertStatus(410)->assertJsonStructure(['message']);
    }
}
