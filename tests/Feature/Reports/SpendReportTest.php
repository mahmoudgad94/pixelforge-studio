<?php

namespace Tests\Feature\Reports;

use App\Models\Generation;
use App\Models\User;
use App\Reports\SpendReport;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
