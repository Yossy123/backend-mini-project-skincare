<?php

namespace App\Providers;

use App\Contracts\NotificationProviderInterface;
use App\Contracts\ShippingProviderInterface;
use App\Services\BiteshipService;
use App\Services\Notifications\LogNotificationProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
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
        // Password reset emails link to the storefront page that collects the new password.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token): string => config('app.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset()));

        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = config('app.frontend_url')
                .'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Atur ulang password akun '.config('app.name'))
                ->greeting('Halo '.$notifiable->name.',')
                ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
                ->action('Atur Ulang Password', $url)
                ->line("Tautan ini berlaku selama {$minutes} menit dan hanya bisa dipakai sekali.")
                ->line('Jika Anda tidak meminta ini, abaikan email ini. Password Anda tidak akan berubah.');
        });

        // Changing a password while signed in: a few attempts per minute per account.
        RateLimiter::for('password', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->user()?->id ?? $request->ip()));
        });

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

        // Inbound provider webhooks: limit forged-secret brute-force attempts.
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
