# Restaurant Backend Deployment Guide

## First-time setup
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
php artisan serve
```

## Recommended production notes
- Use `QUEUE_CONNECTION=database` and run `php artisan queue:work` if you later move notifications or reports to queues.
- Use `LOG_CHANNEL=daily` with retention via `LOG_DAILY_DAYS`.
- Back up the database before running new migrations.
- Keep `APP_DEBUG=false` in production.
- Configure HTTPS and trusted proxies at the web server level.

## Useful commands
```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan db:seed --class=RestaurantDemoDataSeeder
php artisan test
```
