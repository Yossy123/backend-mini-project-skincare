<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureDoctor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'doctor' => EnsureDoctor::class,
        ]);

        // There is no web login page: guests are never redirected. The auth middleware otherwise builds
        // route('login') for requests without `Accept: application/json`, which does not exist (a 500).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The storefront is a separate app that talks to this API, so API routes always answer in JSON.
        // Without this, a request missing the `Accept: application/json` header gets validation errors
        // as redirects instead of 422.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
