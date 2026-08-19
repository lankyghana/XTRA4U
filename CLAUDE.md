# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

XTRA4U is a multi-vendor digital services marketplace for the Ghanaian market (Richprime Services). Vendors sell mobile data bundles, airtime, utility payments, AFA (Agricultural Finance Authority) registrations, and exam result-checker PINs. Built as a monolithic Laravel 12.x / PHP 8.2+ application with Blade templating, Tailwind CSS 4, Alpine.js, and Vite.

## Commands

### First-Time Setup
```bash
composer run setup
# Runs: composer install → .env copy → key:generate → migrate → npm install → npm run build
```

### Development (all-in-one)
```bash
composer run dev
# Starts concurrently: php artisan serve | queue:listen --tries=1 | pail --timeout=0 | npm run dev
```

### Individual Dev Servers
```bash
npm run dev        # Vite HMR (bound to 127.0.0.1)
npm run build      # Production asset build
```

### Testing
```bash
composer run test
# Equivalent to: php artisan config:clear && php artisan test
# Uses SQLite in-memory. Seed credentials: admin@xtra4u.com / password
```

### Code Style
```bash
vendor/bin/pint    # Laravel Pint PHP formatter
```

### Custom Artisan Commands
```bash
php artisan xtra4u:ensure-admin {email} --password=   # Create/update admin
php artisan xtra4u:cancel-stale-withdrawals            # Cancel stuck withdrawal requests
php artisan xtra4u:cleanup-pending-payments            # Purge abandoned payment sessions
php artisan xtra4u:retry-stuck-withdrawals             # Retry failed payouts
php artisan migrate:fresh-no-transaction               # Shared-hosting safe DB reset (avoids DDL transactions)
```

## Architecture

### Three Auth Realms
Each has its own Eloquent model and middleware — do not confuse them:

| Role | Model | Guard/Middleware | Prefix |
|------|-------|-----------------|--------|
| Customer | None (public) | — | `/`, `/store/{vendor_code}`, `/checkout` |
| Vendor | `App\Models\Vendor` | `vendor.approved` | `/vendor/` |
| Admin | `App\Models\Admin` | `admin.only` | `/admin/` |

### Payment Gateway System
The active payment gateway is entirely database-driven via `payment_gateway_configs`. Switching providers requires no code changes — use the admin UI at `/admin/payment-gateways`. `GatewayManager` reads this config at runtime and dispatches to the correct service class.

Supported gateways: Paystack, Flutterwave, Hubtel, BulkClix, Moolre. Each has a corresponding `*PaymentService` and a `Payouts/*PayoutService` in `app/Services/`.

### Order Flow
```
Customer → StorefrontController → CheckoutController
  → GatewayManager::collect()
  → Payment gateway (webhook/callback)
  → PaymentCallbackController
  → PaymentService::completeOrder()   ← heaviest file (~47KB)
  → fires OrderCompleted event
  → Listeners dispatch jobs:
      ProcessExternalFulfillment (data/airtime via XpresPortal/GigsHub/SKDataPlug)
      LogRecipientNumberUsage
      SendOrderPlacedCommunications (SMS + email)
  → WalletService credits vendor wallet
```

Queue workers are **required** — without a running worker, fulfillment, payouts, SMS, and audit logging silently queue up.

### Commission Model
Calculated by `AffiliateChainService` and snapshotted in `orders.affiliate_chain_snapshot`:
- Platform: 2% on all transactions
- Provider vendor: base price − 2%
- Reseller vendor: markup amount − 2%

### External Fulfillment
Per-vendor configuration: each vendor can set their own XpresPortal/GigsHub/SKDataPlug API keys via `vendor_settings`. Platform-level `.env` keys serve as fallback. See `app/Services/ExternalFulfillment/ExternalFulfillmentConfig.php`.

### Dynamic Configuration
- **Mail settings**: `DynamicMailServiceProvider` reads SMTP config from `settings` table at runtime.
- **Product categories**: defined in `config/storefront.php` (data, ecg, shop, results, afa).
- **Network maps**: `config/external_fulfillment.php` (DatafyHub, XpresPortal, GigsHub, SKDataPlug).

### Database
- **Dev**: SQLite (`database/database.sqlite`)
- **Test**: SQLite in-memory (`DB_CONNECTION=sqlite_testing`)
- **Prod**: MySQL 8.0+ (utf8mb4)
- **Sessions/Cache/Queues**: All database-backed
- **Migrations**: 70+ files spanning Nov 2025 – Jun 2026

### Deployment
Production uses cPanel (`.cpanel.yml`) — Laravel lives in `core/` subdirectory; public assets deploy to `/home/xtraucom/public_html/`.

## Key Gotchas

- **Result checker PINs are encrypted** — `result_checker_orders.delivered_pins` uses `Illuminate\Support\Facades\Crypt`.
- **`CHECKOUT_COMING_SOON=true`** in `.env` shows a coming-soon page at `/checkout`. Set to `false` to enable the marketplace.
- **Vendor model saving hook** — a vendor cannot have `affiliate_vendor_id` set without `is_afa_affiliate = true` (enforced at model layer).
- **Vite is forced to `127.0.0.1`** for CSP compatibility — do not change this to `0.0.0.0`.
- **Scheduled task** — `wallet:cleanup-topups` runs hourly (archives consumed wallet topups, expires abandoned ones). Register `php artisan schedule:run` as a cron (`* * * * *`).
