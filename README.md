# XTRA4U

Laravel-based multi-vendor marketplace for digital services (data bundles, vouchers, utilities) with vendor/reseller flows, Paystack payments, SMS, and queued notifications.

## Stack
- Laravel ^12, PHP ^8.2
- Vite (frontend assets)
- Database queues by default (configurable)

## Features
- Vendor storefront with category → service → package → checkout
- Payments via Paystack (redirect flow)
- SMS via BulkClix (optional)
- Reseller products and affiliate-style earnings
- Events/listeners for order notifications and wallet updates

## Quick start (Windows / PowerShell)
```powershell
git clone https://github.com/lankyghana/XTRA4U.git
cd XTRA4U
composer install --no-interaction --prefer-dist
copy .env.example .env
php artisan key:generate

# DB: use sqlite for fast local setup
mkdir database
ni database\database.sqlite -ItemType File
# then set DB_CONNECTION=sqlite in .env

npm install
php artisan migrate --seed
npm run dev   # Vite dev server
php artisan serve
```

## Environment
Set these in `.env`:
- Core: `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_KEY`, `APP_DEBUG`
- DB: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- Mail: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`
- Queue: `QUEUE_CONNECTION` (e.g., `database`, `redis`)
- Payments: `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_PAYMENT_URL`
- SMS: `BULKCLIX_API_KEY`, `BULKCLIX_SENDER_ID`, `BULKCLIX_BASE_URL`

### Paystack callback
Set `APP_URL` to the public URL Paystack can reach. For local dev, use an HTTPS tunnel (ngrok) and keep `payment.callback` exposed. If you hit cURL error 60 on Windows, point PHP to a CA bundle (set `curl.cainfo` and `openssl.cafile` in `php.ini`).

## Development workflow
- `npm run dev` — Vite dev server
- `php artisan serve` — app server
- `php artisan queue:work` — process jobs
- `composer run dev` — runs server, queue worker, pail logs, and Vite together
- `composer pint` — code style (Laravel Pint)

## Testing
```powershell
php artisan test
# or
php artisan test --testsuite=Feature
```
Use sqlite for tests (`DB_CONNECTION=sqlite`) or a dedicated test DB. Factories live in `database/factories`.

## Troubleshooting
- Logs: `storage/logs/laravel.log`
- Clear caches after env/config changes:
	```powershell
	php artisan config:clear
	php artisan cache:clear
	php artisan route:clear
	php artisan view:clear
	```
- Windows perms: ensure `storage/` and `bootstrap/cache` are writable.

## Deployment notes
- Set `APP_ENV=production`, `APP_DEBUG=false`
- Build assets: `npm ci && npm run build`
- Optimize: `composer install --no-dev --optimize-autoloader`
- Run migrations: `php artisan migrate --force`
- Keep a queue worker alive (Supervisor/systemd)

## Contributing
1) Branch from `main`
2) Add tests for changes
3) Keep PRs small and documented

## Code map
- `app/Services` — Paystack, payouts, SMS, transactions
- `app/Events` / `app/Listeners` — order and notification flows
- `database/migrations` — schema
- `tests/Feature` / `tests/Unit` — automated tests
