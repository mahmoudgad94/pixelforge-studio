<?php

namespace Tests\Feature\Pixelforge;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Claude connecting to the admin MCP server with OAuth (Passport), next to the static token. */
class AdminMcpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS_LIST = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }
    }

    public function test_well_known_metadata_endpoints_respond(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/admin')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp/admin'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'));
    }

    public function test_unauthenticated_request_points_to_the_metadata(): void
    {
        $this->postJson('/mcp/admin', self::TOOLS_LIST)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/admin').'"');
    }

    public function test_admin_connects_with_the_full_oauth_flow(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $redirect = 'https://claude.ai/api/mcp/auth_callback';

        // Only Claude's (and local) callbacks may register.
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://evil.example/cb']])
            ->assertStatus(400);

        $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]])
            ->assertCreated()->json('client_id');

        $verifier = Str::random(64);
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        // Guests are sent to the login page first.
        $this->get('/oauth/authorize?'.$query)->assertRedirect(route('login'));

        $page = $this->actingAs($admin)->get('/oauth/authorize?'.$query)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('oauth/Authorize')->where('clientName', 'Claude')->where('redirectHost', 'claude.ai')->where('forbidden', false));

        $approved = $this->post('/oauth/authorize', [
            'state' => 'xyz',
            'client_id' => $clientId,
            'auth_token' => $page->viewData('page')['props']['authToken'],
        ])->assertRedirect();

        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);

        $accessToken = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
            'code' => $params['code'],
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards(); // the next request must authenticate by token alone
        $this->withToken($accessToken)->postJson('/mcp/admin', self::TOOLS_LIST)
            ->assertOk()
            ->assertSee('request-credit-adjustment');
    }

    public function test_non_admin_cannot_authorize_or_use_a_token(): void
    {
        $customer = User::factory()->create();
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', [$redirect], false);

        $this->actingAs($customer)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))->assertForbidden()->assertInertia(fn ($page) => $page->where('forbidden', true)->missing('authToken'));

        // Even a token that exists for a non-admin is refused by the MCP endpoint.
        app(ClientRepository::class)->createPersonalAccessGrantClient('Personal', 'users');
        $token = $customer->createToken('test', ['mcp:use'])->accessToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/mcp/admin', self::TOOLS_LIST)->assertForbidden();
    }

    public function test_login_returns_to_the_consent_screen_with_a_full_page_load(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $intended = url('/oauth/authorize?client_id=1');

        $this->withSession(['url.intended' => $intended])
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $intended);
    }

    public function test_static_token_still_works(): void
    {
        User::factory()->create(['is_admin' => true, 'mcp_token_hash' => hash('sha256', 'pfs_admin')]);

        $this->withToken('pfs_admin')->postJson('/mcp/admin', self::TOOLS_LIST)
            ->assertOk()
            ->assertSee('request-credit-adjustment');
    }
}
