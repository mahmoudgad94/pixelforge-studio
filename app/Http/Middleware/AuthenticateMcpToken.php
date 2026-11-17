<?php

namespace App\Http\Middleware;

use App\Mcp\McpCallLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for the admin MCP server. Accepts either:
 *  - a static token from `php artisan mcp:token` (only its SHA-256 hash is stored), or
 *  - an OAuth access token issued by Passport (how Claude connects).
 * A 401 gets a WWW-Authenticate header from laravel/mcp so clients can discover OAuth.
 * Refused requests are written to the MCP usage log too (capped per IP so a flood cannot fill the table).
 */
class AuthenticateMcpToken
{
    public function __construct(private readonly McpCallLog $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $token = $request->bearerToken();

        if (! $token) {
            return $this->refuse($request, null, 'No access token sent.', 401, ['error' => 'Unauthorized'], $startedAt);
        }

        $user = User::where('mcp_token_hash', hash('sha256', $token))->where('is_admin', true)->first()
            ?? Auth::guard('api')->user();

        if (! $user) {
            return $this->refuse($request, null, 'Token not recognised, expired or revoked.', 401, ['error' => 'Unauthorized'], $startedAt);
        }

        if (! $user->is_admin) {
            return $this->refuse($request, $user, 'Signed in, but not an admin.', 403, ['error' => 'Forbidden: only Pixelforge admins can use this MCP server.'], $startedAt);
        }

        Auth::setUser($user);

        return $next($request);
    }

    /** @param  array<string, string>  $body */
    private function refuse(Request $request, ?User $user, string $reason, int $status, array $body, int $startedAt): Response
    {
        // The JSON API shares this middleware; only the MCP endpoint is part of the MCP log.
        if ($request->is('mcp/*') && RateLimiter::attempt('mcp-refused-log:'.$request->ip(), 20, fn () => true, 60)) {
            $this->log->recordRefused($user, "{$reason} (HTTP {$status})", $startedAt);
        }

        return response()->json($body, $status);
    }
}
