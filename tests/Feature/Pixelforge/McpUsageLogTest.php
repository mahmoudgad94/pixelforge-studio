<?php

namespace Tests\Feature\Pixelforge;

use App\Mcp\AdminServer;
use App\Mcp\McpCallLog;
use App\Mcp\Tools\GetUserCredits;
use App\Models\AdminAction;
use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** The MCP usage log (mcp_tool_calls) and the request history shown on Admin > Approvals and in the JSON API. */
class McpUsageLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }
    }

    private function rpc(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->withToken($token)->postJson('/mcp/admin', [
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    private function adminWithToken(): User
    {
        return User::factory()->create(['name' => 'Ada Admin', 'is_admin' => true, 'mcp_token_hash' => hash('sha256', 'pfs_admin')]);
    }

    public function test_a_tool_call_is_logged_with_user_client_tool_and_outcome(): void
    {
        $admin = $this->adminWithToken();
        User::factory()->create(['email' => 'ana@example.com']);

        $this->rpc('pfs_admin', 'get-user-credits', ['email' => 'ana@example.com'])->assertOk();

        $row = McpToolCall::sole();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('personal token', $row->client);
        $this->assertSame('get-user-credits', $row->tool);
        $this->assertSame('email=ana@example.com', $row->arguments_summary);
        $this->assertSame('ok', $row->outcome);
        $this->assertNull($row->error_message);
        $this->assertGreaterThanOrEqual(0, $row->duration_ms);
    }

    public function test_the_client_name_comes_from_the_oauth_client(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]])->assertCreated()->json('client_id');

        $verifier = Str::random(64);
        $query = http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
        $page = $this->actingAs($admin)->get('/oauth/authorize?'.$query)->assertOk();
        $approved = $this->post('/oauth/authorize', [
            'state' => 'xyz', 'client_id' => $clientId, 'auth_token' => $page->viewData('page')['props']['authToken'],
        ])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $params);
        $accessToken = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => $redirect,
            'code_verifier' => $verifier, 'code' => $params['code'],
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->rpc($accessToken, 'get-user-credits', ['email' => $admin->email])->assertOk();

        $this->assertSame('Claude', McpToolCall::sole()->client);
    }

    public function test_a_failing_call_is_logged_as_an_error_with_its_message(): void
    {
        $this->adminWithToken();

        $this->rpc('pfs_admin', 'get-user-credits', ['email' => 'nobody@example.com'])->assertOk();
        $this->rpc('pfs_admin', 'no-such-tool')->assertStatus(400);
        $this->rpc('pfs_admin', 'get-action-status', ['request_id' => 'abc'])->assertOk();

        $rows = McpToolCall::orderBy('id')->get();
        $this->assertSame(['error', 'error', 'error'], $rows->pluck('outcome')->all());
        $this->assertSame('No user with that email.', $rows[0]->error_message);
        $this->assertSame('no-such-tool', $rows[1]->tool);
        $this->assertStringContainsString('not found', $rows[1]->error_message);
        $this->assertNotEmpty($rows[2]->error_message); // validation message
    }

    public function test_calls_made_through_the_test_harness_are_logged_too(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['email' => 'ana@example.com']);

        AdminServer::actingAs($admin)->tool(GetUserCredits::class, ['email' => 'ana@example.com'])->assertOk();
        AdminServer::actingAs($admin)->tool(GetUserCredits::class, ['email' => 'missing@example.com'])->assertHasErrors();

        $this->assertSame(['ok', 'error'], McpToolCall::orderBy('id')->pluck('outcome')->all());
    }

    public function test_secrets_never_reach_the_summary_and_long_values_are_cut(): void
    {
        $admin = $this->adminWithToken();
        $secretToken = 'pfs_'.Str::random(40);

        $this->rpc('pfs_admin', 'request-credit-adjustment', [
            'email' => 'ana@example.com',
            'api_key' => 'sk-live-1234567890',
            'password' => 'hunter2',
            'reason' => "Refund. My token is {$secretToken} and header Bearer abc.def.ghi ".str_repeat('long ', 80),
        ])->assertOk();

        $summary = McpToolCall::sole()->arguments_summary;
        $this->assertStringContainsString('api_key=[hidden]', $summary);
        $this->assertStringContainsString('password=[hidden]', $summary);
        foreach (['sk-live', 'hunter2', $secretToken, 'abc.def.ghi', 'pfs_admin'] as $secret) {
            $this->assertStringNotContainsString($secret, $summary);
        }
        $this->assertLessThanOrEqual(300, mb_strlen($summary));
        $this->assertStringContainsString('…', $summary);

        // The error message is cleaned the same way.
        $error = app(McpCallLog::class);
        $error->recordCall($admin, 'x', [], 'error', "boom with Bearer {$secretToken}", hrtime(true));
        $this->assertStringNotContainsString($secretToken, McpToolCall::latest('id')->first()->error_message);
    }

    public function test_refused_requests_are_logged_without_a_tool(): void
    {
        $customer = User::factory()->create();
        User::factory()->create(['is_admin' => true, 'mcp_token_hash' => hash('sha256', 'pfs_admin')]);

        $this->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->withToken('pfs_wrong')->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

        $rows = McpToolCall::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['refused', 'refused'], $rows->pluck('outcome')->all());
        $this->assertSame([null, null], $rows->pluck('tool')->all());
        $this->assertSame([null, null], $rows->pluck('user_id')->all());
        $this->assertStringContainsString('No access token', $rows[0]->error_message);
        $this->assertStringNotContainsString('pfs_wrong', $rows[1]->error_message);
        $this->assertSame(0, McpToolCall::where('outcome', 'ok')->count());
        $this->assertNotNull($customer);
    }

    public function test_refused_logging_is_capped_so_a_flood_cannot_fill_the_table(): void
    {
        foreach (range(1, 30) as $_) {
            $this->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        }

        $this->assertSame(20, McpToolCall::count());
    }

    public function test_the_write_tool_records_which_client_asked(): void
    {
        $this->adminWithToken();
        User::factory()->create(['email' => 'ana@example.com']);

        $this->rpc('pfs_admin', 'request-credit-adjustment', ['email' => 'ana@example.com', 'amount_usd' => '2', 'reason' => 'goodwill credit'])->assertOk();

        $this->assertSame('personal token', AdminAction::sole()->client);
    }

    public function test_approvals_page_shows_pending_first_and_the_full_history_newest_first(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Admin', 'is_admin' => true]);
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $make = fn (string $status, string $reason, array $extra = []) => AdminAction::create(array_merge([
            'type' => 'credit_adjustment', 'payload' => ['user_id' => $user->id, 'email' => $user->email, 'amount_picos' => '2000000000000'],
            'reason' => $reason, 'status' => $status, 'requested_by' => $admin->id, 'client' => 'Claude', 'expires_at' => now()->addDay(),
        ], $extra));

        $approved = $make('approved', 'approved one', ['decided_by' => $admin->id, 'decided_at' => now()]);
        $rejected = $make('rejected', 'rejected one', ['decided_by' => $admin->id, 'decided_at' => now()]);
        $expired = $make('pending', 'expired one', ['expires_at' => now()->subHour()]);
        $legacy = $make('approved', 'legacy one', ['client' => null, 'decided_by' => $admin->id, 'decided_at' => now()]);
        $pending = $make('pending', 'pending one');

        $this->actingAs($admin)->get('/admin/approvals')->assertInertia(fn ($page) => $page
            ->has('pending', 1)->where('pending.0.id', $pending->id)->where('pending.0.client', 'Claude')
            ->has('history.data', 4)
            ->where('history.data.0.id', $legacy->id)->where('history.data.0.client', null)
            ->where('history.data.1.id', $expired->id)->where('history.data.1.status', 'expired')
            ->where('history.data.2.id', $rejected->id)->where('history.data.2.status', 'rejected')
            ->where('history.data.3.id', $approved->id)->where('history.data.3.decided_by', 'Ada Admin')
            ->where('history.data.3.requested_by', 'Ada Admin')->where('history.data.3.email', 'ana@example.com'));
    }

    public function test_request_history_is_paginated(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (range(1, 12) as $i) {
            AdminAction::create(['type' => 'credit_adjustment', 'payload' => ['email' => 'a@example.com', 'amount_picos' => '1'], 'reason' => "r{$i}",
                'status' => 'rejected', 'requested_by' => $admin->id, 'expires_at' => now()->addDay(),
            ]);
        }

        $this->actingAs($admin)->get('/admin/approvals')->assertInertia(fn ($page) => $page
            ->has('history.data', 10)->where('history.total', 12)->where('history.last_page', 2));
        $this->actingAs($admin)->get('/admin/approvals?history_page=2')->assertInertia(fn ($page) => $page->has('history.data', 2));
    }

    public function test_approvals_page_lists_mcp_calls_and_filters_by_tool_and_outcome(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $log = app(McpCallLog::class);
        $log->recordCall($admin, 'get-spend-report', [], 'ok', null, hrtime(true));
        $log->recordCall($admin, 'get-user-credits', ['email' => 'x@example.com'], 'error', 'No user with that email.', hrtime(true));
        $log->recordCall($admin, 'get-user-credits', ['email' => 'ana@example.com'], 'ok', null, hrtime(true));

        $this->actingAs($admin)->get('/admin/approvals')->assertInertia(fn ($page) => $page
            ->has('mcpCalls.data', 3)->where('mcpCalls.data.0.tool', 'get-user-credits')->where('mcpCalls.data.0.arguments', 'email=ana@example.com')
            ->where('mcpCalls.data.0.user', $admin->name)->where('mcpCalls.data.0.client', 'personal token')
            ->where('mcpFilters.tools', ['get-spend-report', 'get-user-credits']));

        $this->actingAs($admin)->get('/admin/approvals?tool=get-user-credits&outcome=error')->assertInertia(fn ($page) => $page
            ->has('mcpCalls.data', 1)->where('mcpCalls.data.0.error', 'No user with that email.'));

        $this->actingAs($admin)->get('/admin/approvals?outcome=bogus')->assertSessionHasErrors('outcome');
    }

    public function test_json_api_exposes_the_same_history_to_admins_only(): void
    {
        $admin = $this->adminWithToken();
        User::factory()->create(['is_admin' => false, 'mcp_token_hash' => hash('sha256', 'pfs_customer')]);
        $user = User::factory()->create(['email' => 'ana@example.com']);
        AdminAction::create(['type' => 'credit_adjustment', 'payload' => ['email' => $user->email, 'amount_picos' => '1000000000000'], 'reason' => 'ok then', 'status' => 'approved',
            'requested_by' => $admin->id, 'decided_by' => $admin->id, 'decided_at' => now(), 'client' => 'Cursor', 'expires_at' => now()->addDay()]);
        AdminAction::create(['type' => 'credit_adjustment', 'payload' => ['email' => $user->email, 'amount_picos' => '1000000000000'], 'reason' => 'no thanks', 'status' => 'rejected',
            'requested_by' => $admin->id, 'decided_by' => $admin->id, 'decided_at' => now(), 'expires_at' => now()->addDay()]);
        $this->rpc('pfs_admin', 'get-user-credits', ['email' => 'ana@example.com']);

        $this->withToken('pfs_admin')->getJson('/api/admin/requests')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.1.client', 'Cursor')
            ->assertJsonPath('data.1.decided_by', $admin->name)->assertJsonPath('meta.total', 2);
        $this->withToken('pfs_admin')->getJson('/api/admin/requests?status=approved')->assertOk()->assertJsonCount(1, 'data');

        $this->withToken('pfs_admin')->getJson('/api/admin/mcp-calls?tool=get-user-credits&outcome=ok')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.client', 'personal token')->assertJsonPath('data.0.tool', 'get-user-credits')
            ->assertJsonPath('data.0.arguments', 'email=ana@example.com')->assertJsonStructure(['data' => [['id', 'at', 'user', 'duration_ms', 'outcome']], 'meta']);
        $this->withToken('pfs_admin')->getJson('/api/admin/mcp-calls?outcome=bogus')->assertUnprocessable();

        foreach (['/api/admin/requests', '/api/admin/mcp-calls'] as $url) {
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->getJson($url)->assertUnauthorized();
            $this->withToken('pfs_customer')->getJson($url)->assertUnauthorized();
        }
    }

    public function test_non_admins_and_guests_cannot_see_the_history_page(): void
    {
        $this->get('/admin/approvals')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/approvals')->assertForbidden();
    }

    public function test_prunable_keeps_ninety_days(): void
    {
        McpToolCall::create(['client' => 'x', 'outcome' => 'ok', 'created_at' => now()->subDays(91)]);
        $recent = McpToolCall::create(['client' => 'x', 'outcome' => 'ok']);

        $this->artisan('model:prune', ['--model' => [McpToolCall::class]])->assertSuccessful();

        $this->assertSame([$recent->id], McpToolCall::pluck('id')->all());
    }
}
