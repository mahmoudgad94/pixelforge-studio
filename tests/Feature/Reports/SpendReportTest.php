<?php

namespace Tests\Feature\Reports;

use App\Mcp\AdminServer;
use App\Mcp\Tools\GetDayGenerations;
use App\Models\Generation;
use App\Models\User;
use App\Reports\SpendReport;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpendReportTest extends TestCase
{
    use RefreshDatabase;

    private const USAGE = ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2112];

    private function succeeded(string $completedAt): Generation
    {
        $user = User::factory()->create();

        return Generation::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'medium',
            'reference_images' => [], 'status' => Generation::SUCCEEDED, 'usage' => self::USAGE,
            'provider_cost_picos' => Money::fromUsd('0.05656'), 'price_picos' => Money::fromUsd('0.0707'),
            'hold_picos' => 0, 'estimated_min_price_picos' => 0, 'estimated_price_picos' => 0, 'estimated_max_price_picos' => 0, 'completed_at' => $completedAt,
        ]);
    }

    private function usageDay(string $date, array $overrides = []): array
    {
        return array_merge(['date' => $date, 'generations' => 1, 'input_text_tokens' => 12, 'input_image_tokens' => 0,
            'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'cost_usd' => '0.056560000'], $overrides);
    }

    private function day(array $report, string $date): array
    {
        return collect($report['days'])->firstWhere('date', $date);
    }

    public function test_garbage_dates_fall_back_to_the_default_range(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
        $report = app(SpendReport::class)->build('garbage', '2026-13-45');

        $this->assertSame(now()->utc()->toDateString(), $report['end']);
        $this->assertCount(14, $report['days']);
    }

    public function test_a_day_matches_only_when_count_cost_and_tokens_all_match(): void
    {
        $this->succeeded('2026-11-10 12:00:00');
        $this->succeeded('2026-11-11 12:00:00');
        $this->succeeded('2026-11-12 12:00:00');

        Http::fake(['*/v1/usage*' => Http::response(['data' => [
            $this->usageDay('2026-11-10'),
            $this->usageDay('2026-11-11', ['reasoning_tokens' => 999, 'cost_usd' => '0.056560000']), // same cost, different tokens
            // 2026-11-12 missing although we billed something
            $this->usageDay('2026-11-13', ['generations' => 0, 'input_text_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000']),
        ]])]);

        $report = app(SpendReport::class)->build('2026-11-10', '2026-11-13');

        $this->assertTrue($this->day($report, '2026-11-10')['matches']);
        $this->assertFalse($this->day($report, '2026-11-11')['matches']);
        $this->assertFalse($this->day($report, '2026-11-12')['matches']);
        $this->assertTrue($this->day($report, '2026-11-13')['matches']);
    }

    public function test_the_page_never_errors_on_bad_query_input(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/report?start=garbage&end=1')->assertOk();
    }

    public function test_a_day_lists_its_generations_with_exact_numbers_and_free_failures(): void
    {
        $ok = $this->succeeded('2026-11-10 12:00:00');
        $failed = Generation::create([...$ok->only(['user_id', 'size', 'quality', 'reference_images', 'hold_picos', 'estimated_min_price_picos', 'estimated_price_picos', 'estimated_max_price_picos']),
            'uuid' => (string) Str::uuid(), 'prompt' => 'broken', 'status' => Generation::FAILED, 'error_message' => 'nope', 'completed_at' => '2026-11-10 13:00:00']);
        $this->succeeded('2026-11-11 12:00:00'); // another day, not listed
        $admin = User::factory()->create(['is_admin' => true]);

        $json = $this->actingAs($admin)->getJson('/admin/report/days/2026-11-10')->assertOk()->json('generations');

        $this->assertSame([$failed->id, $ok->id], array_column($json, 'id'));
        $this->assertSame('0.056560000', $json[1]['cost']);
        $this->assertSame(1000, $json[1]['tokens']['reasoning_tokens']);
        $this->assertSame('0.000000000', $json[0]['cost']);
        $this->assertSame('nope', $json[0]['error']);
    }

    public function test_day_detail_is_admin_only_and_rejects_bad_dates(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/admin/report/days/2026-11-10')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/admin/report/days/2026-02-30')->assertNotFound();
    }

    public function test_the_admin_mcp_server_shows_the_same_day_detail(): void
    {
        $ok = $this->succeeded('2026-11-10 12:00:00');
        $admin = User::factory()->create(['is_admin' => true]);

        AdminServer::actingAs($admin)->tool(GetDayGenerations::class, ['date' => '2026-11-10'])
            ->assertOk()
            ->assertStructuredContentPath('count', 1)
            ->assertStructuredContentPath('generations.0.id', $ok->id)
            ->assertStructuredContentPath('generations.0.cost', '0.056560000');

        AdminServer::actingAs($admin)->tool(GetDayGenerations::class, ['date' => 'yesterday'])->assertHasErrors();
    }

    public function test_totals_per_day_and_per_user_are_aggregated_correctly(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
        $a = $this->succeeded('2026-11-10 00:00:00'); // first second of the UTC day
        $this->succeeded('2026-11-10 23:59:59');       // last second of the same day
        $this->succeeded('2026-11-11 00:00:00');
        $b = Generation::create([...$a->only(['user_id', 'size', 'quality', 'reference_images', 'hold_picos', 'estimated_min_price_picos', 'estimated_price_picos', 'estimated_max_price_picos']),
            'uuid' => (string) Str::uuid(), 'prompt' => 'p', 'status' => Generation::SUCCEEDED, 'usage' => self::USAGE,
            'provider_cost_picos' => Money::fromUsd('0.05656'), 'price_picos' => Money::fromUsd('0.0707'), 'completed_at' => '2026-11-10 08:00:00']);
        // Failed and out-of-range rows never count.
        Generation::create([...$a->only(['user_id', 'size', 'quality', 'reference_images', 'hold_picos', 'estimated_min_price_picos', 'estimated_price_picos', 'estimated_max_price_picos']),
            'uuid' => (string) Str::uuid(), 'prompt' => 'f', 'status' => Generation::FAILED, 'completed_at' => '2026-11-10 09:00:00']);
        $this->succeeded('2026-11-12 00:00:00');

        $report = app(SpendReport::class)->build('2026-11-10', '2026-11-11');

        $d10 = $this->day($report, '2026-11-10');
        $this->assertSame(3, $d10['generations']);
        $this->assertSame(Money::format(3 * Money::fromUsd('0.0707')), $d10['spend']);
        $this->assertSame(Money::toUsdString(3 * Money::fromUsd('0.05656'), 9), $d10['cost']);
        $this->assertSame(1, $this->day($report, '2026-11-11')['generations']);
        $this->assertSame(4, $report['totals']['generations']);
        $this->assertSame(Money::toUsdString(4 * Money::fromUsd('0.05656'), 9), $report['totals']['cost']);
        // user a has two rows on the 10th (a, b), others one each: 3 per-day-user rows, newest day first.
        $this->assertCount(3, $report['perUser']);
        $this->assertSame('2026-11-11', $report['perUser'][0]['date']);
        $this->assertSame(2, collect($report['perUser'])->firstWhere(fn ($r) => $r['date'] === '2026-11-10' && str_contains($r['user'], $a->user->email))['generations']);
    }

    public function test_token_sums_are_summed_in_sql_and_compared_per_day(): void
    {
        $this->succeeded('2026-11-10 12:00:00');
        $this->succeeded('2026-11-10 13:00:00');
        Http::fake(['*/v1/usage*' => Http::response(['data' => [
            $this->usageDay('2026-11-10', ['generations' => 2, 'input_text_tokens' => 24, 'output_tokens' => 2200, 'reasoning_tokens' => 2000, 'cost_usd' => '0.113120000']),
        ]])]);

        $this->assertTrue($this->day(app(SpendReport::class)->build('2026-11-10', '2026-11-10'), '2026-11-10')['matches']);
    }

    public function test_past_usage_is_cached_for_a_day_and_the_checked_time_is_shown(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => [$this->usageDay('2026-11-10')]])]);
        $this->succeeded('2026-11-10 12:00:00');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/report?start=2026-11-10&end=2026-11-10')->assertOk()->assertInertia(fn ($p) => $p->whereNot('usageCheckedAt', null));
        $this->actingAs($admin)->get('/admin/report?start=2026-11-10&end=2026-11-10')->assertOk();
        Http::assertSentCount(1);

        $this->travel(23)->hours();
        $this->actingAs($admin)->get('/admin/report?start=2026-11-10&end=2026-11-10');
        Http::assertSentCount(1);
        $this->travel(2)->hours();
        $this->actingAs($admin)->get('/admin/report?start=2026-11-10&end=2026-11-10');
        Http::assertSentCount(2);
    }

    public function test_a_range_including_today_refreshes_after_sixty_seconds(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
        $report = app(SpendReport::class);
        $today = now()->utc()->toDateString();

        $report->build($today, $today);
        $this->travel(59)->seconds();
        $report->build($today, $today);
        Http::assertSentCount(1);
        $this->travel(2)->seconds();
        $report->build($today, $today);
        Http::assertSentCount(2);
    }

    public function test_refresh_bypasses_the_cache_but_is_locked_against_spamming(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => [$this->usageDay('2026-11-10')]])]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/report?start=2026-11-10&end=2026-11-10');
        Http::assertSentCount(1);

        $this->post('/admin/report/refresh', ['start' => '2026-11-10', 'end' => '2026-11-10'])->assertRedirect('/admin/report?start=2026-11-10&end=2026-11-10');
        Http::assertSentCount(2);

        $this->post('/admin/report/refresh', ['start' => '2026-11-10', 'end' => '2026-11-10']);
        Http::assertSentCount(2); // locked: served from the fresh cache
        $this->get('/admin/report?start=2026-11-10&end=2026-11-10')->assertInertia(fn ($p) => $p->where('refreshNotice', 'throttled'));

        $this->travel(11)->seconds();
        $this->post('/admin/report/refresh', ['start' => '2026-11-10', 'end' => '2026-11-10']);
        Http::assertSentCount(3);
    }

    public function test_refresh_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/report/refresh')->assertForbidden();
    }

    public function test_a_failed_usage_call_gives_null_matches_and_is_not_cached(): void
    {
        $this->succeeded('2026-11-10 12:00:00');
        Http::fake(['*/v1/usage*' => Http::sequence()->push(['error' => 'boom'], 500)->push(['data' => [$this->usageDay('2026-11-10')]])]);
        $report = app(SpendReport::class);

        $first = $report->build('2026-11-10', '2026-11-10');
        $this->assertNotNull($first['usageError']);
        $this->assertNull($first['usageCheckedAt']);
        $this->assertNull($this->day($first, '2026-11-10')['matches']);

        $second = $report->build('2026-11-10', '2026-11-10');
        $this->assertNull($second['usageError']);
        $this->assertTrue($this->day($second, '2026-11-10')['matches']);
    }

    public function test_the_day_drill_down_paginates_at_25(): void
    {
        $first = $this->succeeded('2026-11-10 00:00:00');
        for ($i = 1; $i < 30; $i++) {
            Generation::create([...$first->only(['user_id', 'size', 'quality', 'reference_images', 'hold_picos', 'estimated_min_price_picos', 'estimated_price_picos', 'estimated_max_price_picos']),
                'uuid' => (string) Str::uuid(), 'prompt' => "p$i", 'status' => Generation::SUCCEEDED, 'usage' => self::USAGE, 'provider_cost_picos' => 1, 'price_picos' => 2,
                'completed_at' => '2026-11-10 00:00:00']);
        }
        $admin = User::factory()->create(['is_admin' => true]);

        $one = $this->actingAs($admin)->getJson('/admin/report/days/2026-11-10')->assertOk();
        $this->assertCount(25, $one->json('generations'));
        $this->assertSame(['page' => 1, 'last_page' => 2, 'per_page' => 25, 'total' => 30], $one->json('meta'));

        $two = $this->getJson('/admin/report/days/2026-11-10?page=2')->assertOk();
        $this->assertCount(5, $two->json('generations'));
        $ids = array_merge(array_column($one->json('generations'), 'id'), array_column($two->json('generations'), 'id'));
        $this->assertCount(30, array_unique($ids)); // stable order, no row twice
        // API and MCP keep the full (500 cap) list.
        $this->assertCount(30, app(SpendReport::class)->dayDetail('2026-11-10'));
    }

    public function test_the_per_user_table_paginates(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
        for ($i = 0; $i < 30; $i++) {
            $this->succeeded('2026-11-10 12:00:00');
        }
        $report = app(SpendReport::class);

        $this->assertCount(25, $report->build('2026-11-10', '2026-11-10', 1)['perUser']);
        $page2 = $report->build('2026-11-10', '2026-11-10', 2);
        $this->assertCount(5, $page2['perUser']);
        $this->assertSame(30, $page2['perUserPage']['total']);
        $this->assertCount(30, $report->build('2026-11-10', '2026-11-10')['perUser']); // API: everything
    }

    public function test_the_query_count_stays_constant_as_rows_grow(): void
    {
        Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
        $admin = User::factory()->create(['is_admin' => true]);
        $count = function (): int {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs(User::query()->where('is_admin', true)->first())->get('/admin/report?start=2026-11-01&end=2026-11-12')->assertOk();
            $this->actingAs(User::query()->where('is_admin', true)->first())->getJson('/admin/report/days/2026-11-10')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        for ($i = 0; $i < 3; $i++) {
            $this->succeeded('2026-11-10 12:00:00');
        }
        $small = $count();
        for ($i = 0; $i < 40; $i++) {
            $this->succeeded('2026-11-0'.(1 + $i % 9).' 12:00:00');
            $this->succeeded('2026-11-10 13:00:00');
        }
        $this->assertSame($small, $count());
    }
}
