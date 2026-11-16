<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\AdminServer;
use Laravel\Mcp\Facades\Mcp;

// Admin MCP server: read tools + approval-gated write tools.
// Create a token with: php artisan mcp:token you@example.com
Mcp::web('/mcp/admin', AdminServer::class)->middleware([AuthenticateMcpToken::class, 'throttle:60,1']);
