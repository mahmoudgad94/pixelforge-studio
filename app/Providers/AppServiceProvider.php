<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
