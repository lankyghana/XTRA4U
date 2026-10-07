# XTRA4U

XTRA4U is a multi-vendor digital-services marketplace built primarily for the Ghanaian market, operated by Richprime Services. Independent vendors sell mobile data bundles, utility (ECG) services, shop items, exam result-checker PINs and AFA registrations to customers who do not need an account. The platform collects payment through configurable gateways, settles vendor earnings into wallets, and delivers goods through manual vendor fulfillment or external fulfillment providers.

> **Source of truth.** The current code and this README are authoritative for the implementation. The older planning documents in the repository root describe earlier architecture; see [Documentation](#documentation).

## Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Technology stack](#technology-stack)
- [Roles and authorization](#roles-and-authorization)
- [Pricing authority](#pricing-authority)
- [Payment architecture](#payment-architecture)
- [Orders, transactions and wallets](#orders-transactions-and-wallets)
- [Reseller system and commissions](#reseller-system-and-commissions)
- [Fulfillment](#fulfillment)
- [Result Checker](#result-checker)
- [AFA registration](#afa-registration)
- [Utility Bills](#utility-bills)
- [USSD](#ussd)
- [Vendor platform](#vendor-platform)
- [Admin platform](#admin-platform)
- [Support chat](#support-chat)
- [CMS](#cms)
- [Notifications](#notifications)
- [Queues and scheduler](#queues-and-scheduler)
- [Security model](#security-model)
- [Data model](#data-model)
- [Project structure](#project-structure)
- [Local development](#local-development)
- [Environment configuration](#environment-configuration)
- [Testing](#testing)
- [Production deployment](#production-deployment)
- [Operations and recovery commands](#operations-and-recovery-commands)
- [Troubleshooting](#troubleshooting)
- [Documentation](#documentation)
- [Ownership and license](#ownership-and-license)

---

## Overview

| Surface | What it is |
|---|---|
| **Public marketplace** | Homepage, platform service pages (`/services/*`), vendor storefronts (`/store/{vendor_code}`), guest checkout, order-status lookup, CMS-managed pages (about, privacy, terms, FAQ, `/p/{slug}`). |
| **Vendor dashboard** (`/vendor/*`) | Products and reseller listings, orders, manual and external fulfillment, wallet, withdrawals, AFA, Result Checker, USSD subscription, quick-buy, notifications, support chat. |
| **Admin platform** (`/admin/*`) | Operational control plane: vendors, orders, transactions, withdrawals, payment gateways, payment health, Result Checker stock, USSD, settings, support inbox, CMS. |
| **Payment infrastructure** | Database-configured gateways, a centralized integrity guard, canonical order completion, and a reconciliation sweep. |
| **Fulfillment infrastructure** | Idempotent submission to external providers, webhook and polling status sync, plus manual vendor delivery. |
| **Specialized services** | Result Checker PINs, AFA registrations, USSD ordering and vendor USSD subscriptions. |

Customers are guests: no customer account model exists. Vendors and administrators authenticate through separate guards.

---

## Architecture

```
 Customer (guest)        Vendor dashboard         Admin platform
        \                      |                      /
         +---------- Controllers / form validation ---+
                               |
      +------------------------+------------------------+
      |                        |                        |
 Domain services       Payment services         Fulfillment services
 (wallet, tiers,       (GatewayManager,         (ExternalFulfillment
  support, CMS,         PaymentIntegrityGuard,   clients, synchronizer,
  result checker)       PaymentService,          ProcessExternalFulfillment)
                        PaymentReconciliation)
      |                        |                        |
      +------------- Events / queued jobs / listeners --+
                               |
                  Database (orders, transactions, wallets, ...)
                               |
        External providers: payment gateways, SMS, fulfillment APIs
```

Key principle: **money and delivery decisions live in a few central services**, not in controllers. Controllers, webhooks and the reconciler all hand their gateway evidence to the same guard and the same completion service (see [Payment architecture](#payment-architecture)).

---

## Technology stack

Versions come from `composer.json` / `package.json`; the lock files pin exact releases.

| Layer | Technology |
|---|---|
| Language / framework | PHP `^8.2` (developed on 8.4), Laravel `^12.0` |
| Templates / frontend | Blade, Tailwind CSS 4 (`@tailwindcss/vite`), Alpine.js 3 (+ collapse plugin), Axios |
| Build | Vite 7 with `laravel-vite-plugin` |
| Production database | MySQL 8.0+ (utf8mb4). Some migrations alter a MySQL `ENUM` (for example `orders.status`), so MySQL is the intended production engine. |
| Local / test database | SQLite (local default in `.env.example`; tests use in-memory `sqlite_testing`) |
| Sessions, cache, queue | Database-backed by default (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` = `database` in `.env.example`) |
| PDF | `barryvdh/laravel-dompdf` |
| Testing | PHPUnit `^12.4`, Mockery |
| Code style | Laravel Pint |
| PHP extensions needed beyond defaults | `gd` and `fileinfo` (image decoding for CMS media and support attachments); `mbstring` |

---

## Roles and authorization

Three realms, three identities. Do not conflate them.

| Realm | Identity | Guard | Access control |
|---|---|---|---|
| Customer | None (guest) | none | Public routes; orders are looked up by reference/phone. |
| Vendor | `App\Models\Vendor` | `vendor` | `vendor.approved` middleware (`EnsureVendorApproved`): not logged in redirects to vendor login; an account that is not approved is logged out with a message. Ownership checks on every record (for example `VendorPolicy`). |
| Admin | `App\Models\Admin` | `admin` | `admin.only` middleware (`AdminOnly`). |
| Admin (legacy form) | `App\Models\User` with `role = 'admin'` | `web` | Also accepted by `AdminOnly` and by the CMS gate. |

Notes on the admin model:

- `AdminOnly` accepts an `admin`-guard user **or** the default-guard user, and rejects any user whose `role` is set and is not `admin`. An `Admin` model has no `role` attribute and passes.
- Authorization policies (`SupportConversationPolicy`, `SupportAttachmentPolicy`, `UssdPlanPolicy`, `UssdSubscriptionPolicy`, `VendorPolicy`) must be evaluated for the resolved actor, not the default guard. `Admin\Concerns\InteractsWithAdminGate` does this (`Gate::forUser($this->adminUser())`).
- **CMS is stricter.** `EnsureCmsAdmin` / `CmsAdmin` accept only an `Admin` on the `admin` guard or a `User` on `web` with `role === 'admin'`, naming guards explicitly. It runs *before* implicit model binding so anonymous visitors cannot distinguish "record exists" (redirect) from "missing" (404). Anonymous gets login redirect/401; any other authenticated identity, including vendors, gets 403.
- Support chat identifies the actor with a `SupportPrincipal` built from the authenticated session, never from request input.

---

## Pricing authority

This is a core business invariant. The implementation is `App\Services\Payments\OrderPricingSnapshot`.

| Party | Authority |
|---|---|
| **Main vendor** (product owner) | Owns the product and sets its authoritative base price. The platform does not override it and does not impose a platform-wide minimum price. A low price is a legitimate business decision by its owner. |
| **Reseller** | May add a **markup** only. Cannot reduce, replace or restate the owner's base price. |
| **Customer** | Controls neither. The browser is never a price input. |

At order creation the server resolves the terms and freezes them onto the order row:

```
expected_amount = base_price (owner)  +  markup_price (reseller; 0 for a direct sale)
```

Frozen columns on `orders`: `base_price`, `markup_price`, `expected_amount`, `currency`, `pricing_snapshot_at`. The currency is `payments.currency` (`PAYMENTS_CURRENCY`, default `GHS`; the platform is single-currency).

- Price changes by the owner or reseller affect **new orders only**. Settlement of an existing order reads its frozen terms and never re-prices from live `Product` / `ResellerProduct` rows.
- Reseller listings are priced as `base + markup`, recomputed server-side. The stored `selling_price` is a denormalized convenience and is not trusted; disagreement is logged.
- Wallet purchases (vendor quick-buy) freeze `expected_amount = base + platform fee` (see [Wallet payments](#wallet-payments)).
- `products.min_base_price` exists and is used as the owner's **base price for reseller sourcing** (`min_base_price ?? price`) in the marketplace, quick-buy and `AffiliateChainService`. It is **not** a platform price floor and does not constrain what a vendor may charge.
- Historical orders created before snapshots existed have `expected_amount = NULL`. See [Legacy orders](#legacy-orders).

---

## Payment architecture

### Payment integrity

Implemented in `App\Services\Payments\PaymentIntegrityGuard`, with vocabulary in `App\Support\PaymentIntegrity` and `PaymentVerificationState`, and results in `PaymentIntegrityResult`.

**Invariant:** no order may create financial side effects (transactions, wallet credits, earnings) or reach external fulfillment until the server holds proof that a trusted source satisfied that exact order's frozen terms.

The proof is `orders.payment_integrity_status`, a separate axis from `orders.payment_status` (which answers "where is the order in its payment lifecycle").

| `payment_integrity_status` | Meaning | Settles / fulfills? |
|---|---|---|
| `pending_verification` | Terms frozen; nothing verified yet. | No |
| `verified` | Gateway independently confirmed the payment against the frozen terms. | Yes |
| `wallet_verified` | Paid by an atomic server-side vendor wallet debit. | Yes |
| `admin_confirmed` | An authenticated admin explicitly confirmed (attributed, audited). | Yes |
| `legacy_paid` | Backfill: already paid before integrity tracking existed. | Yes (already settled) |
| `mismatch` | Verification contradicted the frozen terms. Terminal for automation. | No |
| `manual_review` | Integrity cannot be proven or disproven. Fails closed. | No |
| `legacy_unverified` | Backfill: historical and unpaid. Needs a human. | No |

For a gateway payment to be stamped `verified`, **all** of the following must hold:

1. the gateway authoritatively reported success (`PaymentVerificationState::SUCCESS`);
2. verification ran against the gateway the order was created under, not whichever gateway is the current default;
3. if the provider echoes a reference, it equals the order's `payment_reference`;
4. the order's expected amount is knowable from frozen data;
5. the confirmed amount **exactly equals** `expected_amount` (decimal-safe integer-minor-unit comparison via `App\Support\Money`, never float, never `>=`);
6. the confirmed currency matches the frozen currency whenever the provider reports one;
7. the gateway transaction id and the payment reference are not already consumed by a different order.

The result records per aspect (amount, gateway, currency, reference, transaction) whether the provider **confirmed** it or it was **assumed**. A provider that does not report currency (for example Moolre) is recorded as currency-assumed rather than falsely confirmed. A provider that confirms success without an amount leaves the order in `manual_review`.

Failure never mutates money: the order keeps its amounts, is not marked paid, credits nothing, dispatches nothing, and is stamped with a terminal status and an investigable note. Failures log a structured entry and raise a throttled admin notification (`payments.integrity_alerts`).

Single-use is backed by database constraints (migration `2026_09_14_000003_add_payment_single_use_constraints_to_orders_table`). Run `php artisan payments:audit-uniqueness` before deploying that migration on a database with history; the migration aborts rather than silently skipping when duplicates exist.

`PaymentVerificationState` classifies a raw verification result into `success`, `failed`, `pending` or `unknown`. **Only an explicit terminal gateway status marks a payment failed.** Network errors, 5xx responses and malformed bodies are `unknown` and leave the record pending.

#### Legacy orders

Orders predating frozen pricing are not trusted by default. With `PAYMENTS_RECONSTRUCT_LEGACY_EXPECTED_AMOUNT=true` (default), an expected amount is reconstructed only when the pricing row the order points at still agrees with the recorded amount **and** has not been edited since the order was created. Otherwise the order is parked for manual review. Setting it to `false` sends every unsnapshotted order to a human.

#### Scope of the strict guard

The exact-amount `PaymentIntegrityGuard` applies to **`Order`** payments (checkout, `/purchase`, callback, webhooks, reconciliation, admin confirmation). The other payable types use their own verification, which refuses to fulfil when the verified amount is **less than** expected but does not apply the strict equality check:

- `AfaRegistration`, `ResultCheckerOrder`, `WalletTopup` (verify then compare `data.amount`)
- `UssdSubscription` (compares to the plan price and fails the payment on mismatch)

All five payable types share checkout-intent idempotency, gateway-bound verification and the reconciliation sweep.

### Payment completion

`App\Services\PaymentService::completeOrder()` is the single canonical financial settlement path for orders. Controllers, webhooks and the reconciler must **not** recreate settlement logic.

1. Fast idempotency check (already `paid`/`completed` returns true).
2. `maySettle()`: refuses unless `payment_integrity_status` allows settlement (logged, no mutation).
3. `DB::transaction` with `lockForUpdate()` on the order, then re-checks idempotency and integrity under the lock.
4. Dispatches to the regular, reseller or multi-level reseller settlement path.
5. Upserts the `transactions` row, sets the order to `Processing` / `paid`, credits wallets from frozen terms, creates vendor/admin notifications.
6. **After commit** (`dispatchPostCommitActions`): sends order communications (`SendOrderPlacedCommunications`, run via `dispatchSync` so no worker is required for this step, but still outside the DB transaction), then fires `OrderCompleted`.
7. `OrderCompleted` listeners log recipient numbers and dispatch external fulfillment.

### Gateways

Active gateways are database-driven (`payment_gateway_configs`, managed at `/admin/payment-gateways`). `GatewayManager` reads the default active config for each type (`payment_collection`, `payout`, `sms`) and dispatches to the matching service. Credentials are stored encrypted in the config row, not in code.

| Gateway | Collection | Payout | Webhook endpoint |
|---|---|---|---|
| Paystack | Yes | Yes | `POST /webhooks/paystack` (HMAC-SHA512 signature required) |
| Moolre | Yes | Yes | `POST /webhooks/moolre/payment` (shared secret checked when `webhook_secret` is configured) |
| Payaza | Yes | No | `POST /webhooks/payaza` (HMAC signature checked when a secret is configured; otherwise relies on the status-query API) |
| Flutterwave | Yes | Yes | none (callback / reconciliation) |
| BulkClix | Yes | Yes | none (callback / reconciliation) |
| Hubtel | **No** (collection is explicitly blocked in `GatewayManager`) | Yes | none |

BulkClix and Moolre also back SMS (`SmsService` selects `bulkclix` or `moolre`). A legacy `MomoPayoutService` also exists (`config/momo.php`). Hubtel appearing in the admin UI does not mean it can collect payments.

### Verification entry points

All of these converge on the same guard and `PaymentService::completeOrder()`:

| Path | Entry |
|---|---|
| Browser verification | `POST /checkout/verify`, `/payment/status/{reference}` polling |
| Redirect callback | `GET/POST /payment/callback`, `/purchase/callback/{token}` |
| Webhook | `/webhooks/paystack`, `/webhooks/moolre/payment`, `/webhooks/payaza` |
| Reconciliation | `payments:reconcile` (every 5 min) and `payments:cleanup` (every 6 h) via `PaymentReconciliationService` |
| Admin | "Confirm payment" on an order or transaction, stamped `admin_confirmed` |

Webhook and callback requests are hints; the verified gateway response is the evidence. The browser is never a trusted source.

### Reconciliation

`PaymentReconciliationService` finds payments a browser callback and webhook both missed. It queries each record's **own stored gateway**, classifies the result, and for a confirmed outcome invokes the same completion pipeline. It never starts a new charge or reference and never holds a DB lock across the gateway call.

- Eligible after 5 minutes of age; backoff by attempts made: 5, 10, 30, 60, 120 min, then every 4 h.
- Automatic window is 72 hours. Past that a record is parked for manual review (never cancelled on age alone) and reachable only via `payments:reconcile --reference=`.
- Outcomes: `completed`, `cancelled` (only on explicit terminal failure), `left_pending`, `no_gateway`, `integrity_mismatch`, `manual_review`.
- Covers `Order`, `AfaRegistration`, `ResultCheckerOrder`, `UssdSubscription`, `WalletTopup`.
- Writes a heartbeat to cache that the Payment Health page reads.

### Checkout idempotency

`CheckoutIntentGuard` prevents a customer's own retry from minting a second charge for the same intent. A client-supplied `idempotency_key` is paired with a server-computed `idempotency_scope` (session, vendor, or USSD dial session); a database unique index on `(idempotency_scope, idempotency_key)` is the concurrency guard. Keys alone are never trusted across scopes.

### Wallet payments

Vendor quick-buy (`/vendor/quick-buy` and the wallet branch of `PurchaseController::store`) pays with the vendor's wallet:

- Requires an authenticated vendor session (`auth('vendor')`); the paying identity is never taken from the request.
- The charge is `base price + 2% platform fee`, computed server-side and frozen as the order's `expected_amount`.
- The order is created through the idempotency guard **before** any wallet lock is taken.
- The wallet debit (`WalletService::debitVendorFromTopups`) runs under a vendor row lock. Only when the debit succeeds is the order stamped `wallet_verified` and completed through `PaymentService::completeVendorWalletOrder()`. A failed debit never marks the order paid.
- Wallet-paid orders record the transaction but do not credit vendors again (no automated credit loop).

Wallet **top-ups** go through a gateway (`WalletTopup`, `/vendor/wallet/topup`), are completed by `WalletService::completeTopup()` after verification, and are tracked in `wallet_ledgers`. Wallet purchases spend from top-up balances; earnings are the withdrawable balance (`getWithdrawableBalance`).

---

## Orders, transactions and wallets

**Order lifecycle (gateway order):**

```
order created (status Pending, payment unpaid, terms frozen, integrity pending_verification)
  -> payment initiated through the order's gateway (reference stored)
  -> independent verification (browser / callback / webhook / reconciliation)
  -> PaymentIntegrityGuard  (verified | mismatch | manual_review | unresolved)
  -> PaymentService::completeOrder  (lock, settle, transaction upsert, wallet credit)
  -> status Processing, payment_status paid
  -> OrderCompleted -> ProcessExternalFulfillment (if configured) or manual vendor delivery
  -> status Completed (provider delivery / webhook / polling, or vendor confirms)
```

`orders.status` values (MySQL enum): `Pending`, `Processing`, `Completed`, `Failed`, `Cancelled`, `Refunded`, `On Hold`, `Verifying`. External fulfillment has its own `external_fulfillment_status` (for example `processing`, `succeeded`, `delivered`, failed states).

**Relationships**

- `Order` is the customer purchase and holds the frozen financial terms.
- `Transaction` is the accounting record (polymorphic: orders, AFA registrations, Result Checker orders), carrying `amount`, `commission_amount`, `vendor_earning`, `gateway_transaction_id`.
- `Vendor.wallet_balance` holds withdrawable earnings; `WalletLedger` records wallet movements (with a `source`); `WalletTopup` records top-ups.
- Owner and reseller earnings are credited at settlement from the order's frozen split. `WalletService::reverseOrderEarnings()` supports admin refunds/reversals.
- `VendorWithdrawal` records payouts.

### Withdrawals

1. Vendor requests a withdrawal at `/vendor/wallet`. The amount is validated against the withdrawable balance (minimum 1), and re-checked under a vendor row lock.
2. The wallet is **debited up front** and a `VendorWithdrawal` is created in `processing`.
3. `ProcessVendorWithdrawalPayout` (queued, `WithoutOverlapping` per withdrawal, 60 s attempt throttle) locks the row, resolves the payout gateway and calls the provider through `GatewayManager::payout()`.
4. Statuses: `processing`, `approved`, `failed`, `cancelled`. A failure or cancellation refunds the wallet once (guarded by `refunded_at`).
5. Scheduled safety nets: `withdrawals:cancel-stale` (every minute) fails and refunds withdrawals with no payout reference after 5 minutes; `withdrawals:retry-stuck` (every 5 minutes) re-queues eligible rows. The scheduled retry closure resets rows to `pending`; the artisan command of the same name dispatches payout jobs.
6. Admin visibility: `/admin/withdrawals` with refresh and cancel actions; admin and vendor notifications on state changes.

---

## Reseller system and commissions

- A vendor can resell another vendor's resellable product (`ResellerProduct`), setting only a markup. `AffiliateChainService` resolves the upline (multi-level chains up to a depth limit) and the owner's base price.
- **Platform commission is 2%, taken separately from each party's portion**, using constants in the settlement code (`PaymentService`, `AffiliateChainService`, `AfaPaymentService`, `PurchaseController`): the owner receives `base - 2% of base`, each reseller receives `markup - 2% of markup`. Because the 2% is taken from each portion, the platform's total on a reseller sale is 2% of base plus 2% of markup, which equals 2% of the sale price. A direct sale has one portion.
- **Vendor tiers** (`VendorTier`, qualification rules, promotion workflow, history): a reseller's tier can carry a discount rate. The discount reduces the base amount credited to the owner and is added to the reseller's effective markup in the multi-level payout computation. Tiers are evaluated daily (`vendor-tiers:evaluate`, 02:00); eligibility is marked for admin promotion at `/admin/vendor-tier-promotions`.
- Reseller settlement uses the **frozen** `base_price`/`markup_price`. Fallbacks apply only to legacy orders without a snapshot and are logged loudly.
- `Vendor` has a saving hook: `affiliate_vendor_id` cannot be set unless `is_afa_affiliate = true`.

The commission rate is a literal in several places rather than a single config value. Change it everywhere or not at all.

---

## Fulfillment

### Manual fulfillment

At `/vendor/fulfillment` a vendor downloads **paid, Processing** orders grouped by network as plain text (`Number<TAB>Package`), optionally capped by a batch limit, and later marks them Completed. `orders.downloaded_at` prevents duplicate downloads. Orders sold through resellers are fulfilled by the **product owner** vendor. The vendor can also resend failed API orders. Vendors also have `orders` and `orders/affiliate` views and a status-update action.

### External fulfillment

Providers (all implement `ExternalFulfillmentClient`; configured in `config/external_fulfillment.php` and per vendor): **DatafyHub, XpresPortal, GigsHub, SKDataPlug**. Status polling is supported by GigsHub and SKDataPlug (`SupportsStatusPolling`).

**Invariant: fulfillment must not become a second payment authority.** It may only proceed after the order has passed the payment-integrity boundary.

Flow:

1. `OrderCompleted` fires after settlement. `DispatchExternalFulfillmentFromOrderCompleted` dispatches `ProcessExternalFulfillment` if the target vendor (owner, else vendor) has a ready configuration. Per-vendor keys live in `vendor_settings` (encrypted); platform `.env` keys are the fallback.
2. `ProcessExternalFulfillment` is unique per order and takes a cache lock, then **claims** the order in a `lockForUpdate` transaction that:
   - skips unless the order is paid **and** `allowsFulfillment()` (integrity status);
   - skips administratively closed orders (marker `Fulfillment closed administratively`);
   - skips orders already `succeeded`/`delivered`;
   - if a genuine provider reference already exists, switches to **recover** (look up status) instead of resubmitting;
   - otherwise stores a stable `external_fulfillment_idempotency_key` (`order-{id}` unless one exists), sets `processing`, increments attempts.
3. The HTTP call happens **outside** any DB transaction. The result is written back with a guarded conditional update that only applies if the order is still in the `processing` state this job set, so a late result cannot regress an order a webhook already resolved.
4. Provider reference, provider used and last-attempt metadata are stored on the order.
5. A confirmed duplicate response (HTTP 409 that matches a recognized duplicate signal) is treated as "already accepted": the order stays `processing` and is resolved from the provider when possible. A recipient-conflict (provider has another in-flight order for that number) holds the order and rechecks every 10 minutes for up to 144 attempts (about 24 h). An unrecognized 409 fails the order for manual review **without** a queue retry that would resubmit the same request.
6. Status sync: provider webhooks (`/webhooks/gigshub`, `/webhooks/skdataplug`; GigsHub also has `/webhooks/gigshub/balance-low`) and the scheduled `xtra4u:sync-external-fulfillment` (every 10 min) both go through `ExternalFulfillmentStatusSynchronizer`, so they apply status identically. Raw provider statuses map to `delivered` / `failed` / `processing` through `external_fulfillment.status_map`; **unrecognized statuses are ignored**, so they can never silently complete or fail an order.
7. `EXTERNAL_FULFILLMENT_AUTO_COMPLETE` (default true) lets a provider "delivered" move the order to Completed. Turn it off to require vendor confirmation.
8. Failed orders are visible to vendors (`fulfillment` page, `resend-failed-api`) and in admin Payment Health (fulfillment backlog).

Policy: processing orders are not auto-failed on age alone.

Administrative closure commands for historical orders exist (`fulfillment:close-paid-legacy`, `fulfillment:close-manual-legacy`); see [Operations and recovery commands](#operations-and-recovery-commands).

---

## Result Checker

Exam result-checker PINs, sold as a distinct product from data bundles.

- **Customer**: `/store/{vendor_code}/result-checkers`, checkout via `ResultCheckerCheckoutController`, payment callback/webhook via `ResultCheckerPaymentCallbackController`, status lookup at `/results-checker/status`. Customers see delivered PINs on the status/success page and by SMS (`SendResultCheckerDeliveryNotification`).
- **Vendor**: configures pricing and settings (`/vendor/result-checkers`, `VendorResultCheckerSetting`) and sees orders (`/vendor/result-checkers/orders`). Vendor profit is resolved by `ResultCheckerOrder::resolveVendorProfit()`; admin-defined pricing tiers (`ResultCheckerPricingTier`) and a base price exist.
- **Admin**: dashboard, PIN inventory and upload (`/admin/results-checkers/pins`), orders with retry / mark-failed / status update, pricing tiers.
- **Allocation**: `ResultCheckerService` runs payment handling, transaction creation and allocation in one DB transaction. Allocation locks the order row and the candidate `ResultCheckerPin` rows (`lockForUpdate`), takes `quantity` available PINs, marks them `sold`, links them to the order, stores them on the order and credits the vendor.
- **Stock shortage**: if too few PINs are available the order is paid but set to `pending_stock`; `fulfillPendingOrder` / `RetryResultCheckerOrder` and the admin retry action fulfill it once stock exists. Admin can also release PINs and mark an order failed.
- **At rest**: `delivered_pins_json` on the order is cast `encrypted:array`. `ResultCheckerPin.pin`/`serial` are hidden from serialization by default but are stored as ordinary columns, so treat the database as sensitive.

Result Checker is **not** AFA.

---

## AFA registration

A separate registration service (`AfaRegistration`, `AfaRegistrationController`, `VendorAfaController`, `AfaPaymentService`). The code does not define what "AFA" expands to; older docs give conflicting expansions, so this README does not assert one.

- **Customer**: `/store/{vendor_code}/afa` and `/services/afa-registration`; submits identity details (`ghana_card`, `drivers_license`, `voters_id` types), pays, and checks status at `/afa/success/{reference}` and `/afa/check-status`. Submission is rate-limited and idempotent.
- **Vendor / provider**: sets an AFA price (`afa_enabled`, `afa_price`); manages registrations at `/vendor/afa` (status updates, bulk update, stats). Registrations are fulfilled by the provider vendor (`VendorAfaFulfillmentOwnershipTest`).
- **Reseller**: `afa_reseller_enabled`, `afa_source_vendor_id`, `afa_base_price`, `afa_markup`, `afa_selling_price` (= base + markup). Restricted by affiliate rules (`Vendor` saving hook, `VendorAfaResellerProviderRestrictionTest`). Settlement splits base and markup with the 2% commission per portion (`AfaPaymentService`, `AffiliateChainService::computeAfaPayoutFromSeller`).
- **Lifecycle**: registration `status` is `pending` -> `processing` -> `approved`/`completed`, or `rejected`/`cancelled`; `payment_status` is `pending`, `completed`, `failed` or `refunded`.
- Completion fires `AfaRegistrationCompleted` (recipient-number logging). Pending AFA payments are covered by reconciliation.

---

## Utility Bills

A **global, platform-owned service** (not a vendor product): customers pay electricity, water and TV bills; **KiNG FLEXY GH** performs the bill payment. No `Product`/`ResellerProduct` rows exist for it and vendors configure nothing. Code: `app/Services/UtilityBills/*`, `UtilityBillController`, `Admin\UtilityBillSettingsController`, `Admin\UtilityBillSalesController`, `Vendor\UtilityBillSalesController`, `SubmitUtilityBillPayment`, command `utility-bills:sync`.

**Roles**

| Role | What they do |
|---|---|
| Admin | Owns everything: global on/off (+ maintenance message), per-biller enable, per-biller vendor commission (percentage or fixed GHS), all sales, recovery. Settings: `/admin/settings/utility-bills`; sales: `/admin/utility-bill-sales`. |
| Vendor | Nothing to configure. Utility Bills appears on their storefront automatically; they see their own sales, statuses and commission at `/vendor/utility-bills` (read-only, masked accounts). |
| Customer | Opens `/services/utility-bills` (direct) or `/store/{vendor_code}/utility-bills` (storefront), verifies the account, confirms, pays, tracks status at an opaque-token URL. |
| XTRA4U | Owns payment integrity, order state, vendor attribution and commission accounting. |

`/services/ecg` permanently redirects to `/services/utility-bills`. **Hierarchy:** Utility Bills is the ONE top-level service; ECG, Ghana Water, DSTV, GOtv and StarTimes are billers inside it (Admin > Utility Bills Settings). There is no ECG platform service, no vendor assignment for Utility Bills (KiNG FLEXY fulfils; the storefront vendor is only the commission owner), and no separate ECG toggle in the admin UI: Service Availability shows Utility Bills as a read-only row linking to its settings, and Platform Service Vendors lists only data/shop/results/afa. Retired categories are defined in `App\Support\SupersededCategories` (`ecg`): they are excluded from both admin lists and save handlers (so saving never alters their stored values), no NEW legacy record can be created in them (vendors cannot create products, Admin > Networks cannot create network services, and the reseller marketplace neither offers nor accepts new listings of such products; existing records can still be viewed and edited and keep their category), and the key stays for legacy compatibility only: historical ECG products/orders (`category: ecg`), the old `service_open.ecg` flag (still read by the legacy vendor-product checkout, not exposed or editable in the UI) and any stored `platform_service_vendor.ecg` value are left untouched. `ecg` is also the storefront category slot under which the Utility Bills card appears.

**Availability.** A biller takes new sales only when the **provider reports it enabled** AND the **admin enabled it** AND the **service is globally enabled**. An admin toggle can never enable a biller the provider has disabled. The service is **off until an admin enables it**, billers are off until enabled, and no commission is invented (default `percentage 0`). Disabling stops *new* sales only; paid orders keep fulfilling, syncing and recovering.

**KiNG FLEXY integration** (`KingFlexyUtilityProvider`; Commission Services API **v2**, base `https://api.kingflexygh.com/api/v2`, `Authorization: <raw key>` with no `Bearer`):

| Endpoint | Use | Provider limit |
|---|---|---|
| `GET /utilities/billers` | live biller catalog + global `min_amount`/`max_amount` (cached ~2 min) | 30/min |
| `GET /utilities/lookup` | verify the account / list ECG meters (identical lookups cached ~2 min) | 10/min |
| `POST /utilities/pay` | pay at face value; `reference` is an **idempotency key** | 6/min |
| `GET /utilities/orders/{reference}` | status, by the **provider's** `UTIL-…` reference | 30/min |

The key must be a **Commission Services key** (`kf_cs_live_…`); a normal data key is rejected by the provider with 403, and the code refuses to use any key not starting `kf_cs_`. It is read from server config only and never reaches Blade, JS, responses, logs or user-facing errors. Own per-endpoint budgets sit just under the provider limits; HTTP uses connect/request timeouts and **no automatic POST retries**. Provider min/max are global, not per biller; provider `commission_share_percent`/`commission_earned` is KiNG FLEXY → XTRA4U money, stored in `provider_commission_*` and **never** used as the vendor commission.

**Customer flow**

1. Biller list comes from the catalog (provider capabilities drive the form: phone vs account, account label, phone required).
2. Lookup is **mandatory**. The verified result is stored server-side under an unguessable token bound to the **browser session and the storefront** it was made in. The order step reads the account, name and meters only from that snapshot; the browser sends the token, which meter it picked (an opaque id) and the amount. Account/meter/biller/vendor substitution is rejected.
3. ECG looks up by phone and may return several meters. All are shown (masked number, name, outstanding); the customer must explicitly pick one (never auto-selects the first). Ghana Water collects account + phone; DSTV/GOtv/StarTimes use the account/smartcard only. A **negative** `amount_due` is shown as *account credit*, never as a debt. `amount_due` is informational: any amount within the provider's limits may be paid.
4. Checkout creates the immutable order and starts payment through the normal gateway pipeline.

**Order model.** Each sale is a normal `orders` row (carries the payment, frozen `expected_amount`, integrity proof) with **`vendor_id = NULL`**, plus a 1:1 `utility_bill_orders` row (frozen biller/account/name/phone, bill amount, attribution, commission terms, fulfillment state, both provider references). `orders.vendor_id` was made nullable for this. Consequences: gateway collection, `PaymentIntegrityGuard`, webhooks and reconciliation all work unchanged; vendor order lists, tier qualification and earnings never see these orders; no `transactions` row or vendor earning is created for them. `PaymentService::completeOrder` has one narrow branch (`completeUtilityBillOrder`) that marks the payment paid and queues fulfillment.

**Customer fee.** None: the customer pays face value (`expected_amount = bill_amount`). A gateway fee overpayment is accepted by the integrity guard as usual.

**Attribution.** Frozen at order creation from the route-bound storefront vendor (an approved vendor in the URL path); direct XTRA4U purchases have no vendor and earn no commission. Never re-derived from session/referrer/cookie/route at fulfillment. Reseller storefronts are the same `Vendor` storefront: the storefront that generated the sale receives the commission; there is no owner/reseller split and no base+markup pricing.

**Payment before fulfillment.** The provider is contacted only after the envelope order is `paid` **and** carries a trusted integrity status (`PaymentIntegrity::allowsFulfillment`), re-checked under a row lock at claim time. Underpayment, failed/pending verification, mismatches and bare `payment_status = paid` writes never fulfil.

**Fulfillment lifecycle** (`FulfillmentStatus`, separate from customer payment): `awaiting_payment → queued → submitting → provider_pending → provider_processing → completed`, with `failed`, `provider_refunded` (provider refunded *XTRA4U's provider wallet*, **not** a customer refund) and `attention` (recoverable: provider wallet empty, provider/biller disabled, key rejected). A paid order whose bill cannot be delivered stays **paid** and is surfaced to admin; nothing marks the customer payment failed or refunded. Provider statuses are mapped in one place (`ProviderStatusMapper`); unrecognised values change nothing; terminal states never regress (contradictions are logged and flagged).

**Idempotency.** (1) CLAIM under a row lock: payment re-proven, one stable `provider_request_reference` (`XU-{public_ref}-A{attempt}`) generated and **persisted before the first call**, status `submitting`, claim token. (2) SUBMIT outside any lock. (3) RECORD with a conditional update keyed on the claim token. Timeouts, 5xx, malformed responses, 429s, queue retries, stale-claim recovery and admin/scheduler retries all reuse the **same** reference, so the provider replays the original order instead of charging twice. The two references are stored separately (`provider_request_reference` = ours, `provider_order_reference` = the provider's, used for status). A **new** reference (attempt N+1) is created only by an explicit admin "start new provider attempt" and only when **all** of these hold: the customer payment is proven; the previous attempt's status is `refunded` (the provider's documented definitive failure: "auto-refunds your wallet"); a **live** status read at that moment confirms it is still `refunded` (an unreachable/unknown/different answer creates nothing); the admin gives a reason and ticks an explicit confirmation. `failed` is listed in the provider's status flow but not defined for utility bills, so it **never** permits a new reference. Timeouts, connection failures, 429, 5xx, 409, malformed responses, stale claims, queue failures and scheduler/admin retries always reuse the **same** reference. A 409 is the provider's 30-second duplicate window (a new reference does not bypass it): it is requeued after ~40 s with the same reference. Closing attempt N preserves it as a structured, append-only `attempt_closed` event (`utility_bill_events.meta`: request/provider references, provider status and reason, submitted/closed times, who started N+1 and why); the admin detail page lists every attempt. Commission belongs to the Utility Bill order, so attempt 1 refunded + attempt 2 completed pays the vendor once, and a completed attempt makes any further attempt impossible.

**Status sync.** `utility-bills:sync` runs every minute from the scheduler: re-dispatches queued/stale-claim orders, re-queues transient `attention` orders (≥15 min apart), and polls in-flight provider orders with backoff (20 s → 20 min; max 20 polls/run). The customer's browser polls only XTRA4U's local status endpoint. Orders not terminal after 2 h raise one admin alert (never auto-failed or auto-refunded).

**Vendor commission.** Set per biller by admin; **frozen on each order** at creation (type, value, basis `bill_face_value`, basis amount, computed amount), computed in integer pesewas (percent to 4 decimals, half-up; fixed is capped at the bill). Technical bounds: ≤ 20 % or ≤ GHS 100. Earned **only** when the provider order is `completed`. `UtilityBillCommissionService::settle` is the only credit path: one DB transaction, row lock on the order and the vendor, exact decimal wallet update, a `wallet_ledgers` row (`source = utility_bill_commission`), the `credited` marker and a **UNIQUE** `commission_wallet_ledger_id`. Safe to call from any worker, poll, retry or admin action. The credit lands in `vendors.wallet_balance` (withdrawable like other earnings). Config changes are audited (`utility_bill_config_audits`: admin, biller, old → new, time).

**Privacy.** Public URLs use a 40-char opaque token, never an account number. The sequential-id pages (`/checkout/success/{id}`, `/checkout/receipt/{id}`) and the public phone/id order-status endpoints refuse or omit platform orders, and the gateway callback redirects straight to the token URL, so ids cannot be enumerated into tokens. Public/vendor views show masked identifiers (`••••1234`); admin detail shows full values. Status pages send `no-store` and `noindex`. Provider-returned text is stripped of markup and escaped.

**Admin recovery** (`/admin/utility-bill-sales/{id}`): *Retry the existing attempt* (SAME reference, cannot pay twice; only for `attention`/`queued`/stale claims with no provider order), *Refresh status* (read-only), and *Start a NEW provider attempt* (NEW reference; only after a live-confirmed `refunded`, with a recorded reason and explicit confirmation; see Idempotency). The admin page words these as clearly different actions. Everything goes through the fulfillment service (row lock, terminal-state guard); completed orders cannot be retried.

**Tables:** `utility_biller_configs`, `utility_bill_config_audits`, `utility_bill_orders`, `utility_bill_events` (append-only timeline; no secrets or raw payloads).

**Migrations (forward-only).** `2026_10_07_000001` makes `orders.vendor_id` nullable (metadata-only; existing rows and the foreign key are untouched) and its `down()` is intentionally a no-op; `2026_10_07_000002` creates the four tables above. Do **not** use `php artisan migrate:rollback` as production recovery: it rolls back every migration in the last batch, including unrelated ones. To disable the feature, switch the service off in the admin settings (or empty the API key); to recover a specific order use the admin recovery actions. Take a database backup before migrating, as `scripts/deploy.sh` already does.

**Operational dependencies.** A queue worker (`SubmitUtilityBillPayment`), the scheduler (`utility-bills:sync`), a funded KiNG FLEXY **provider wallet**, and `KINGFLEXY_UTILITIES_API_KEY`. Without the key, the service is unavailable (fails safe). The Commission Services key may not be able to read the wallet balance; none of this relies on it.

---

## USSD

Two related features, both configured under `/admin/settings/ussd`:

1. **Customer USSD ordering.** An aggregator posts to `POST /api/ussd` (`UssdController`). `UssdMenuService` drives a menu for data bundles and Result Checker orders, using `UssdSession` rows (retained for replay detection, pruned after 7 days) and `UssdLog` interaction logs.
2. **Vendor USSD subscriptions.** Vendors buy a `UssdPlan` (`/vendor/ussd/subscription`) that gives them a dial code `<base><extension>*<vendor_id>#` (`UssdCodeGenerator`). `UssdSubscriptionPurchaseService` pays through the standard gateway stack and a dedicated callback route (`/vendor/ussd/subscription/callback/{reference}`, which re-verifies the reference before activating). `UssdSubscriptionService` is the only writer of subscription `status`, `ussd_code` and `current_for_vendor_id` (one live slot per vendor, enforced by a unique index). Statuses: `pending_payment`, `paid`, `active`, `expired`, `suspended`, `cancelled`, `payment_failed`.

Routing (`UssdVendorResolver`): `<extension>*<vendor id>`, legacy `vendor_code`, or the admin-configured default vendor; every form passes the subscription gate and there is no fallback to an arbitrary vendor.

Gateway authentication (`ussd.gateway` / `EnsureUssdGatewayRequest`): optional IP allowlist (IPs/CIDR) and optional shared secret (constant-time compare, stored encrypted). **Both default to off** and should be configured before exposing the endpoint publicly. Admin tools: plans CRUD, settings, `ussd-events` log.

Scheduled: expire subscriptions hourly, expiry warnings daily 08:00, session pruning daily 03:30.

---

## Vendor platform

Vendor routes are under `/vendor/*` behind `vendor.approved`.

| Area | Capabilities |
|---|---|
| Dashboard and analytics | Sales stats with date filters, analytics, delivery-status notice modal from admin. |
| Products | Create/edit/delete (soft delete), network services, activation, resellable flag. |
| Reseller | Affiliate marketplace (`marketplace`, `affiliates`), add reseller listings, set markup (`reseller-products`). |
| Orders | Orders, affiliate orders, status updates. |
| Fulfillment | Manual download/complete, resend failed API orders. |
| External fulfillment | Per-vendor provider keys (`settings/external-fulfillment`), connection test, `external-services`. |
| Wallet | Balance, top-ups (with status), ledger, withdrawals with account-name lookup. |
| Quick-buy | Wallet-funded purchases. |
| AFA | Provider/reseller settings, registrations, stats. |
| Result Checker | Pricing/settings and orders. |
| USSD | Subscription purchase and status. |
| Notifications | In-app list, read, read-all, unread count (the layout polls the count). |
| Support chat | See [Support chat](#support-chat). |
| Settings | Profile, password. |

Public vendor onboarding: `/vendor/request` (application), `/vendor/login`, password reset by email. New vendors require admin approval.

---

## Admin platform

An operational control plane under `/admin/*` (redesigned shell with `x-admin.*` components in `resources/views/components/admin` and `resources/css/admin.css`).

| Module | Purpose |
|---|---|
| Dashboard | Operational overview. |
| Vendors | Approve/reject, update, delete, adjust wallet balance, disable affiliate, set tier. |
| Orders / Transactions | Search, detail, update, **confirm payment** (stamps `admin_confirmed`), refunds/reversals. |
| Wallet top-ups / Withdrawals | Review top-ups; refresh or cancel withdrawals. |
| Payment gateways | CRUD, set default, toggle active, test connection. |
| Payment Health | Read-only view of pending/stuck payments, provider health, manual-review queue, scheduler heartbeat, queue health, alerts; manual recheck. Thresholds in `config/payment_health.php` are display-only. |
| Network services / Commissions / Reports | Catalog, commission view, reporting. |
| Recipient numbers | Audit log with streamed exports (`recipient_number_logs`). |
| Result Checker | Dashboard, PINs, orders, pricing tiers. |
| USSD | Plans, settings, event log. |
| Vendor tiers | Tiers, rules, promotions, history. |
| Settings | Service availability, delivery-status notice, vendor approval, platform service vendors, email (SMTP), USSD. |
| Notifications | List/read. |
| Manual queue run | Flag a cache request that the scheduler bridge turns into `queue:work --stop-when-empty`. |
| Support | Inbox, conversations, quick-reply management. |
| Content (CMS) | `/admin/content/*`. |

---

## Support chat

A private vendor <-> admin inbox. Vendor side: `Vendor\SupportController` (`/vendor/support`). Admin side: `Admin\SupportInboxController` (`/admin/support`). Both are thin callers of `Services\Support\SupportService`.

- **Conversations** have a category (DB rows: `support_categories`, seeded by migration and editable), a subject, and optionally a related record resolved only through `SupportRelatedRecords` with vendor-scoped queries (order, transaction, wallet top-up, withdrawal, AFA registration, Result Checker order).
- **Messages** may contain text, up to 4 images, and one voice note. Quick replies (`support_quick_replies`) are managed at `/admin/support/quick-replies`.
- **Statuses**: `waiting_admin` (Waiting for Admin), `waiting_vendor` (Waiting for Vendor), `resolved` (Resolved), `closed` (Closed); an `open` constant also exists. A vendor message sets `waiting_admin` (`waiting_since` is set only when *entering* that state, so follow-ups keep their place); an admin message sets `waiting_vendor`; admins resolve, close and reopen.
- **Reopen**: a vendor reply to a Resolved conversation reopens it only within `support.reopen_days` (default 7, env `SUPPORT_REOPEN_DAYS`). Closed, or resolved longer ago, requires a new request.
- **Queue**: the admin queue is FIFO by oldest `waiting_since`.
- **Unread** is per reader (`support_reads` keyed by conversation + `reader_guard` + `reader_id`), so each admin has their own unread state; queue status is global.
- **Actor identity**: a `SupportPrincipal` from the session (`vendor`, `admin`, or `web` with role admin), never from input.
- **Attachments**: stored on the private `local` disk (`SUPPORT_DISK`) under `support/{vendor}/{conversation}/{ulid}.ext` and served only by `SupportAttachmentResponder` after a policy check (404 on denial). Images (JPEG/PNG/WebP, max 5120 KB, max 40 megapixels) are decoded and re-encoded; audio (max 5120 KB, max 180 s) must match a webm/ogg/mp4/mp3 signature. Limits are in `config/support.php`.
- **Updates**: the thread UI polls every `support.poll_interval_seconds` (8 s); the layout polls unread counts. This is periodic polling, not push.
- **Voice notes** use the browser `MediaRecorder` API. The Content-Security-Policy grants `microphone=(self)` and `media-src 'self' blob:` **only** on `vendor.support.new|show` and `admin.support.show`; every other page keeps `microphone=()`. Voice recording needs a secure context (HTTPS) and a supporting browser.
- **Server requirements**: `gd` and `fileinfo`, writable `storage/app/private`, and PHP `upload_max_filesize` / `post_max_size` large enough for several 5 MB images.
- Rate limits: starting conversations 20/min, unread polls 120/min, related-options 60/min.

---

## CMS

Admin-managed **public site content** at `/admin/content`. Implemented in `app/Support/Cms`, `app/Models/Cms`, `Admin\Cms\*` controllers and `routes/cms.php`.

What exists:

- **Structured pages** (homepage, about): per-section field editing from a code-defined registry (`CmsRegistry`), section visibility and ordering, SEO.
- **Rich pages** (privacy, terms and custom `/p/{slug}`): Markdown body, with reserved slugs protected.
- **Banners**, **announcements** (date windows evaluated per request), **FAQs** (`/faq`), **navigation/footer** links, **site settings** (contact, social, logo), **media library**.
- **Drafts and publishing**: edits are stored as a pending draft; Publish promotes it to live and appends a `CmsRevision`. Unpublish, discard, archive, history and restore exist. Admin preview shows drafts and bypasses the cache.
- **Caching**: one short-TTL cache entry per content type, explicitly forgotten on every admin write (`CmsCache`).
- **Fallback**: before the one-time backfill migration (`2026_10_06_000002_backfill_cms_default_content`) runs, registry defaults (the former hardcoded content) are rendered; afterward the database is authoritative.

Safety: Markdown has raw HTML stripped and is passed through a strict DOM whitelist (`CmsMarkdown`); every admin-entered link is validated by `CmsLink` (rejects `javascript:`, `data:`, `http:`, protocol-relative and links to protected routes) and re-validated at render. Uploads (`MediaService`) accept only JPEG/PNG/WebP sniffed from bytes, within size and dimension limits (`config/cms.php`), are decoded and **re-encoded**, and stored under server-generated names on the `public` disk; SVG is refused. Media URLs are built from the request host so they satisfy CSP `img-src 'self'`. Actor identity is recorded from the session (`guard:id`).

**Boundary:** the CMS controls presentation content only. It does not control orders, payment settlement, wallets, gateway state or fulfillment.

---

## Notifications

| Channel | Behaviour |
|---|---|
| Vendor in-app (`VendorNotification`) | New order, affiliate order, order completed, withdrawal approved/rejected, AFA registration, order refunded, support reply. Layout polls the unread count. |
| Admin in-app (`AdminNotification`) | New order/vendor/product, withdrawal events, support message, order cancelled, **payment integrity alert** (throttled). |
| Email | Laravel mail with SMTP read at runtime from the `settings` table (`DynamicMailServiceProvider`, configured at `/admin/settings/email`). The mailables listed below implement `ShouldQueue`. |
| SMS | `SmsService` via BulkClix or Moolre (admin-configured SMS gateway); skipped when no SMS gateway is configured. |

Order-placed SMS/email communications run inline after the settlement commit (`dispatchSync`); mailables and several listeners are queued, so a worker is required for them. See [Queues and scheduler](#queues-and-scheduler).

---

## Queues and scheduler

### Queued work

`QUEUE_CONNECTION=database` by default. Without a worker, queued work waits silently.

| Work | Class |
|---|---|
| External fulfillment submission | `ProcessExternalFulfillment` (3 tries, backoff 60/300/900 s, unique per order) |
| Fulfillment status polling | `SyncExternalFulfillmentStatuses` |
| Utility Bill provider payment | `SubmitUtilityBillPayment` (1 try; exactly-once comes from the DB claim + stable provider reference; the scheduler recovers lost jobs) |
| Withdrawal payouts | `ProcessVendorWithdrawalPayout` |
| Result Checker retry | `RetryResultCheckerOrder` |
| Order SMS/email | `SendOrderPlacedCommunications` (run inline on completion), `SendUssdSubscriptionNotification`, queued mailables |
| Result Checker delivery | `SendResultCheckerDeliveryNotification` listener |
| Recipient-number audit | `LogRecipientNumberUsage` on queue **`audit`** (`RECIPIENT_NUMBER_LOGGING_QUEUE`) |

> **Named queue.** Recipient-number logging uses the `audit` queue. A worker started with default options only processes `default`. Use `--queue=default,audit` (or the audit logs never drain). Logging is skipped entirely when `QUEUE_CONNECTION=sync`.

### Scheduler

Registered in `routes/console.php` (verified with `php artisan schedule:list`):

| Task | Frequency | Purpose |
|---|---|---|
| `payments:reconcile` | every 5 min, no overlap | Verify and safely complete or cancel pending payments. |
| `payments:cleanup` | every 6 h, no overlap | Wide safety net (`--hours=24`); delegates to reconciliation, never cancels on age alone. |
| `withdrawals:retry-stuck` | every 5 min | Reset stuck payouts to `pending` for retry. |
| `withdrawals:cancel-stale` | every minute | Fail and refund withdrawals with no payout reference after 5 min. |
| `queue-manual-run-bridge` | every minute | Runs `queue:work --stop-when-empty` when an admin requested it (no shell from HTTP). |
| `external-fulfillment:sync-status` | every 10 min, no overlap | Poll GigsHub/SKDataPlug for delivery status. |
| `utility-bills:sync` | every minute, no overlap | Utility Bills: re-dispatch lost/stale submissions, re-queue transient attention orders, poll provider status with backoff. |
| `vendor-tiers:evaluate` | daily 02:00 | Mark vendors eligible for tier promotion. |
| `ussd:expire-subscriptions` | hourly | Expire past-due USSD subscriptions. |
| `ussd:notify-expiring` | daily 08:00 | Warn vendors 3 days before expiry. |
| `ussd:prune-sessions` | daily 03:30 | Close abandoned sessions; prune old rows. |

> **`wallet:cleanup-topups` is not scheduled.** A schedule for it is defined in `app/Console/Kernel.php`, but the Laravel 12 bootstrap (`bootstrap/app.php`) does not use that kernel for scheduling, and `schedule:list` does not show it. The command still exists and can be run manually (`php artisan wallet:cleanup-topups`). Earlier documentation claiming an hourly run was wrong.

### Production strategy (cPanel)

Two cron entries (replace placeholders):

```cron
* * * * * PHP_BINARY /path/to/core/artisan schedule:run  >> /dev/null 2>&1
* * * * * PHP_BINARY /path/to/core/artisan queue:work --queue=default,audit --stop-when-empty --tries=3  >> /dev/null 2>&1
```

The queue runs as a self-terminating worker each minute (no long-lived daemon). `schedule:run` also executes the manual-queue bridge. As an optional scaling step on a VPS, replace the queue cron with a Supervisor/systemd `queue:work` daemon and run `php artisan queue:restart` on each deploy. That is a recommendation, not the current production setup.

**Deploys** run `scripts/deploy.sh` via `.cpanel.yml`: maintenance mode, gzip `mysqldump` to `/home/xtraucom/backups` (14 kept; aborts if the dump fails), clean code sync, `composer install --no-dev --optimize-autoloader`, `migrate --force`, config/route/view caches, `public/storage` symlink, `queue:restart`, then back online. If a step fails the site stays in maintenance mode on purpose; fix it and run `php artisan up`. Production seeding of `CreateAdminUser`, `TestVendorSeeder` and the factory test user is refused; use `xtra4u:ensure-admin`.

**Queue stall alerting:** `queue:health-check` runs every 5 minutes and logs critical plus creates an admin notification (max one per hour) when ready jobs are older than `QUEUE_HEALTH_STALE_MINUTES` (default 15). Set `QUEUE_HEALTH_PING_URL` to a healthchecks.io-style URL to also catch the scheduler cron itself dying.

---

## Security model

High-level controls; see the sections above for detail.

- **Guard separation**: vendor, admin and web identities are separate. Admin authorization **fails closed** and has one definition, `App\Support\AdminAccess`: an `Admin` on the `admin` guard, or a `User` on the `web` guard whose `role` is exactly `admin`. A missing/NULL/empty/unknown role, a vendor, a customer, or any other model is never an admin (earlier code treated an absent role as admin via `$user->role ?? 'admin'`). `AdminOnly` runs before route-model binding so non-admins cannot tell existing from missing records.
- **Ownership**: vendor queries are scoped to the owner (policies, vendor-scoped related-record lookups).
- **Payment integrity**: frozen pricing, exact-amount verification, gateway/reference identity, single-use constraints, row locks, idempotent settlement, terminal failure behavior with no wallet credit and no fulfillment.
- **Fulfillment gating**: external fulfillment checks `allowsFulfillment()` under its own row lock.
- **Webhooks**: Paystack requires a valid HMAC signature. Moolre, Payaza and GigsHub verify a secret/signature **when one is configured**; configure them in production. Webhook routes are excluded from CSRF and, for Payaza, throttled.
- **CSRF**: enabled for all web routes except `webhooks/*` and `payment/callback`.
- **CSP**: `ContentSecurityPolicy` middleware on all web requests, with page-specific microphone/media allowances for support threads. Vite is bound to `127.0.0.1` for CSP compatibility; do not change it to `0.0.0.0`.
- **CSP known limitation (tracked)**: `script-src` still allows `'unsafe-inline'` and `'unsafe-eval'`, which weakens XSS protection. Removing them means moving Alpine to `@alpinejs/csp` (no inline expression evaluation), converting the inline `x-data`/`@click` expressions in ~40 Blade views into registered `Alpine.data()` components, and adding per-request script nonces. Mitigations today: Markdown/HTML sanitising in the CMS, output escaping, and `frame-ancestors`/`object-src` restrictions.
- **Rate limiting**: throttles on checkout, purchase, AFA submission, wallet top-up callback, USSD callback, Payaza webhook, support endpoints, CMS media and Markdown preview.
- **Uploads**: CMS media and support images are sniffed, decoded and re-encoded; support audio must match a container signature; support files are private and policy-gated.
- **Secrets**: gateway credentials are stored encrypted in the database; the USSD gateway secret is stored encrypted; per-vendor provider keys are encrypted in `vendor_settings`; Result Checker delivered PINs are encrypted on the order. Never commit `.env`.
- **Maintenance routes**: `routes/web.php` contains secret-keyed maintenance endpoints (`/clear-cache/{secret}`, `/storage-link/{secret}`) whose secret is hardcoded in the source. Treat that as a known weakness: anyone with source access knows the secret. Remove these routes or move the secret to configuration (and rotate it) before relying on them.
- **Local-only commands**: `xtra4u:ensure-admin`, `xtra4u:debug-gateway-users` and `xtra4u:test-moolre-payout-auth` refuse to run outside the `local` environment unless `--force` is passed.

No system is free of defects; this list describes controls present in the code, not a guarantee.

---

## Data model

High-level grouping (not every table):

| Subsystem | Models |
|---|---|
| Commerce | `Order`, `Transaction`, `Product`, `NetworkService` |
| Vendors / resellers | `Vendor`, `VendorSetting`, `ResellerProduct`, `VendorTier`, `VendorTierRule`, `VendorTierHistory` |
| Payments / wallet | `PaymentGatewayConfig`, `WalletTopup`, `WalletLedger`, `VendorWithdrawal` |
| Fulfillment | order `external_fulfillment_*` columns, `GigshubLowBalanceAlert`, `RecipientNumberLog` |
| Result Checker | `ResultCheckerOrder`, `ResultCheckerPin`, `ResultCheckerPricingTier`, `VendorResultCheckerSetting` |
| AFA | `AfaRegistration` |
| USSD | `UssdPlan`, `UssdSubscription`, `UssdSubscriptionEvent`, `UssdSession`, `UssdLog` |
| Support | `SupportCategory`, `SupportConversation`, `SupportMessage`, `SupportAttachment`, `SupportRead`, `SupportQuickReply`, `SupportConversationEvent` |
| CMS | `CmsPage`, `CmsSection`, `CmsRevision`, `CmsBanner`, `CmsAnnouncement`, `CmsFaq`, `CmsNavigationItem`, `CmsMedia`, `CmsSetting` |
| Notifications | `VendorNotification`, `AdminNotification` |
| Identity / config | `Admin`, `User` (legacy admin form), `Setting` |

---

## Project structure

```
app/
  Console/Commands/        Artisan operations (payments, withdrawals, fulfillment, USSD, tiers)
  Events/  Listeners/      OrderCompleted, AfaRegistrationCompleted, ResultCheckerOrderPaid
  Http/
    Controllers/           Public, Vendor, Admin (+ Admin/Cms, Webhooks, Api)
    Middleware/            AdminOnly, EnsureVendorApproved, EnsureCmsAdmin, EnsureUssdGatewayRequest,
                           ContentSecurityPolicy, PrunePurchaseTokens
  Jobs/                    ProcessExternalFulfillment, ProcessVendorWithdrawalPayout, ...
  Models/  (Models/Cms)
  Policies/                Support, USSD and Vendor policies
  Providers/               AppServiceProvider, CmsServiceProvider, DynamicMailServiceProvider, EventServiceProvider
  Services/
    Payments/              OrderPricingSnapshot, PaymentIntegrityGuard/Result, CheckoutIntentGuard
    ExternalFulfillment/   Client contract, factory, config, synchronizer, Providers/*
    Payouts/               Per-gateway payout services
    Support/               SupportService, inbox, attachments, related records
    Ussd/                  Routing, codes, subscriptions
    Admin/                 PaymentHealthService
    GatewayManager, PaymentService, PaymentReconciliationService, WalletService,
    AffiliateChainService, ResultCheckerService, AfaPaymentService, SmsService, ...
  Support/                 Money, PaymentIntegrity, PaymentVerificationState, Cms/*, ServiceAvailability, ...
config/                    payments, support, cms, external_fulfillment, payment_health, storefront, audit, momo
database/migrations/       107 migrations; seeders for gateway configs and optional admin/test users
resources/views/           storefront, vendor, admin, afa, result_checkers, checkout, components, emails
routes/                    web.php, cms.php, console.php (scheduler)
tests/                     Feature/ and Unit/
.cpanel.yml                cPanel deployment tasks
```

Note: `app/Console/Kernel.php` is vestigial for scheduling under Laravel 12 (see [Scheduler](#scheduler)).

---

## Local development

Prerequisites: PHP 8.2+ with `gd`, `fileinfo`, `mbstring`; Composer 2; Node.js and npm; SQLite or MySQL 8.

```bash
git clone <repository-url> XTRA4U
cd XTRA4U
composer run setup      # composer install, copy .env, key:generate, migrate --force, npm install, npm run build
```

`composer run setup` uses the SQLite default; create the file first if it does not exist (`touch database/database.sqlite`). For MySQL set `DB_CONNECTION=mysql` and the `DB_*` variables in `.env` before migrating.

Run everything together:

```bash
composer run dev
# php artisan serve | queue:listen --tries=1 | pail | npm run dev
```

Or individually: `php artisan serve`, `npm run dev`, `php artisan queue:listen`, `npm run build`. Use `--queue=default,audit` if you want recipient-number logging to run locally.

**Seeders.** `php artisan db:seed` creates a placeholder user (`test@example.com`) and `PaymentGatewayConfig` rows from `.env` values. It does **not** create a known admin. Create an admin explicitly:

```bash
php artisan xtra4u:ensure-admin admin@example.com --password='choose-a-strong-password'
```

This command refuses to run outside `local` unless `--force` is given. Optional seeders (`AdminSeeder`, `AdminUserSeeder`, `CreateAdminUser`, `TestVendorSeeder`) exist for development; some carry placeholder passwords, so do not run them in production.

Payment callbacks and webhooks need a publicly reachable HTTPS `APP_URL` (use a tunnel locally). On Windows, cURL error 60 means PHP needs a CA bundle (`curl.cainfo` / `openssl.cafile` in `php.ini`).

Set `CHECKOUT_COMING_SOON=false` in `.env` to enable `/checkout` if it is set to show the coming-soon page. Shared-host database reset helpers: `php artisan migrate:fresh-no-transaction` and `db:wipe-no-transaction`.

---

## Environment configuration

`.env.example` is partial: it covers the Laravel defaults, BulkClix, Paystack and GigsHub. Most integration settings are stored in the database (gateway configs, SMTP, USSD, vendor provider keys). Variables read by the code, grouped by system:

| Group | Variables |
|---|---|
| Core | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `LOG_*` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Queue / cache / session | `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_LIFETIME`, `FILESYSTEM_DISK` |
| Mail | `MAIL_*` (fallback; runtime SMTP comes from the `settings` table) |
| Payments | `PAYMENTS_CURRENCY`, `PAYMENTS_RECONSTRUCT_LEGACY_EXPECTED_AMOUNT`, `PAYMENT_INTEGRITY_ALERTS_ENABLED`, `PAYMENT_INTEGRITY_ALERT_THROTTLE_MINUTES`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_PAYMENT_URL` (seed values; live gateways are DB-configured) |
| Payment health (display) | `PAYMENT_HEALTH_*` thresholds |
| SMS | `BULKCLIX_API_KEY`, `BULKCLIX_SENDER_ID`, `BULKCLIX_BASE_URL` |
| Fulfillment | `GIGSHUB_BASE_URL`, `GIGSHUB_API_KEY`, `GIGSHUB_TIMEOUT`, plus XpresPortal / SKDataPlug / DatafyHub keys in `config/services.php`; `EXTERNAL_FULFILLMENT_AUTO_COMPLETE`, `EXTERNAL_FULFILLMENT_POLLING_ENABLED` |
| Utility Bills | `KINGFLEXY_UTILITIES_API_KEY` (Commission Services key `kf_cs_live_…`; server-side only; empty = service unavailable), `KINGFLEXY_UTILITIES_BASE_URL` (default `https://api.kingflexygh.com/api/v2`), `KINGFLEXY_UTILITIES_CONNECT_TIMEOUT` (5), `KINGFLEXY_UTILITIES_TIMEOUT` (20); optional tunables in `config/utility_bills.php`: `UTILITY_BILLS_CATALOG_TTL`, `…_CATALOG_STALE_TTL`, `…_LOOKUP_CACHE_TTL`, `…_LOOKUP_TOKEN_TTL`, `…_LOOKUP_PER_MINUTE`, `…_PAY_PER_MINUTE`, `…_STATUS_PER_MINUTE`, `…_BILLERS_PER_MINUTE`, `…_CLAIM_STALE_SECONDS`, `…_MAX_SUBMIT_ATTEMPTS`, `…_ATTENTION_AFTER_MINUTES` |
| Support | `SUPPORT_REOPEN_DAYS`, `SUPPORT_DISK` |
| CMS | `CMS_MEDIA_MAX_KB` |
| Audit | `RECIPIENT_NUMBER_LOGGING_ENABLED`, `RECIPIENT_NUMBER_LOGGING_QUEUE` |
| Storefront | `CHECKOUT_COMING_SOON` |
| Other | `MOMO_*` (legacy MoMo payouts, `config/momo.php`) |

Check `config/services.php` and `config/storefront.php` for the full list of provider variable names before adding keys. Never put real values in this README.

---

## Testing

```bash
composer run test                      # config:clear + php artisan test
vendor/bin/pint                        # code style
```

Tests use in-memory SQLite (`DB_CONNECTION=sqlite_testing`), array cache/session/mail and the `sync` queue (see `phpunit.xml`). Behaviour that depends on MySQL specifics (the `orders.status` enum alteration, unique-index migrations) is not fully exercised on SQLite; verify migrations against MySQL before production.

The suite is large. If a single run exhausts memory, run PHPUnit directly with a higher limit or split `tests/Feature` into chunks and run `tests/Unit` separately:

```bash
php -d memory_limit=1024M vendor/bin/phpunit
```

Some tests fail on a clean checkout (an `ExampleTest`, `MigrationTest` and two `VendorAfaFulfillmentOwnershipTest` cases at the last recorded baseline). Capture a baseline before blaming a change.

Invariant and security suites worth knowing by purpose:

- **Payment integrity and pricing**: `PaymentIntegrityInvariantTest`, `PricingAuthorityAndSnapshotTest`, `CheckoutAuthoritativePricingTest`, `PaymentVerificationStateTest`, `MoneyTest`.
- **Duplicate-charge prevention**: `DuplicateChargePrevention*Test`, `CheckoutIntentGuardTest`.
- **Reconciliation**: `PaymentReconciliation*Test`, `ReconcilePendingPaymentsCommandTest`, `PaymentsCleanupReconciliationTest`.
- **Wallet**: `PurchaseWalletBypassTest`, `PurchaseControllerWalletConcurrencyTest`, `VendorWalletPaymentTest`.
- **External fulfillment**: `ExternalFulfillmentIdempotencyTest`, `ExternalFulfillmentStatusSyncTest`, `ExternalFulfillmentStatusPollingTest`.
- **Gateways and webhooks**: `Paystack*`, `Payaza*`, `BulkClixPaymentCollectionTest`.
- **Support and CMS**: `tests/Feature/Support/*` (authorization, attachments), `tests/Feature/Cms/*`.
- **Legacy-recovery commands**: `PaymentsCloseLegacyCommandTest`, `FulfillmentClose*LegacyCommandTest`, `PaymentsReverseLegacyDuplicateCreditsCommandTest`.

---

## Production deployment

### Topology

The current deployment is cPanel/shared-hosting oriented. The Laravel application lives in a `core/` subdirectory of the web root; public assets live in the web root. `.cpanel.yml` performs these tasks, in order:

1. create `core/` under the deploy path;
2. copy `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `storage`, `vendor`, `artisan`, `composer.json`, `composer.lock` into `core/`;
3. copy `public/*` into the web root;
4. set permissions (`755` on `core`, `775` on `core/storage` and `core/bootstrap/cache`);
5. run `php artisan migrate --force` in `core/`.

What it does **not** do: it does not run `composer install`, does not build Vite assets, does not clear or rebuild caches, and does not restart queue workers. `vendor/` is not tracked in git, so the checkout that cPanel deploys from must already contain installed dependencies. `public/build` **is** tracked, so run `npm run build` and commit the output before deploying frontend changes.

> **Verify the entry point.** The repository's `public/index.php` requires `../vendor/autoload.php` and `../bootstrap/app.php`. With the `core/` layout the web-root `index.php` must point into `core/`. Because the deploy copies `public/*` over the web root, confirm what the live `index.php` looks like after a deploy.

Other requirements: PHP 8.2+ with `gd` and `fileinfo`; MySQL 8.0+; `APP_ENV=production`, `APP_DEBUG=false`; the two cron entries from [Queues and scheduler](#queues-and-scheduler); `public` uploads (CMS media) reachable at `/storage` (a `storage` symlink or real directory in the web root pointing at `core/storage/app/public`); `storage/app/private` writable for support attachments.

### Checklist

> **Warning:** take a verified database backup first. Do not rely on `migrate:rollback` as a casual rollback; some migrations alter schema and backfill data, and a safe rollback is a restore from backup plus a redeploy of the previous release.

1. Back up the database and note the current release.
2. If the release includes the payment single-use migration on a database with history, run `php artisan payments:audit-uniqueness --details` and resolve any duplicates first (the migration aborts otherwise).
3. Build assets (`npm ci && npm run build`) and commit `public/build` if the frontend changed.
4. Ensure dependencies are installed in the deploy checkout (`composer install --no-dev --optimize-autoloader`).
5. Deploy (push to the cPanel repository and run the deployment).
6. Confirm `migrate --force` completed (the deploy runs it).
7. Clear and rebuild caches as needed (`php artisan optimize:clear`, then `config:cache`/`route:cache`/`view:cache` if used). Clear config cache after any `.env` change.
8. Queue processing is self-terminating per cron tick, so new code is picked up on the next tick. If you run a daemon worker, `php artisan queue:restart`.
9. Verify the scheduler: `php artisan schedule:list`, and the Payment Health page's scheduler heartbeat.
10. Smoke test: a checkout (sandbox or small amount), vendor login and dashboard, admin dashboard and Payment Health, a support conversation (including an attachment), the public pages and a CMS preview.

---

## Operations and recovery commands

Run from the application directory (`core/` in production). `--execute` flags matter: the recovery commands are dry runs by default.

**Safe / read-only**

| Command | Purpose |
|---|---|
| `php artisan schedule:list` | Show registered schedules. |
| `php artisan utility-bills:sync [--submit-limit=5] [--poll-limit=20]` | Run one Utility Bills recovery + status-sync pass (the same work the scheduler does every minute). Idempotent; safe to run by hand. |
| `php artisan payments:audit-uniqueness [--details]` | Report duplicate payment references / gateway transaction ids. Changes nothing. |
| `php artisan withdrawals:retry-stuck --dry-run`, `withdrawals:cancel-stale --dry-run` | Preview. |
| `php artisan fulfillment:close-paid-legacy --before=YYYY-MM-DD` | Dry run (default). |
| `php artisan fulfillment:close-manual-legacy --before=YYYY-MM-DD` | Dry run (default). |
| `php artisan payments:close-legacy --before=YYYY-MM-DD` | Dry run (default). |
| `php artisan payments:reverse-legacy-duplicate-credits --date=YYYY-MM-DD` | Dry run (default). |

**Operational**

| Command | Purpose |
|---|---|
| `payments:reconcile [--type=] [--limit=] [--reference=]` | Reconcile pending payments (scheduled every 5 min). |
| `payments:cleanup [--hours=] [--limit=]` | Wide reconciliation safety net (scheduled). |
| `xtra4u:sync-external-fulfillment [--vendor=] [--sync]` | Poll provider delivery status. |
| `withdrawals:retry-stuck`, `withdrawals:cancel-stale` | Payout retry / stale cancellation. |
| `xtra4u:evaluate-vendor-tiers [--dry-run]` | Tier eligibility. |
| `xtra4u:expire-ussd-subscriptions`, `xtra4u:notify-expiring-ussd-subscriptions`, `xtra4u:prune-ussd-sessions` | USSD lifecycle. |
| `wallet:cleanup-topups [--days=] [--archive]` | Manual only (not scheduled). |
| `xtra4u:mail-test {to}` | Send a test email using the active SMTP configuration. |

**Mutating / use with care**

| Command | Notes |
|---|---|
| `fulfillment:close-paid-legacy --before= --execute` | Administratively closes historical paid orders so they are never resubmitted. |
| `fulfillment:close-manual-legacy --before= --execute` | Same, for manually delivered historical orders. |
| `payments:close-legacy --before= --execute [--type=]` | Closes legacy pending payment records out of reconciliation and fulfillment. |
| `payments:reverse-legacy-duplicate-credits --date= --execute` | Reverses wallet credits from one historical duplicate-credit incident. |
| `gateways:migrate-config` | Moves `.env` gateway settings into the database. |
| `products:fix-gigshub-mappings [--dry-run]` | Repairs GigsHub product mappings. |
| `vendors:export-numbers` | Exports vendor contact data; handle the output as personal data. |
| `xtra4u:ensure-admin`, `migrate:fresh-no-transaction`, `db:wipe-no-transaction` | Local/reset helpers; `migrate:fresh-no-transaction` and `db:wipe-no-transaction` destroy data. |

> **Warning:** the `*-legacy*` and `reverse-*` commands exist for specific historical incidents. Do not run them casually, always review the dry-run output first, and take a backup before `--execute`. `payaza:payout-diagnostic` is a sandbox-only diagnostic and refuses to run without several explicit guards.

---

## Troubleshooting

| Symptom | Where to look |
|---|---|
| Errors | `storage/logs/laravel.log` (`php artisan pail` locally). |
| Orders paid but not fulfilled | `orders.payment_integrity_status` (must allow fulfillment), `external_fulfillment_status`, vendor external-fulfillment config, Payment Health fulfillment backlog, the log for "External fulfillment skipped". |
| Payment stuck pending | Admin Payment Health; `php artisan payments:reconcile --reference=<ref>`; `mismatch` / `manual_review` orders need a human (read `payment_integrity_note`). |
| Jobs not running | A worker/cron must run; check the `jobs` and `failed_jobs` tables and the queue health panel. Remember the `audit` queue. Do not flush queues as routine troubleshooting. |
| Scheduler not running | `php artisan schedule:list`; check the cron entry and the Payment Health scheduler heartbeat. |
| Config change not applied | `php artisan config:clear` (and `optimize:clear`). |
| Images or uploads missing | `storage` link in the web root, permissions on `storage/app/public` (CMS) and `storage/app/private` (support). |
| Support attachment fails | `gd`/`fileinfo` loaded, PHP upload/post limits, `storage/app/private` writable, file under 5 MB and within allowed types. |
| Voice note will not record | HTTPS required; browser must support `MediaRecorder`; only support thread pages permit the microphone under CSP. |
| Webhooks rejected | Confirm the configured webhook secret/signature for the gateway; check the log for signature warnings. |

---

## Documentation

| Document | Status |
|---|---|
| `README.md` | Current. |
| `CLAUDE.md` | Contributor/assistant guidance. Several statements are stale (custom command names, the `wallet:cleanup-topups` schedule, the Result Checker PIN encryption note, Flutterwave/Hubtel as supported collection gateways); prefer this README. |
| `knowledge base/PRODUCT REQUIREMENTS DOCUMENT (PRD).md` | Product requirements; may describe intended rather than current behavior. |
| `PROJECT_CONTEXT.md` (2026-05-11), `IMPLEMENTATION PLAN (TECHNICAL).md` and `SYSTEM ARCHITECTURE DIAGRAM.md` (2025-12), `REFACTOR_SUMMARY.md` | **Historical.** Predate the payment-integrity, reconciliation, Payaza, Support and CMS work. |
| `CONFIRMATION.md`, `TEST_RESULTS.md`, `WALLET_TOPUP_FIXES.md` (2026-05) | **Historical** point-in-time notes about wallet top-ups. |

Current code plus this README are authoritative. Historical planning documents may describe earlier architecture.

---

## Ownership and license

- **Registered company:** Richprime Services (Registrar General's Department of Ghana, registration number BN582881225).
- **Platform:** XTRA4U, <https://xtra4u.com>.
- **License:** proprietary. This repository is for internal development and operations of XTRA4U and for authorized contractors, partners and reviewers under appropriate agreement. Redistribution, derivative works and use outside XTRA4U operations are not permitted without written permission.
- Contact: info@richprimeservices.com (business and licensing), support@xtra4u.com (technical).

This section records registration facts only. It makes no statement about regulatory compliance.
