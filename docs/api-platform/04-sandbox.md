# Milestone 4 — API sandbox

Scope note: design/operations documentation for what Milestone 4 built. Public developer docs and the
OpenAPI spec are Milestone 7.

## What it is

A test key (`xt_test_…`) now works end to end: catalog, quote, order, simulated payment, simulated
fulfilment, order/transaction retrieval and webhooks. Nothing it does has a real-world effect.

A sandbox request **never**: writes to `orders`/`transactions`, contacts a payment gateway or a
fulfilment provider, moves a wallet, calculates or credits a commission, sends an SMS/email, allocates
inventory or a result-checker PIN, or dispatches a production job.

## Environment separation

The environment comes **only** from the API key row. `AuthenticateApiKey` stores it as the request
attribute `api.environment`; there is no header, query parameter or body field that can change it.

* `api.env` middleware (every authenticated route) refuses a request whose environment is unknown
  (`403 ENVIRONMENT_MISMATCH`) — fail closed.
* Every controller action starts with `if (ApiEnvironment::isSandbox($request))`. The sandbox branch always
  returns or throws; there is no fall-through into production code.
* Production services (`ApiOrderService`, `ApiPaymentService`, and through them the gateway manager, wallet,
  SMS…) are resolved lazily and only on production paths, so a sandbox request never even instantiates them.
* Sandbox ids are visibly different: `sbx_qt_…` (quote), `sbx_ord_…` (order), `sbx_pay_…` (payment),
  `sbx_ful_…` (fulfilment), `evt_sbx_…` (webhook event). A production id presented to a sandbox key (or vice
  versa) is a plain 404.
* Sandbox responses carry `meta.environment: "sandbox"`; resources add `environment` and `simulated: true`.

## Data

Additive tables (migration `2026_11_19_000001`), no foreign keys to legacy tables:

| Table | Purpose |
|---|---|
| `developer_sandbox_orders` | Order, product snapshot, three statuses, payment/fulfilment results. Unique: `order_ref`, `quote_reference`, `payment_reference`, `(application_id, idempotency_key)`. |
| `developer_sandbox_payments` | One simulated payment per order (`sandbox_order_id` unique, FK cascade). |
| `developer_sandbox_order_events` | Append-only state history (FK cascade). |

Shared tables gain environment awareness: `api_quotes.environment` (already existed, now `sandbox` for test
quotes), `developer_webhooks.environment` (new, default `production`, immutable per endpoint) and the
`developer_events` unique key becomes `(environment, order_id, type)` so a sandbox and a production order
may share a number. The migration is reversible: `down()` deletes only sandbox events/deliveries/attempts/
webhooks and sandbox quotes, and leaves production rows untouched. A quote counts as a sandbox quote only
when **both** `environment = 'sandbox'` and its reference is exactly the minted format (`sbx_qt_` + 26
lowercase ULID characters) say so; production quotes (`qt_…`), including the same vendor's and application's,
and any row that matches only one of the two tests are never deleted.

## Endpoints (all `/api/v1`, test key)

Same URLs and scopes as production: `GET networks|services|products|products/{offer}`, `POST quotes`,
`POST orders` (`client_reference` optional, sandbox only), `GET orders/{order}`, `GET orders/{order}/status`,
`POST orders/{order}/payments`, `GET transactions/{reference}`. The catalog and pricing logic is the vendor's
real one (read-only).

Sandbox-only (production key → `403 ENVIRONMENT_MISMATCH`), scope **`sandbox:simulate`**:

* `GET /sandbox/simulations` — the allowed steps and scenarios.
* `POST /sandbox/orders/{order}/simulate` — body: exactly one of `event` or `scenario`.

### The `sandbox:simulate` scope

* Both endpoints above require it. `payments:initiate` keeps its production meaning (start a payment,
  `POST /orders/{id}/payments`) and grants **no** simulation access; `sandbox:simulate` in turn does not allow
  starting a payment. A key without the scope gets `403 MISSING_SCOPE` (`required_scope: sandbox:simulate`).
* **Test keys only.** `ApiKeyService::create` and the portal key form refuse it on a production key
  (`INVALID_SCOPES`), `DeveloperApiKey::hasScope()` ignores it on any non-sandbox key even if a row were edited
  by hand, and the `api.sandbox` middleware rejects production keys before the scope check runs.
  The list lives in `config('developer_api.sandbox_only_scopes')`.
* **Upgrade note:** sandbox keys created while simulation used `payments:initiate` must be re-issued (or
  rotated after adding the scope) to keep simulating. Only unreleased M4 builds had that behaviour.

## Workflow

1. `POST /quotes` → `sbx_qt_…` (same TTL and pricing as production).
2. `POST /orders` with `Idempotency-Key` → `sbx_ord_…`, status `awaiting_payment`; emits `order.created`.
3. `POST /orders/{id}/payments` → simulated payment, status `payment_pending`; emits `payment.pending`.
   No checkout URL, no gateway.
4. `POST /sandbox/orders/{id}/simulate`:

| `event` | Needs | Result | Webhook events |
|---|---|---|---|
| `payment.succeeded` | payment initiated | payment paid | `payment.succeeded` |
| `payment.failed` | payment initiated | payment failed, order failed | `payment.failed`, `order.failed` |
| `fulfillment.processing` | payment paid | processing | `order.processing` |
| `fulfillment.completed` | processing | completed | `order.completed` |
| `fulfillment.failed` | processing | failed | `order.failed` |

| `scenario` | Steps |
|---|---|
| `success` | payment.succeeded → fulfillment.processing → fulfillment.completed |
| `payment_failed` | payment.failed |
| `fulfillment_failed` | payment.succeeded → fulfillment.processing → fulfillment.failed |

A single event is **strict** (out-of-order → `409`, `PAYMENT_NOT_INITIATED` when no payment exists yet).
A scenario is **lenient and all-or-nothing** in one transaction: already-applied steps are skipped, and
replaying a finished scenario returns `200` with `meta.idempotent_replay: true`. Arbitrary statuses cannot be
injected (`status`, `payment_status`, `fulfillment_status`, `amount`, `paid` are rejected).

All state changes go through one engine (`SandboxOrderTransitions`): row lock → explicit transition table →
history row → events, in a single transaction.

## Webhooks

The existing pipeline is reused unchanged (HMAC signature, timestamp, unique event id, duplicate protection,
retries/backoff, SSRF validation, attempt history). Differences:

* Endpoints are created for an environment (`POST` form: environment select, default `sandbox`). Fan-out
  matches application **and** environment; the dispatcher re-checks it and cancels a mismatched delivery
  (`last_error = environment_mismatch`).
* Sandbox bodies add top-level `"environment": "sandbox"` and `"simulated": true`; header
  `X-XTRA4U-Environment: sandbox`. Production bodies and headers are byte-for-byte unchanged.
* Delivery needs the queue worker (immediate dispatch after commit); `developer-webhooks:run` is the
  per-minute backstop.

## Portal

Dashboard and application page show a separate sandbox panel (orders, simulated payments, completed, failed),
TEST/LIVE badges on keys and endpoints, and live/test key counts. Sandbox data is excluded from every revenue,
sales, commission, wallet and production-order figure because it lives in tables those queries never touch.

## Retention

`developer-sandbox:prune {--days=} {--dry-run}`, scheduled daily. **Off unless configured**: set
`DEVELOPER_API_SANDBOX_RETENTION_DAYS` (config `developer_api.sandbox.retention_days`) or pass `--days`.
**Production setting: `DEVELOPER_API_SANDBOX_RETENTION_DAYS=30`** (opt-in; it is not set by default and is
commented out in `.env.example`). "Age" is last activity (`updated_at`) for orders and `created_at` for quotes.
Preview first, which deletes nothing: `php artisan developer-sandbox:prune --dry-run`.
A floor of `min_retention_days` (7) applies; deletion is batched (`prune_batch`, default 500), idempotent,
and filtered to sandbox rows only (sandbox orders, their events/payments, sandbox quotes, sandbox
`developer_events` and their deliveries). Production rows sharing an order number are never touched.

## Testing

`tests/Feature/DeveloperApi/Sandbox*Test.php`. `SandboxTestCase::forbidProductionServices()` binds every
production collaborator (gateway manager, payment service, integrity guard, wallet, SMS, the API order/payment
services) to a closure that records and throws, and fakes Mail/Notification/Http and the `OrderCompleted`
event; each test asserts those were never invoked. `SandboxIsolationTest` also scans the sandbox code for
forbidden references. All HTTP is faked.
