<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\AdminServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth discovery (/.well-known/oauth-*) and dynamic client registration (/oauth/register),
// backed by Passport, so Claude can connect by URL and sign in as an admin.
Mcp::oauthRoutes();

// Admin MCP server: read tools + approval-gated write tools.
// Auth: an OAuth access token, or a static token from: php artisan mcp:token you@example.com
Mcp::web('/mcp/admin', AdminServer::class)->middleware([AuthenticateMcpToken::class, 'throttle:60,1']);
