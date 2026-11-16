# XTRA4U Developer API + AI Platform — Audit & Implementation Plan

Status: Milestone 1 (audit) complete. Source of truth is the code, not the docs.

## 1. What already exists

| Area | Finding |
|---|---|
| Routing | `bootstrap/app.php` registers **web routes only** (+ console, `/up`). No `api:` routing, no Sanctum/Passport. The only "API" routes are `POST /api/ussd` and `/api/ussd/gwosevo` and the `/webhooks/*` family, all inside `routes/web.php`. |
| Auth realms | `web` (User, role=admin), `vendor` (Vendor), `admin` (Admin) — all session guards. `AdminAccess` is the fail-closed admin check; `AdminOnly` and `EnsureCmsAdmin` are prepended before `SubstituteBindings`. Customers have **no accounts**. |
| Catalog | `products` (vendor-owned, soft-deleted, metadata JSON in `description`: network/size/validity/category), `reseller_products` (listing = base + markup → `selling_price`, derived on save), `network_services` (network images). Public marketplace query lives **inline** in `CheckoutController::show()`. |
| Order creation | `CheckoutController::process()` (~270 lines, HTTP + business logic mixed): resolves product vendor-matched, prices via `OrderPricingSnapshot`, checks `ServiceAvailability`, dedupes via `CheckoutIntentGuard` (scope+key unique index), creates `Order`, calls `PaymentService::initiatePayment()`. |
| Payment | `GatewayManager` (DB-driven gateway) → per-gateway `*PaymentService`. Verification: `PaymentService::checkPaymentStatusForGateway` → `PaymentVerificationState` → `PaymentIntegrityGuard::guard` → `PaymentService::completeOrder` (row-locked, idempotent). Webhooks + `PaymentReconciliationService` settle orders independently of any client. `CheckoutController::verify()` holds the orchestration inline. |
| Order lifecycle | `orders.status`: Pending → Processing → Completed/Failed; `payment_status`: unpaid/paid/completed/failed; `payment_integrity_status` gates settlement and fulfilment. `OrderCompleted` event → listeners queue `ProcessExternalFulfillment`, SMS/email, recipient logging. |
| Commission | `AffiliateChainService` + `completeResellerOrder` snapshot onto the order. Untouched by this work. |
| Scheduling | `routes/console.php` (not Kernel). Queue workers are required. |
| Rate limiting | One named limiter (`utility-lookup`) in `AppServiceProvider`. |
| AI | No AI/OpenAI code or dependency anywhere. |
| Tests | ~190 feature tests, SQLite in-memory, `RefreshDatabase`, `Http::fake()` for gateways. Known: 4 pre-existing failures on clean main and a single-process OOM (see project memory) → run split, baseline first. |
| Prod | cPanel, MariaDB 11.4, Laravel in `core/`. `orders` was MyISAM at one point (no FKs/row locks) — verify engine before relying on a FK to `orders`. |

## 2. What can be reused (and must not be re-implemented)

`GatewayManager`, `PaymentService::initiatePayment/checkPaymentStatusForGateway/completeOrder`, `PaymentIntegrityGuard`, `PaymentFailureTransition`, `OrderPricingSnapshot`, `CheckoutIntentGuard`, `ServiceAvailability`, `GhanaPhoneNumber`, `Money`, `Setting` (admin config), `AdminAccess`, `WalletService` (untouched), existing fulfilment jobs/listeners, `Http` client (no OpenAI SDK needed — avoids a new dependency).

## 3. What needs to be created

New realm **Developer** (4th guard `developer`, separate from vendor/admin/customer), API key auth, versioned stateless `/api/v1`, quote store, order attribution, webhook delivery engine, sandbox engine, AI layer, developer portal, admin section, docs/OpenAPI.

## 4. Conflicts / risks

1. **Order attribution**: orders need `developer_application_id` (+ client `external_reference`). New nullable columns on a large production table; instant on InnoDB, table rebuild on MyISAM → check engine, deploy off-peak.
2. **`CheckoutController::process()/verify()` are not reusable as-is.** Decision: do **not** modify them (production safety rule 4). New `DeveloperApi` services call the same underlying primitives. Duplicated orchestration is small (~60 lines) and covered by tests; a later refactor to share it is optional and separate.
3. **State-change events bypass Eloquent** (`PaymentFailureTransition` and other conditional updates use query-builder writes), so model observers would miss transitions. Webhook events therefore come from a **scheduled sweeper** that compares order state to the last emitted event, not from observers.
4. **Gateways are hosted/redirect or MoMo-prompt flows.** The API returns what the gateway returned (authorization URL / pending prompt); it never marks anything paid.
5. **Platform-vendor-less orders** (Utility Bills) must never be served by the API (existing invariant) → API only exposes `orders` rows with `developer_application_id` = caller.
6. **Two USSD gateways** (Moolre, GWO SEVO) are independent — nothing here reads or writes their config.
7. `bootstrap/app.php` change is minimal and additive (`then:` callback registering a separate route file) — no change to the `web` group, so CSP/CSRF/session behaviour of existing pages is unchanged.

## 5. Decisions needed from the business (NOT invented)

| # | Question | Interim behaviour |
|---|---|---|
| B1 | Does an API-originated sale pay any extra commission/markup to the developer? | None. Sale settles exactly as a marketplace/storefront sale for the seller vendor. |
| B2 | Vendor-specific applications: must the vendor consent? | A vendor application is linked by admin approval **and** requires the vendor to approve in their dashboard (pending state until then). Flag to confirm. |
| B3 | Airtime: is it modelled as a `products` row? Config categories are data/ecg/shop/results/afa only. | v1 exposes `products` rows whose metadata category is `data` (and airtime if present as a product). No invented airtime flow. |
| B4 | Results Checker / AFA / Utility Bills have their own tables and flows. | Out of scope for REST v1 (documented as not available); phase-2 adapters. |
| B5 | Plan prices / quotas | Configurable rows (`developer_plans`), no seeded prices. Billing: interface only (`BillingGateway` contract, null implementation). |
| B6 | Production access approval | Admin approves an application for `production`; default is sandbox. |
| B7 | AI provider capabilities/costs | OpenAI via Chat Completions + tools, model/base URL/price table configurable; cost estimates are labelled estimates. |

## 6. Proposed database changes (all additive, InnoDB, no changes to existing columns)

New: `developers`, `developer_applications` (env, status, vendor_id nullable, scopes, plan_id), `developer_api_keys` (prefix, `hash` sha256 of secret, scopes, expires_at, revoked_at, last_used_at), `developer_api_requests` (usage log, no bodies), `api_quotes`, `api_idempotency_keys`, `developer_webhooks`, `developer_webhook_deliveries`, `developer_plans`, `developer_subscriptions`, `developer_sandbox_orders` (**sandbox never touches `orders`**), `ai_conversations`, `ai_messages`, `ai_usage_logs`, `developer_audit_events`.
Altered: `orders` + `developer_application_id` (nullable, indexed), `external_reference` (nullable; unique with application id).

## 7. API architecture

`/api/v1/*`, stateless, middleware: `api.request-id` → `api.key` (hash lookup, status/expiry/env/scope) → `api.rate` (per key/app/plan) → `api.scope:xxx` → controller → service → API Resource. Uniform envelope `{success,message,data,meta{request_id}}` / `{success:false,message,error{code,details},meta}`. Policies enforce resource ownership in queries (application-scoped orders; vendor-scoped apps limited to their vendor's offers). API keys: `xt_{live|test}_{8-char prefix}_{40-char secret}`, only sha256 stored, shown once.
Sandbox: a `test` key can only reach sandbox services (fixed catalog from config, `developer_sandbox_orders`, simulated payment state machine). The environment is read from the key, enforced inside the service container binding — not a flag on a production code path.

## 8. Security considerations

Hashed keys + constant-time compare, one-time reveal, scope + resource-level checks, no secrets/bodies in logs, SSRF-hardened webhook URLs (https only, resolve DNS and reject private/loopback/link-local/metadata ranges at registration AND at delivery, no redirects, timeouts, size cap), HMAC-SHA256 signed payloads with timestamp, idempotency keys scoped to application, AI tools allow-listed with per-tool authz, confirmation token required for any charge-creating tool (server-side, not model-side), prompt-injection assumption (tool results are data), AI keys only via env/encrypted setting, never logged.

## 9. Milestones

| M | Scope | Exit |
|---|---|---|
| 2 | Foundation: migrations, models, key service, envelope, middleware, rate limit, health/networks/services/products, quotes, orders, payments, transaction status (+tests) | tests green, existing checkout tests unchanged |
| 3 | Developer portal (guard, register, apps, keys, logs, usage, webhooks, docs) + webhook engine | feature tests |
| 4 | Sandbox engine | no-real-call tests |
| 5 | AI engine + customer/vendor/developer assistants | mocked-provider tests |
| 6 | Admin management | tests |
| 7 | OpenAPI + guides, full regression (split runs) | docs match routes |
