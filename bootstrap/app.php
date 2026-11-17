<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Pixelforge can't send a CSRF token; the route verifies its HMAC signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/pixelforge']);
        $middleware->alias(['admin' => EnsureAdmin::class]);
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Unhandled errors go to Sentry when SENTRY_LARAVEL_DSN is set; nothing happens without it.
        Integration::handles($exceptions);

        // Branded error pages for people in a browser. API, MCP and webhook callers keep their JSON
        // or plain responses, and with APP_DEBUG on a 500 still shows the stack trace.
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $branded = [403, 404, 419, 503, ...(config('app.debug') ? [] : [500])];

            if (! in_array($status, $branded, true)
                || $request->expectsJson()
                || $request->is('api/*', 'mcp', 'mcp/*', 'oauth/token', 'webhooks/*')) {
                return $response;
            }

            return Inertia::render('ErrorPage', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
