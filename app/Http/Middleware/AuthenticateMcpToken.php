<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for the admin MCP server. Accepts either:
 *  - a static token from `php artisan mcp:token` (only its SHA-256 hash is stored), or
 *  - an OAuth access token issued by Passport (how Claude connects).
 * A 401 gets a WWW-Authenticate header from laravel/mcp so clients can discover OAuth.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = User::where('mcp_token_hash', hash('sha256', $token))->where('is_admin', true)->first()
            ?? Auth::guard('api')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $user->is_admin) {
            return response()->json(['error' => 'Forbidden: only Pixelforge admins can use this MCP server.'], 403);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
