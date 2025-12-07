# XTRA4U

A Laravel-based multi-vendor marketplace and transaction platform used by vendors, resellers, and administrators. This repository contains the application code, migrations, factories, services (payment providers, SMS, payouts), and tests.

**Quick overview:**
- **Framework:** Laravel `^12.0` (see `composer.json`)
- **PHP:** `^8.2`
- **Environment:** Uses Vite for frontend assets and standard Laravel services (queues, mail, cache).

**Notable folders:**
- `app/` : Application code (Controllers, Models, Services, Events, Listeners, Mail)
- `database/` : Migrations, factories, and seeders
- `routes/` : `web.php` and `console.php`
# XTRA4U

XTRA4U is a Laravel-based multi-vendor marketplace and transaction platform used by vendors, resellers, and administrators. The app supports payments (Paystack), mobile money payouts (MOMO), SMS notifications, and a simple storefront for digital goods (data bundles, vouchers, utility payments).

**Quick facts**
- **Laravel:** ^12.0
- **PHP:** ^8.2
- **Frontend:** Vite (JS/CSS assets)
- **Queue:** database (default) — the app uses queued jobs for notifications and background processing

**Key components**
- `app/Models` — Eloquent models including `User`, `Vendor`, `Order`, `Product`, `Transaction`, notifications
- `app/Services` — business integrations and helpers: `PaystackPaymentService`, `MomoPayoutService`, `SmsService`, `TransactionService`, `PaymentService`
- `app/Events` & `app/Listeners` — domain events and listeners for notifications (e.g., `OrderCompleted` -> `SendOrderCompletionNotification`)
- `database/migrations` & `database/factories` — schema and test data factories

## Table of Contents

- Project overview
- Requirements
- Local setup (Windows / PowerShell)
- Environment variables (detailed)
- Development workflow
- Testing
- Logging & troubleshooting
- Deployment notes
- Contributing & code style

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+ and npm
- A database supported by Laravel: MySQL, PostgreSQL, or SQLite for local testing

## Local Setup (Windows / PowerShell)

1. Clone the repository and install dependencies:

```powershell
git clone <repo-url> xtrau
cd xtrau
composer install --no-interaction --prefer-dist
```

2. Create the environment file and edit settings:

```powershell
copy .env.example .env
```

Open `.env` and set at minimum `APP_URL`, `DB_*`, and mail/third-party service credentials. For quick local setup, use SQLite:

```powershell
mkdir database
ni database\database.sqlite -ItemType File
```
Then set `DB_CONNECTION=sqlite` in `.env`.

3. Application key, migrations and seeders:

```powershell
php artisan key:generate
php artisan migrate --seed
```

4. Frontend (Vite) dev server and assets:

```powershell
npm install
npm run dev
```

5. Start the app (dev):

```powershell
php artisan serve
```

Or run the provided dev script (runs server, queue listener, pail logs, and Vite concurrently):

```powershell
composer run dev
```

## Environment variables (recommended)

The project ships with a `.env.example`. Below are commonly required variables and a short description. Add or update keys per your provider details.

- `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_KEY`, `APP_DEBUG`
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — database configuration
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` — mail delivery
- `QUEUE_CONNECTION` — e.g., `database`, `redis`

XTRA4U custom keys (examples; check `app/Services` for any additional provider requirements):
- `BULKCLIX_API_KEY`, `BULKCLIX_SENDER_ID`, `BULKCLIX_BASE_URL` — SMS service
- `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` — Paystack payment provider
- `MOMO_*` — mobile money / payout provider keys (if configured)

Important: never commit `.env` or secrets to version control.

## Development workflow & tools

- `composer setup` — convenience script to install composer/npm dependencies, create `.env`, generate app key, run migrations and build assets (may run migrations in your environment — use with caution).
- `composer run dev` — runs a development orchestrator (concurrently runs `php artisan serve`, queue listener, pail logs, vite dev server)
- `npm run dev` — starts Vite dev server
- `php artisan queue:work` or `php artisan queue:listen` — process queued jobs
- `php artisan pail` — a logging helper included in dev dependencies
- `vendor/bin/pint` (or `composer pint`) — code style/linting (Laravel Pint)

Follow this typical flow for a feature:
1. Create a feature branch from `main`.
2. Implement models/controllers/services and add/change migrations if needed.
3. Add tests (in `tests/Feature` or `tests/Unit`).
4. Run `php artisan test` locally and ensure green.
5. Open a PR with a clear description and tests attached.

## Testing

- Run the full test suite:

```powershell
php artisan test
```

- Run only feature tests:

```powershell
php artisan test --testsuite=Feature
```

- PHPUnit is available via `vendor/bin/phpunit` but `php artisan test` is preferred because it prepares the environment.

Notes:
- Use `DB_CONNECTION=sqlite` in testing, or configure a dedicated test database.
- Factories are available in `database/factories` for creating model instances in tests.

## Logging & troubleshooting

- Application logs: `storage/logs/laravel.log`
- If queues are failing: run `php artisan queue:work --tries=3` and inspect logs for exceptions
- Clear caches when config changes: `php artisan config:clear`, `php artisan cache:clear`, `php artisan route:clear`, `php artisan view:clear`

If you see permission errors on Windows check that the `storage/` and `bootstrap/cache` directories are writable by your user.

## Deployment notes

- This repository is deployable to typical PHP hosts supporting Composer and PHP 8.2. Common approaches:
	- Use a CI pipeline to run `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, and restart workers.
	- Use Laravel Forge / Envoy / GitHub Actions for automated deployments.

- Ensure production `.env` has `APP_ENV=production` and `APP_DEBUG=false`.
- Use a robust queue backend (Redis or database) and a process manager (Supervisor, systemd) to keep `php artisan queue:work` running.

## Code style & static analysis

- Use Laravel Pint for code style: `composer pint` or `vendor/bin/pint`.
- Optionally run static analysis tools such as PHPStan or Psalm if added to the project.

## Contributing

1. Fork the repo and create a branch named `feature/your-description`.
2. Write tests for new behavior and make sure existing tests pass.
3. Keep PRs small and focused; document any schema changes.

## Useful commands (quick reference)

- Install deps: `composer install`
- Generate key: `php artisan key:generate`
- Migrate & seed: `php artisan migrate --seed`
- Serve: `php artisan serve`
- Run tests: `php artisan test`
- Queue worker: `php artisan queue:work`
- Clear config cache: `php artisan config:clear`

## Where to look in the codebase

- `app/Services` — payment, payout, SMS, and transaction services
- `app/Listeners` & `app/Events` — business event handling
- `database/migrations` — DB schema
- `database/factories` — test data factories

---

If you'd like, I can now:

- commit this README update to the current branch, or
- run `php artisan test` and share the results.

Tell me which you'd prefer next.
