<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token for the admin MCP server. Only a SHA-256 hash is stored. */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $user = $token ? User::where('mcp_token_hash', hash('sha256', $token))->where('is_admin', true)->first() : null;

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
