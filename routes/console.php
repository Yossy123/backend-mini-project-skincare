<?php

use App\Services\ShipmentTrackingSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Expire unpaid orders past the payment window (aligns with the 24h Midtrans expiry).
Schedule::command('orders:expire-pending')->hourly();

// Best-effort telemetry refresh for in-flight shipments (Biteship webhook remains the primary source).
Schedule::call(fn () => app(ShipmentTrackingSyncService::class)->syncActiveShipments())
    ->everyThirtyMinutes()
    ->name('shipment-tracking-sync')
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
