<?php

namespace Tests\Feature\Pixelforge;

use App\Mcp\AdminServer;
use App\Mcp\Tools\FindGenerations;
use App\Mcp\Tools\GetSpendReport;
use App\Mcp\Tools\GetUserCredits;
use App\Mcp\Tools\RequestCreditAdjustment;
use App\Models\AdminAction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*/v1/pricing' => Http::response([], 500), '*/v1/usage*' => Http::response(['data' => []])]);
    }

    public function test_endpoint_requires_an_admin_token(): void
    {
        $this->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->withToken('pfs_wrong')->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

        $customer = User::factory()->create(['mcp_token_hash' => hash('sha256', 'pfs_customer')]);
        $this->withToken('pfs_customer')->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_admin_token_lists_tools(): void
    {
        User::factory()->create(['is_admin' => true, 'mcp_token_hash' => hash('sha256', 'pfs_admin')]);

        $this->withToken('pfs_admin')
            ->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertSee('request-credit-adjustment');
    }

    public function test_read_tools(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'ana@example.com', 'balance_picos' => Money::fromUsd('3')]);

        AdminServer::actingAs($admin)->tool(GetUserCredits::class, ['email' => 'ana@example.com'])
            ->assertOk()->assertStructuredContentPath('balance', '$3.00');
        AdminServer::actingAs($admin)->tool(FindGenerations::class, ['email' => 'ana@example.com'])->assertOk();
        AdminServer::actingAs($admin)->tool(GetSpendReport::class, [])->assertOk();
    }

    public function test_write_tool_only_creates_a_request_until_a_human_approves(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'ana@example.com', 'balance_picos' => Money::fromUsd('1')]);

        AdminServer::actingAs($admin)->tool(RequestCreditAdjustment::class, [
            'email' => 'ana@example.com', 'amount_usd' => '2.50', 'reason' => 'Refund for a broken image',
        ])->assertOk()->assertStructuredContentPath('status', 'pending');

        $this->assertSame(Money::fromUsd('1'), $user->fresh()->balance_picos); // unchanged

        $action = AdminAction::firstOrFail();
        $this->actingAs($admin)->post("/admin/approvals/{$action->id}/approve")->assertRedirect();
        $this->assertSame(Money::fromUsd('3.5'), $user->fresh()->balance_picos);

        // approving twice does nothing
        $this->actingAs($admin)->post("/admin/approvals/{$action->id}/approve");
        $this->assertSame(Money::fromUsd('3.5'), $user->fresh()->balance_picos);
    }

    public function test_rejected_and_expired_requests_change_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'ana@example.com', 'balance_picos' => Money::fromUsd('1')]);

        foreach (['2', '3'] as $amount) {
            AdminServer::actingAs($admin)->tool(RequestCreditAdjustment::class, ['email' => 'ana@example.com', 'amount_usd' => $amount, 'reason' => 'goodwill credit']);
        }
        [$first, $second] = AdminAction::orderBy('id')->get();

        $this->actingAs($admin)->post("/admin/approvals/{$first->id}/reject");
        $second->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($admin)->post("/admin/approvals/{$second->id}/approve");

        $this->assertSame(Money::fromUsd('1'), $user->fresh()->balance_picos);
        $this->assertSame(['rejected', 'expired'], AdminAction::orderBy('id')->pluck('status')->all());
    }

    public function test_cannot_push_a_balance_below_zero_and_non_admins_cannot_approve(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'ana@example.com', 'balance_picos' => Money::fromUsd('1')]);
        AdminServer::actingAs($admin)->tool(RequestCreditAdjustment::class, ['email' => 'ana@example.com', 'amount_usd' => '-5', 'reason' => 'chargeback']);
        $action = AdminAction::firstOrFail();

        $this->actingAs($user)->post("/admin/approvals/{$action->id}/approve")->assertForbidden();
        $this->actingAs($admin)->post("/admin/approvals/{$action->id}/approve");

        $this->assertSame(Money::fromUsd('1'), $user->fresh()->balance_picos);
        $this->assertSame('pending', $action->fresh()->status);
    }
}
