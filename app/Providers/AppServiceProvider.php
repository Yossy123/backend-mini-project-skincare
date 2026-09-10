<?php

namespace App\Providers;

use App\Contracts\NotificationProviderInterface;
use App\Contracts\ShippingProviderInterface;
use App\Services\BiteshipService;
use App\Services\Notifications\LogNotificationProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            NotificationProviderInterface::class,
            LogNotificationProvider::class
        );

        $this->app->bind(
            ShippingProviderInterface::class,
            BiteshipService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Brute-force protection for login/register.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        // Guest booking creation & lookup (spam prevention).
        RateLimiter::for('booking', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Biteship quota protection for public shipping lookups.
        RateLimiter::for('shipping', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
