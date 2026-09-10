# Production deployment checklist

## Required environment

Use a production `.env` that is not committed to source control:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
APP_URL=https://api.example.com
FRONTEND_URLS=https://www.example.com
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
MIDTRANS_ENABLED=true
MIDTRANS_IS_PRODUCTION=true
```

Set real database, Redis, Midtrans, Biteship, and `APP_KEY` values. Never run the development seeders or use placeholder credentials in production.

## Application setup

Run from the backend directory:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

The web server must point to `backend/public`, and HTTPS should be enabled for both frontend and API.

## Queue worker

The payment/shipping workflow dispatches background jobs. Keep a worker running under Supervisor, systemd, or an equivalent process manager:

```bash
php artisan queue:work redis --sleep=3 --tries=3 --timeout=120
```

After each deployment, restart workers gracefully:

```bash
php artisan queue:restart
```

Monitor failed jobs and configure alerting for worker crashes and provider failures.

## Scheduler

The scheduler expires unpaid orders hourly and synchronizes active shipments every 30 minutes. Configure the server cron to invoke Laravel every minute:

```cron
* * * * * cd /var/www/project/backend && php artisan schedule:run >> /dev/null 2>&1
```

Verify the schedule and queue worker after deployment:

```bash
php artisan schedule:list
php artisan queue:monitor redis:default
```

## Smoke test before handover

Verify health endpoint, customer login, booking, order creation, payment sandbox/production callback, shipping rate lookup, Biteship webhook signature, queue processing, and automatic pending-order expiry in a staging environment first.
