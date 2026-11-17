<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            (string) config('services.pixelforge.base_url'),
            config('services.pixelforge.key'),
        ));

        $this->app->singleton(Pricing::class, fn ($app) => new Pricing(
            $app->make(PixelforgeClient::class)->pricingTable(),
            (int) config('services.pixelforge.markup_percent'),
        ));
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a TLS-terminating proxy, keep generated URLs (e.g. OAuth metadata) on https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // OAuth for the admin MCP server (Claude connectors). Short-lived access tokens, refreshed by the client.
        Passport::tokensExpireIn(now()->addDay());
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Consent screen shown after login. Only admins may connect Claude.
        Passport::authorizationView(function (array $params) {
            $props = ['appName' => config('app.name'), 'clientName' => $params['client']->name];

            if (! $params['user']->is_admin) {
                $params['request']->session()->forget(['authToken', 'authRequest']);

                return Inertia::render('oauth/Authorize', $props + ['forbidden' => true])
                    ->toResponse($params['request'])
                    ->setStatusCode(403);
            }

            return Inertia::render('oauth/Authorize', $props + [
                'forbidden' => false,
                'scopes' => ['Read admin reports and request credit adjustments (a human must approve every adjustment)'],
                'state' => $params['request']->input('state'),
                'clientId' => (string) $params['client']->getKey(),
                'redirectHost' => parse_url((string) $params['request']->input('redirect_uri'), PHP_URL_HOST),
                'authToken' => $params['authToken'],
                'csrfToken' => csrf_token(),
            ]);
        });
    }
}
