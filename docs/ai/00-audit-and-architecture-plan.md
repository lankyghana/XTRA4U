# XTRA4U AI Engine — M5.1 Audit and Architecture Plan

Status: **M5.1 (audit and design) complete. Nothing is implemented.** Awaiting product-owner review.
Audit date: 2026-11-18. Source of truth is the code, migrations, tests and config as of the commit below, not older documentation.

**Legend** — every statement is tagged where it matters:
**[V]** verified in this audit from code/config/lock file · **[P]** proposed, does not exist · **[A]** assumption · **[U]** unresolved / not verified here · **[N]** the capability does not exist.

---

## 1. Executive Summary

1. **There is no AI engine and nothing to reuse [N].** No AI package, provider code, table, env var, prompt, chatbot or conversation store exists. The only trace is one **reserved but unimplemented API scope**, `ai:chat`, in `config/developer_api.php:15`.
2. **That reserved scope is already a live trap [V].** `ApplicationController` passes every `config('developer_api.scopes')` entry to its view (line 44), and `StoreApiKeyRequest` / `ApiKeyService` accept all of them, so an approved vendor can mint a key labelled "Use the XTRA4U AI assistant" that grants nothing. Decide whether to hide or implement it (§21 D8). This audit recommends hiding it and **not** exposing a public AI endpoint in M5.
3. **The existing public order tracking is not safe to put an AI in front of [V].** `POST /order-status/check` returns up to 50 orders (vendor, amount, status) for *any* phone number with no verification, and `POST /order-status/poll` accepts arbitrary integer order ids; neither route has throttle middleware. `/checkout/success/{order}` and `/checkout/receipt/{order}` are keyed by sequential id with no ownership check. These are pre-existing issues; M5 must not wrap them, and I recommend a separate hardening ticket (§21 D12). Nothing was changed.
4. **Recommended provider approach: Laravel's HTTP client behind a small `AiProvider` interface, OpenAI first. Do not install `openai-php/laravel`.** Its latest release (v0.22.0, 2026-10-31) requires `laravel/framework ^12.70|^13.0`; this project is locked at **v12.69.3**, so installing it forces a framework upgrade. `openai-php/client` v0.22.0 would resolve, but it is pre-1.0, pulls in two extra packages, and gives nothing the HTTP client plus `Http::fake()` (already the project's test pattern) doesn't.
5. **Recommended execution model: synchronous, non-streaming first.** Queue workers on production are cron-driven, self-terminating (`queue:work --stop-when-empty` every minute), so a queued AI reply would take up to ~60 s. There is no broadcasting layer. The real risk is the opposite one: a synchronous AI call holds a PHP worker on shared hosting and could starve checkout. This is the main performance risk and drives the design (global concurrency cap, kill switch, circuit breaker — §16).
6. **Tools, not database access.** The model only ever sees an allow-listed, per-surface set of server-side tools; identity (vendor id, application id, session) comes from the authenticated server context and is never a tool parameter. Authorization runs in Laravel before every tool call.
7. **Purchases: hand off to the existing checkout. The assistant never creates orders or initiates payment in M5.** A single-use, session-bound, price-bound *proposal* record backs the "confirm" button. The existing checkout has **no deep-link/prefill support [V]**, so the handoff needs a small change to existing checkout code, which needs your approval (§12, D5).
8. **Recommended build order differs from the brief:** Foundation → Tool system → **Developer assistant** → **Vendor assistant** → **Customer assistant** → Hardening. Lowest-risk, authenticated surface first; the anonymous, cost-abusable, injection-exposed customer surface last, after budgets and monitoring are proven (§19). Build order ≠ release order; everything ships behind flags.
9. **Largest hidden cost:** vendor dashboard figures are computed inline inside the 1,237-line `VendorDashboardController`; there is no reusable vendor read-model service. The vendor assistant needs a new read-only query service plus parity tests so the AI never quotes a number that differs from the dashboard.
10. **Business decisions are required before any live provider call** (provider terms/data handling, budget, retention, privacy-policy wording). The current Privacy Policy contains no AI/processor, retention or cross-border wording (§15).

---

## 2. Current Repository and Git State

| Item | Value | |
|---|---|---|
| Branch | `main` | [V] |
| HEAD | `d7128335f58cfe44bc38ad97a37347f7220662f4` (`d712833 docs(api-platform): document sandbox quote handling on rollback`) | [V] |
| Working tree | Clean (`git status --short -uall` empty before the doc was created) | [V] |
| Tracking | `origin/main`; `git rev-list --left-right --count main...origin/main` = `0 0` | [V] — against the last-known remote ref; **no fetch was run**, so this does not prove the remote hasn't moved |
| `docs/ai/` | Did not exist before this document | [V] |

**M4 is present in history [V]:** `1bb83fc feat(developer-api): add sandbox engine for xt_test_ keys`, `ce1a270` (retention schedule), `1aa7a95` (vendor-tied access, sandbox key serving), `108f8ba`/`168b50b` (tests), `46e2b25`/`379bd38` (docs), `3b8bafc`/`25656c5`/`d712833` (quote-rollback fix, tests, docs). M2/M3 commits (`379dec6`, `2bdd9f9`, `f069dea`, `2288c3a`, `85ab438`) are also present.

*Note on sources:* an auto-memory note from earlier work described M4 as uncommitted. Git history shows it committed; git wins.

Audit method: read-only inspection (Git, file reads, `grep`, `php -v`, `php artisan --version`, one read-only Packagist metadata request). No migrations run, no packages installed, no tests run, no provider/gateway calls, `.env` not read (only `.env.example` key names; values not reproduced).

---

## 3. Verified Technology Stack

| Area | Finding |
|---|---|
| Laravel | `laravel/framework` **v12.69.3** (`composer.lock`; `php artisan --version` agrees) [V] |
| PHP | `composer.json` requires `^8.2`; local runtime **8.4.11 NTS** [V]. Production PHP version not verified [U] (the deploy script refuses builds vulnerable to a named CVE per `4ab1f04`) |
| HTTP client | `guzzlehttp/guzzle` **7.15.5**; `psr/http-client`, `psr/http-factory`, `psr/http-message` installed; `php-http/*` **not** installed [V] |
| Other runtime deps | `barryvdh/laravel-dompdf` 3.1.2, `laravel/tinker` 2.11.1. No Sanctum/Passport/Livewire/Inertia/Redis client [V] |
| Frontend | Blade, Tailwind 4, Alpine 3.15 (+`@alpinejs/collapse`), Vite 7, axios. Alpine is bundled via npm, not a CDN [V] |
| DB engines | `config/database.php` defines `sqlite` (default), `sqlite_testing`, `mysql`, `mariadb`, both MySQL-family with `utf8mb4_unicode_ci`, `strict => true`, `engine => null` [V]. Production is **MariaDB 11.4**; M4 work was verified on SQLite, MySQL 8.4 and MariaDB 11.4.13 per project notes/commit history [A — not re-run here] |
| Migrations / tests | 121 migration files; 191 test files [V]. Tests: SQLite in-memory (`DB_CONNECTION=sqlite_testing`), `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `BROADCAST_CONNECTION=null` (`phpunit.xml`) [V] |
| Cache / session / queue | Defaults: cache `database`, session `database` (lifetime 480 min, `encrypt` false), queue `database` (`retry_after` 90 s) [V]. Rate limiting is therefore cache-backed, i.e. database writes in production |
| Queue execution | README documents a **cron every minute**: `queue:work --queue=default,audit --stop-when-empty --tries=3`, plus `schedule:run`; no daemon; an admin "manual queue run" bridge exists [V]. `QUEUE_CONNECTION` in production `.env` not read [U] |
| Scheduling | `routes/console.php` (not `Kernel`) [V] |
| Logging | Default stack channel = env `LOG_STACK`, default `single` (no rotation unless env overrides); `daily` channel keeps 14 days [V] |
| CSP | `ContentSecurityPolicy` middleware on the `web` group: `connect-src 'self' + active gateway hosts`, `script-src 'self' 'unsafe-inline' 'unsafe-eval'`, `frame-ancestors 'none'`. Provider calls would be server-side, so **no CSP change is needed** for AI; the permissive `script-src` means model output must never be rendered as HTML [V] |
| Deployment | cPanel, `.cpanel.yml` → `scripts/deploy.sh`; Laravel in `core/` [V per CLAUDE.md/`.cpanel.yml`]. Web-server buffering, PHP `max_execution_time` and worker count not verified [U] |
| Existing API | `routes/api_v1.php` mounted at `/api/v1` via `bootstrap/app.php` `then:` — stateless, outside the `web` group [V] |

**CLAUDE.md drift observed [V]:** it says "70+ migrations" (actual 121) and describes the developer platform only partially. README line 917 already flags several CLAUDE.md statements as stale. Not edited.

---

## 4. Existing Authentication and User Roles

### 4.1 Guards [V] (`config/auth.php`)

| Guard | Provider / model | Used by |
|---|---|---|
| `web` (default) | `App\Models\User` | Legacy admin representation only (`users.role === 'admin'`) |
| `vendor` | `App\Models\Vendor` | Vendors, resellers, affiliates, **and approved API developers** |
| `admin` | `App\Models\Admin` | Administrators |

All are session guards. There is no token/Sanctum guard; API keys are a separate, hand-rolled credential on `/api/v1` only.

### 4.2 Roles

| Role | Reality [V] |
|---|---|
| **Customer** | **No account, no model.** Guest checkout; identified only by session and by the data they type (recipient phone, payer phone). |
| **Vendor** | `Vendor` on guard `vendor`; middleware alias `vendor.approved` (`EnsureVendorApproved`): not signed in → vendor login; signed in but `is_approved` false → **force logout** + redirect. Prefix `/vendor/`. |
| **Reseller / affiliate** | Not a separate realm: they are `Vendor` rows. Reselling = `ResellerProduct` rows (`base_price` + `markup_price` → `selling_price`, always recomputed on save); affiliate chain via `affiliate_vendor_id`/`is_afa_affiliate`; `VendorTier` discounts. Commission/payout math lives in `AffiliateChainService`. |
| **Administrator** | `App\Support\AdminAccess` is the single **fail-closed** check (Admin on `admin` guard, or User on `web` with `role === 'admin'` exactly). Middleware `admin.only` / `cms.admin` are prepended *before* `SubstituteBindings` so unauthorized users cannot probe record existence. |
| **API developer** | **Not a separate account.** The Developer Portal (`/developers/*`, middleware `developer.access` = `EnsureVendorDeveloperAccess`) recognises the signed-in **approved vendor** (`VendorApiAccess::isEligible` → `vendors.is_approved === true`, read live per request). API requests authenticate with an API key (`api.key` = `AuthenticateApiKey`), which re-checks key not revoked/expired, application active, **owning vendor approved right now**, and key environment (`xt_test_` sandbox / `xt_live_` production). The key, not the application, decides the environment. |

### 4.3 Authorization mechanisms

* **Isolation is by query scoping, not policies [V].** Vendor dashboards filter `where vendor_id = <session vendor>`; portal routes load applications *through* `vendor_id`. Policies exist but are narrow: `DeveloperApplicationPolicy::manage(Vendor, DeveloperApplication)` (second layer), `Support*Policy`, `Ussd*Policy`. **`VendorPolicy` is typed to `App\Models\User`, so it does not govern the `vendor` guard's `Vendor` model** [V] — the AI layer must not lean on it.
* **Established "principal" pattern [V]:** `App\Support\Support\SupportPrincipal` — private constructor, built only from the authenticated session (`forVendor`, `forAdmin`), never from request input. The AI layer should copy this pattern (`AiPrincipal`).
* **Existing request attributes for API keys [V]:** `api.key`, `api.application`, `api.environment`.

---

## 5. Existing Business Services and Reusable Components

"Owner" = the class that must remain authoritative. The AI layer **calls read paths of these or is blocked from them; it never re-implements or bypasses them.**

| Operation | Authoritative owner [V] | AI may… |
|---|---|---|
| Public marketplace catalog | **Inline in `CheckoutController::show()`**: active products of approved vendors + active resellable reseller listings; metadata JSON lives *inside* `products.description` (`decodeDescription`) | Needs a read-only catalog query that mirrors these rules (no reusable service exists) — see below |
| API-scoped catalog | `Services\DeveloperApi\PublicCatalogService` (offer ids `own_{id}` / `rsl_{id}`; boundary applied inside queries; category `data` only) — **requires a `DeveloperApplication`, vendor-bound** | Pattern to copy; cannot be reused directly for the marketplace-wide customer view |
| Service open/closed | `Support\ServiceAvailability` (`isOpen`, `statuses`, `message`) | Read |
| Price truth | `Product.price`, `ResellerProduct.selling_price` (derived on save), frozen into orders by `Services\Payments\OrderPricingSnapshot` ("the browser is never a price input") | Read live price only |
| Order creation (web) | `CheckoutController::process()` (idempotency via `CheckoutIntentGuard`, scope computed server-side); a second path exists in `PurchaseController` (route `/purchase`; wallet-related tests exist; not audited in depth [U]) | **Never** |
| Order creation (API) | `ApiOrderService::createFromQuote` (orders carry `developer_application_id`) | **Never** |
| Payment init/verify | `GatewayManager` → `*PaymentService`; `PaymentService::initiatePayment` / `checkPaymentStatusForGateway` / `completeOrder` (1,405 lines, row-locked, idempotent); `PaymentIntegrityGuard`; `PaymentReconciliationService`; webhooks | **Never** |
| Wallet & commission | `WalletService` (`creditVendor`, `debitVendor`, `reverseOrderEarnings`, `getWithdrawableBalance`…), `AffiliateChainService`, snapshot in `orders.affiliate_chain_snapshot` | Read-only figures, via one blessed read path (see risk below) |
| Vendor transactions/stats | `TransactionService::vendorTransactionsQuery(int)`, `getVendorTransactionStatsByFilter`, `getVendorTransactionStats`, `getVendorEarnings`… | **Reusable read path** for sales/earnings |
| Withdrawals/payouts | `VendorDashboardController::requestWithdrawal`, `ProcessVendorWithdrawalPayout` job, `Services/Payouts/*` | **Never** |
| External fulfillment | `OrderCompleted` → `DispatchExternalFulfillmentFromOrderCompleted` → `ProcessExternalFulfillment` (claim/submit/recover, guarded updates); `SyncExternalFulfillmentStatuses` | **Never** |
| Result checker | `ResultCheckerService`, `ResultCheckerOrder` (`delivered_pins_json` is `encrypted:array`), public status lookup throttled 10/min | PINs **never**; status only via verified path |
| AFA | `AfaRegistration` holds `full_name`, `id_number`, `id_type`, `date_of_birth`, `phone_number`, `location`… (high-sensitivity PII) | **Never sent to a provider** |
| Utility bills | Platform-owned orders (`vendor_id` NULL) + `utility_bill_orders`; status behind an **opaque token**; "never served by sequential-id pages" | Out of AI scope in M5 |
| Notifications | `SendOrderPlacedCommunications`, `SmsService`, `VendorNotification` | Not used by AI |
| Support chat | `Services\Support\SupportService`, `SupportPrincipal`, per-reader unread, private attachments | Reuse the *patterns* (principal, ownership checks), not the tables |
| Developer API/Webhooks/Sandbox | See §6 | Selected read paths |

**Order visibility rule for vendors [V]** (`VendorDashboardController::index`): an order is visible to a vendor if `vendor_id = V` **or** `owner_vendor_id = V` **or** its `resellerProduct.owner_vendor_id = V` (legacy fallback), **and** `payment_status IN ('paid','completed')`, **and** `status != 'Pending'`. The AI vendor tools must reproduce this exactly, with a parity test, rather than inventing a simpler rule (a simpler rule either hides orders the dashboard shows or leaks ones it hides).

**Risks found in existing code that affect M5 [V]:**

* Dashboard maths is inline in the controller; the private `calculateWithdrawableBalance` and `WalletService::getWithdrawableBalance` both exist and were **not reconciled** in this audit [U]. The AI must quote exactly one agreed implementation (D11).
* `VendorSetting` stores per-vendor fulfillment API keys (written via `Crypt::encryptString` in `ExternalFulfillmentSettingsController`). It must be on the AI **deny list**.
* `products.description` is vendor-authored free text that also carries structured metadata. It is an **indirect prompt-injection vector** (§14).
* Vendor-initiated writes exist next to the read paths (`updateOrderStatus`, `addResellerProduct`, `requestWithdrawal`, `VendorQuickBuyController::store`). None may be reachable from a tool.

**Existing order tracking [V]:**

* `OrderStatusController::check`: phone only (`recipient_phone_number` exact match **or** `LIKE '%<last 9 digits>'`), limit ≤ 50, returns `id`, payment reference (or `ORD-` + id), service, amount, status, vendor name/code, timestamps. No throttle, no second factor.
* `OrderStatusController::poll`: accepts `order_ids[]` (integers) → status. No throttle, no ownership.
* `CheckoutController::success($orderId)` / `receipt(Order)`: sequential id; only utility-bill orders are excluded. What the receipt PDF discloses was not audited [U].
* `ResultCheckerStatusController` is throttled (10/min) — a better precedent.

**Conclusion:** no existing mechanism proves "this person owns this order". See §8.A and §12.4 for the recommended verification.

---

## 6. Existing Developer API Integration Opportunities

### 6.1 What exists [V]

`/api/v1` (stateless): `health`, `networks`, `services`, `products`, `products/{offer}`, `quotes`, `orders`, `orders/{order}`, `orders/{order}/status`, `orders/{order}/payments`, `transactions/{reference}`, and sandbox-only `sandbox/simulations`, `sandbox/orders/{order}/simulate`. Middleware chain `api.context → api.key → api.throttle → api.env`, then `api.scope:<scope>` per route; `api.sandbox` for `/sandbox/*`. Scopes (`config/developer_api.php`): `services:read`, `products:read`, `quotes:create`, `orders:create`, `orders:read`, `payments:initiate`, `webhooks:manage`, **`ai:chat` (unimplemented)**, `sandbox:simulate` (sandbox keys only; enforced in three places). Rate limit per **application** (override → plan → default 60/min), failed-auth limit per IP (20/min). Request log (`developer_api_requests`, metadata only, 30-day retention pruned daily). `DeveloperAuditLogger` strips meta keys containing `secret|key|hash|password|token`. Webhooks: SSRF-hardened, HMAC-signed, secrets `encrypted` cast. Sandbox: separate `developer_sandbox_*` tables and `sbx_` ids; simulated outcomes (per the M4 design notes; the sandbox code path was not re-read line by line here).

### 6.2 What an AI assistant can safely reuse

| Reuse | How | Why safe |
|---|---|---|
| `ApiError` constants (24 codes) | Source for `dev.explain_error` | Static, no data |
| `config('developer_api.*')` | Inject live scopes, rate limits, webhook events, quote TTL into the docs context | Docs cannot drift from config |
| Laravel route table filtered by name prefix `developer-api.` | Generate the endpoint list + required scope per endpoint at runtime | Ground truth; no OpenAPI doc exists yet (planned M7) [N] |
| `docs/api-platform/03-portal-and-webhooks.md`, `04-sandbox.md` | Hand-written prose corpus, loaded as static knowledge | Version-controlled, reviewed |
| `DeveloperDashboardService`, `DeveloperApiRequest` (own application only) | `dev.recent_requests`, usage summaries | Metadata only by design; query *through* `vendor_id` |
| `DeveloperApplication` list/keys via vendor-scoped queries | `dev.list_applications`, `dev.list_keys` | Return prefix/scopes/status/expiry only; the key hash and secret never leave the DB layer |
| `DeveloperApplicationPolicy::manage` | Second authorization layer | Existing, tested |

### 6.3 What it must not do

* No second API authentication system; the assistant uses the **portal's vendor session** (`developer.access`), not an API key.
* No weakening of vendor approval: the same live `is_approved` check gates the assistant.
* It must not call `/api/v1` on the developer's behalf, create/rotate/revoke keys, edit webhooks, or run sandbox simulations (it can *generate* the request for the developer to run).
* The `ai:chat` scope should not be turned into a public "AI over HTTP" endpoint in M5 (cost exposure per API key, no UI, no agreed pricing/plan). See D8.

### 6.4 Can the Developer Portal host an assistant? **Yes [V]**

`layouts/developers.blade.php` + `developer.access` gives an approved-vendor session, CSRF, CSP and Alpine already. It is the lowest-risk host for the first surface.

---

## 7. Existing AI Capabilities and Dependencies

| Search | Result [V] |
|---|---|
| Terms searched (openai, chatgpt, anthropic, gemini, llm, chatbot, embedding, vector, rag, langchain, gpt-, claude, assistant, "ai") across `*.php/json/js/md/yml/blade` excluding `vendor`, `node_modules`, `storage`, `.git`, `build` | No application AI code. Hits: `CLAUDE.md`/`README.md` (contributor-tooling notes), `docs/api-platform/00-audit-and-plan.md` ("No AI/OpenAI code or dependency anywhere"; B7 decision: OpenAI Chat Completions + tools, configurable model/base URL/price table), `config/developer_api.php` (`ai:chat`), a test name containing "vector" (XSS), and the CSP font host list |
| DB tables / migrations | No AI tables. (Name collisions with `ai_` in migration filenames are false positives: `external_fulfillment`, `payment_integrity`, `utility_bills`…) |
| Env vars | `.env.example` has no AI keys; `.env` was not opened |
| Composer | No AI packages. `php-http/discovery` is whitelisted in `allow-plugins` but not installed |
| Stray tooling | A `.venv` containing the Python `anthropic` package sits in the repo root; unrelated to the Laravel app, not tracked [V] |

**Therefore: there is nothing to reuse.** The 2025-11 plan's B7 decision (OpenAI via Chat Completions + tools, configurable base URL/model/price table, cost labelled as estimate) is the only prior design input.

### SDK compatibility inspection (nothing installed)

| Option | Verified compatibility | Assessment |
|---|---|---|
| `openai-php/laravel` v0.22.0 (Packagist, 2026-10-31) | Requires `laravel/framework ^11.57\|^12.70\|^13.0`, `guzzlehttp/guzzle ^7.9.3`, `openai-php/client ^0.22.0`. **Project is locked at 12.69.3 → does not resolve without a framework upgrade.** | Reject (would force an out-of-scope framework bump) |
| `openai-php/client` v0.22.0 | Requires `php ^8.2`, `psr/http-client ^1.0.3` (present), `php-http/discovery ^1.20`, `php-http/multipart-stream-builder ^1.4.2` (**neither installed**), PSR-18 client + PSR-17 factory implementations (Guzzle/Laravel supply them) | Would resolve, but: pre-1.0 (minor releases may break), community library [A — I did not verify who maintains it or whether an official OpenAI PHP SDK exists; check at M5.2], two new transitive deps on a cPanel host |
| **Laravel `Http` facade (Guzzle 7.15.5, already locked)** | Zero new dependencies. `Http::fake()`, `Http::preventStrayRequests()` and recorded-request assertions are already the project's pattern (`PortalTestCase`, `SandboxTestCase`, gateway tests) | **Recommended** |

Evaluation against the requested criteria:

| Criterion | HTTP client behind own interface | Community SDK |
|---|---|---|
| Laravel/PHP compat | Native | Version-gated (above) |
| Maintenance | Ours, ~300 lines per provider | Upstream, 0.x |
| Streaming | Supported (`stream` option / PSR body) but see §16 hosting caveat | Supported |
| Tool calling | Plain JSON we already model | Typed objects |
| Error handling / timeouts / retries | Fully ours: explicit connect/total timeouts, bounded retry on 429/5xx/connect only, deadline-aware | SDK defaults + wrapper anyway |
| Cost tracking | We parse `usage` ourselves (needed either way) | Same |
| Testability | `Http::fake` + a `FakeAiProvider` | Mock the client |

Which OpenAI endpoint (Chat Completions vs the newer Responses API) and which model is a **M5.2 decision to be made against OpenAI's current docs** [U — not verified here]; the `AiProvider` interface deliberately hides it.

---

## 8. Proposed AI Product Surfaces

Each surface is a distinct **principal + tool set + prompt + limits**, sharing one engine.

### 8.A Customer Assistant (anonymous guest) — [P]

* **Can do:** list networks; search/compare offers by network, size, price, vendor; explain differences; quote **current** prices; explain how buying works; answer FAQs; (gated) report the status of an order the visitor has **verified in this session**.
* **Data truth:** prices/availability only from catalog tools reading `Product.price`/`ResellerProduct.selling_price` + `ServiceAvailability`. UI cards for prices/statuses are **rendered by the server from tool results**; the model's prose is commentary. Output post-check flags any `GHS <amount>` in model text not present in this turn's tool results.
* **Order verification (recommended):** do **not** use the phone-only endpoint. Verification happens in a **server-rendered form outside the model** (so the secret never enters the prompt): reference (`payment_reference`) **and** the recipient phone must both match; uniform "no match" response; strict throttle (per session, per IP, per reference); on success the session stores `ai.verified_orders[order_id] = expires_at`. The tool `order.get_verified_status` only returns orders in that list and only coarse fields (status label, service name, date, amount) — no vendor contact, no payer number. Options: (B) reference + phone [recommended first]; (C) SMS OTP to the recipient phone via `SmsService` (costs money, abusable); (D) opaque status token issued at checkout, as Utility Bills already does. Entropy/format of `payment_reference` is gateway-dependent and **was not verified** [U] — confirm before choosing B.
* **Cannot do:** create orders, initiate payment, see other customers' data, see vendor private data.

### 8.B Vendor Assistant (approved vendor session) — [P]

* **Can do:** explain dashboard; list own products/reseller listings and prices; summarise own sales/earnings/commission (`TransactionService`); find own orders and explain statuses; explain own API usage; troubleshoot; "how do I…" help.
* **Identity:** `AiPrincipal::forVendor($vendor)` from `Auth::guard('vendor')` behind `vendor.approved`. No vendor id appears in any tool schema. Extra/unknown arguments are rejected, not ignored.
* **Cannot do:** any write (status updates, price/markup edits, listings, withdrawals, wallet top-ups, settings); read another vendor's anything; read `VendorSetting`, API keys, webhook secrets.
* **New work required:** a `VendorInsights` read service (see §5 "Order visibility rule") because the logic is trapped in a controller.

### 8.C Developer Assistant (approved vendor in the portal) — [P]

* **Can do:** explain endpoints (generated from the live route table + config), auth, scopes, rate limits, error codes, webhooks (signature, retry schedule, events), sandbox (`sbx_` ids, `sandbox:simulate`); generate example requests with **placeholder** credentials (`xt_test_YOUR_KEY`); explain errors; summarise the vendor's own application/key metadata and recent request log.
* **Cannot do:** reveal keys/secrets/hashes, other vendors' applications/webhooks/deliveries; run API calls; change anything.
* **Host:** `/developers/ai/*` behind `developer.access`.

### 8.D Admin Assistant — **out of M5 scope** (per brief)

Documented as a future possibility only: read-only operations triage (stuck/paid-unfulfilled orders, reconciliation notes explained from existing records), support-inbox reply drafting for humans to send. It would need its own principal (`AdminAccess`), its own audit trail, and separate approval. **Not planned.**

---

## 9. Recommended AI Architecture

### 9.1 Principles

1. The model **proposes**; Laravel **disposes**. Natural-language generation and tool execution are separate steps with an authorization gate between them.
2. One engine, three surfaces; surface is determined by the **route**, never by a request field.
3. No new infrastructure: database-backed everything, no vector DB, no embeddings (the knowledge base is small and static; retrieval = targeted tools + prompt-resident docs).
4. AI is optional: any AI failure degrades to "assistant unavailable" and never touches the marketplace.

### 9.2 Component map ([P], namespace proposal)

```
app/Services/Ai/
  Contracts/AiProvider.php            // 1. provider abstraction
  Providers/OpenAiProvider.php        //    Http-client implementation
  Providers/FakeAiProvider.php        //    scripted, for tests (never in prod binding)
  AssistantOrchestrator.php           // 2. turn loop: model → tool calls → model, bounded
  Conversation/ConversationService.php// 3. create/load/append, ownership enforced here
  Context/ContextBuilder.php          // 4. per-surface system context + history window + truncation
  Prompts/PromptRepository.php        // 8. versioned prompt files (resources/ai/prompts/{surface}/)
  Tools/Tool.php (interface)          // 5. name, JSON schema, surfaces, risk class, authorize(), handle()
  Tools/ToolRegistry.php              //    compile-time allow-list per surface
  Tools/ToolExecutor.php              // 6. validate → authorize → run → cap → redact → record
  Tools/Customer|Vendor|Developer/*   //    concrete tools
  Security/AiPrincipal.php            // 7. like SupportPrincipal; built from session only
  Security/Redactor.php               //    secrets/PII masking (in + out + logs)
  Usage/UsageMeter.php, BudgetGuard.php // 9. tokens, integer micro-USD cost, caps
  Safety/CircuitBreaker.php, KillSwitch.php // 11/12. concurrency cap, provider breaker, admin toggle
app/Http/Controllers/Ai/{Customer,Vendor,Developer}AssistantController.php
app/Models/Ai/*  config/ai.php  routes/ai.php  resources/ai/{prompts,knowledge}/
```

Mapping to the 12 requested components: provider abstraction (`AiProvider`), orchestration (`AssistantOrchestrator`), conversation management (`ConversationService`), context building (`ContextBuilder`), tool registration (`ToolRegistry`), tool execution (`ToolExecutor`), authorization (`AiPrincipal` + `Tool::authorize` + executor gate), prompt management (`PromptRepository`), usage tracking (`UsageMeter`), logging (DB tables + `Redactor`, §13), rate limiting (named limiters + `BudgetGuard`, §16), error handling (typed `AiException` hierarchy → safe user messages, §16). **Not introduced:** repositories, DTO frameworks, event buses, plugin systems, vector stores.

### 9.3 Request flow

```mermaid
sequenceDiagram
    participant U as Browser
    participant C as Surface controller
    participant G as Gates (auth, kill switch, limits, budget)
    participant O as AssistantOrchestrator
    participant P as AiProvider (HTTP)
    participant T as ToolExecutor
    participant S as Existing read services
    U->>C: POST /…/ai/messages {text}  (CSRF, session)
    C->>G: build AiPrincipal from session; kill switch; concurrency cap; throttles; budget
    G-->>C: ok / 429 / unavailable
    C->>O: handle(principal, conversation, redacted text)
    loop ≤ N iterations, within deadline
        O->>P: messages + tool schemas for THIS surface only
        P-->>O: text or tool_calls
        O->>T: tool_call(name, args)
        T->>T: registry allow-list → validate args → authorize(principal) → run
        T->>S: read-only query (principal-scoped)
        S-->>T: data
        T-->>O: capped, redacted result (+ UI card payload)
    end
    O-->>C: final text + server-rendered cards + usage
    C-->>U: JSON (text escaped, cards from tool results)
```

### 9.4 Where AI sits relative to the money path

```
 Customer ─► Assistant ─(read tools)─► catalog / availability
     │            │
     │            └─ "Confirm" button ─► proposal (single-use) ─► redirect ─┐
     ▼                                                                    ▼
 Existing checkout  ─► GatewayManager ─► gateway ─► webhook ─► PaymentService::completeOrder ─► OrderCompleted ─► fulfillment / wallet
 (unchanged, authoritative)                 ▲
                                            └── the AI layer has NO edge into this chain
```

---

## 10. Proposed AI Provider Strategy

* **`AiProvider` interface [P]:** `complete(AiRequest): AiResponse` (messages, tool schemas, limits, deadline → text | tool calls, `usage`, provider request id, finish reason) and `supportsStreaming(): bool` (stream method added only if D9 says yes). Business code never sees provider JSON.
* **`OpenAiProvider` [P]:** `Http::withToken(...)->baseUrl(config)->connectTimeout(5)->timeout(≤25)`; retries only on connect error / 429 / 5xx, at most one, with jitter, never past the turn deadline; maps failures to `AiProviderUnavailable`, `AiRateLimited`, `AiBadRequest` (no raw provider body to users). Base URL, model, price table (with an `as_of` date) in `config/ai.php`/DB `Setting`; **cost labelled "estimate"**.
* **Credential custody [P]:** not in git; follow the existing `UtilityBillCredentials` pattern (`Crypt::encryptString` into `Setting`, admin-editable, never logged, never echoed). D2.
* **Second provider:** the interface is the extension point; no second provider is built or required in M5.
* **Prompt caching / cost levers:** keep the static system prompt + tool schemas as a stable prefix so provider-side caching applies [A — provider feature, verify at M5.2]; small history window; tool results capped.
* **Model choice** is configuration, per surface, with a cheap default for the anonymous customer surface (D3).

---

## 11. AI Tool Architecture and Permission Matrix

### 11.1 Mechanics ([P])

* `Tool` implementations are the **only** bridge from model output to application code. `ToolRegistry` holds a **per-surface allow-list**; the provider request contains only that surface's schemas, and `ToolExecutor` rejects any call whose name is not in the surface's list (a model hallucinating `vendor.sales_summary` on the customer surface gets "unknown tool").
* Parameters: Laravel `Validator` per tool; unknown keys rejected; **no identity parameters exist** (no `vendor_id`, `application_id`, `customer_id`); numeric/length caps; enum filters.
* `Tool::authorize(AiPrincipal)` runs **before** `handle()`; ownership is enforced by principal-scoped queries (the existing house style), with policy calls as a second layer where a policy exists.
* Result contract: a typed array with a **field allow-list**, hard size cap, `Redactor` pass, and `provenance`. Results re-enter the prompt wrapped as *data*, never as instructions.
* **Classification (honest):** `READ` = no DB write at all. `DRAFT` = writes an AI-owned row (e.g. a proposal) and nothing else. `HANDOFF` = produced by an HTTP endpoint triggered by a human click, not by a tool call. Anything that writes is **not** called "read-only".
* Every call is recorded (`ai_tool_calls`): tool, redacted args, status, duration, error code — not full results.

### 11.2 Matrix (C = customer guest, V = vendor, D = developer)

| Tool | C | V | D | Class | Data source | Notes |
|---|:-:|:-:|:-:|---|---|---|
| `catalog.list_networks` | ✔ | ✔ | | READ | new `AiCatalogQuery` (mirrors `CheckoutController::show()` rules) | |
| `catalog.search_offers` | ✔ | ✔ | | READ | same | limit ≤ 10, filters network/size/price/vendor/sort |
| `catalog.compare_offers` | ✔ | ✔ | | READ | same | ≤ 4 offer ids |
| `catalog.get_offer` | ✔ | ✔ | | READ | same | price re-read live |
| `service.availability` | ✔ | ✔ | | READ | `ServiceAvailability` | |
| `help.search_faq` | ✔ | ✔ | ✔ | READ | static `resources/ai/knowledge/*` | |
| `order.get_verified_status` | ✔ | | | READ | `Order` by session-verified id only | needs D4 |
| `purchase.prepare_summary` | ✔ | | | **DRAFT** | catalog + new `ai_purchase_proposals` | writes a proposal row; **no order, no gateway** |
| `vendor.profile_summary` | | ✔ | | READ | `Vendor` (no password/secrets) | |
| `vendor.list_products` | | ✔ | | READ | own `Product` + own `ResellerProduct` | |
| `vendor.sales_summary` | | ✔ | | READ | `TransactionService` stats | period enum |
| `vendor.list_orders` | | ✔ | | READ | `VendorInsights` (dashboard rule) | masked phones, paginated |
| `vendor.get_order` | | ✔ | | READ | `VendorInsights` | ownership by dashboard rule |
| `vendor.wallet_summary` | | ✔ | | READ | one blessed balance method | D11 |
| `vendor.api_usage` | | ✔ | | READ | `DeveloperDashboardService` / `DeveloperApiRequest` | |
| `vendor.explain_status` | | ✔ | | READ | static | |
| `dev.api_reference` | | | ✔ | READ | live route table + `config/developer_api.php` + `docs/api-platform/*.md` | |
| `dev.explain_error` | | | ✔ | READ | `ApiError` + static text | |
| `dev.list_applications` | | | ✔ | READ | `DeveloperApplication` via `vendor_id` | |
| `dev.list_keys` | | | ✔ | READ | `DeveloperApiKey` — prefix, env, scopes, expiry, revoked, last used | never hash/secret |
| `dev.recent_requests` | | | ✔ | READ | `DeveloperApiRequest` (own app) | metadata only |
| `dev.webhook_status` | | | ✔ | READ | own `DeveloperWebhook`/deliveries | no secret, no payload bodies |
| `dev.generate_example` | | | ✔ | READ | template engine | placeholder keys only |

### 11.3 Prohibited operations and how each is enforced

| Prohibited | Enforcement |
|---|---|
| Mark paid / bypass verification / complete callbacks / credit wallets / change commissions / withdrawals / payouts / execute fulfillment / allocate PINs / modify inventory / change vendor approval / change API keys, scopes or webhook security | **Structural absence:** no tool exists; the registry is the only door; there is no generic "call service/method" tool. Backed by an **architecture test** that fails CI if any class under `App\Services\Ai\Tools` imports `PaymentService`, `GatewayManager`, `*PaymentService`, `WalletService` (write methods), `ProcessExternalFulfillment`, `ResultCheckerService`, payout services, `ApiKeyService`, `WebhookService`, or uses `DB::statement/raw`, `Http::`, `exec/shell_exec/proc_open`, `eval`, `Storage`, `file_*`. |
| Arbitrary SQL / PHP / shell / HTTP / file access / arbitrary service invocation | No tool accepts code, SQL, URLs, paths or class names. The only outbound HTTP is `OpenAiProvider` to the configured host; tests run under `Http::preventStrayRequests()`. |
| Writes caused by model text | Model output has no side-effect path except `ToolExecutor`; the only write-adjacent action (proposal confirm) is an ordinary CSRF-protected HTTP endpoint a human triggers. |
| Privilege escalation across surfaces | Route → surface; `AiPrincipal` surface-typed; executor checks tool∈surface and principal role. |
| Prompt override of permissions | Authorization never reads conversation content or model-provided identity. |

---

## 12. Purchase Confirmation and Checkout Handoff

### 12.1 Recommendation

**M5 does not let the AI create orders or initiate payments.** It prepares an exact summary, obtains an explicit human confirmation bound to those exact details, then **hands the customer to the existing checkout**, which remains the sole owner of `Order` creation, `CheckoutIntentGuard`, `GatewayManager`, `PaymentIntegrityGuard` and everything downstream. Rationale: the existing path is hardened over many incidents (duplicate-charge guards, integrity states, reconciliation); a second order-creation path owned by a non-deterministic component adds risk with no benefit. (A future "AI-confirmed server-side order" would reuse the same primitives as `ApiOrderService` and is explicitly **not** part of M5.)

### 12.2 Flow

```mermaid
sequenceDiagram
    participant U as Customer
    participant A as Assistant (model)
    participant S as Server
    U->>A: "I want the 5GB MTN bundle"
    A->>S: tool catalog.search_offers / get_offer  (live price)
    A->>S: tool purchase.prepare_summary(offer_id)
    S-->>U: SERVER-RENDERED card: vendor, product, price, validity + recipient-number input
    U->>S: submits recipient number in the card form (never typed into chat)
    S->>S: validate via GhanaPhoneNumber; re-resolve offer; create proposal (pending, expires ≈10 min, bound to session+conversation, details_hash)
    S-->>U: card shows exact recipient, vendor, product, total + [Confirm and continue to checkout]
    U->>S: POST /ai/proposals/{id}/confirm   (CSRF, session-bound, throttled)
    S->>S: re-resolve live price == snapshot? status pending→confirmed atomically (single use)
    S-->>U: redirect to existing checkout for that offer + recipient
```

### 12.3 Binding and replay protection ([P])

`ai_purchase_proposals`: `id` (ULID), `conversation_id`, `owner_key_hash` (session), `offer_ref` (`own_`/`rsl_` + id), `vendor_id`, `product_name_snapshot`, `price` (decimal string), `currency`, `recipient_phone` (encrypted cast), `details_hash` = HMAC-SHA256 (server key) over canonical JSON of all of the above + `expires_at` + nonce, `status` (`pending → confirmed → consumed`, or `expired`/`superseded`/`cancelled`), timestamps. The client submits **only the proposal id**; every detail is read server-side, so a tampered form cannot change product/amount/vendor/recipient. Confirmation = a single conditional `UPDATE … WHERE status='pending' AND expires_at > now() AND owner_key_hash = ?` (race-safe on SQLite/MySQL/MariaDB); a replay finds `confirmed/consumed` and gets the same idempotent redirect or a refusal, never a second proposal effect. **If the live price differs at confirm time, the proposal is superseded and the customer must re-confirm the new number.** Confirmation never moves money: payment is still authorised by the customer at the gateway.

### 12.4 Unresolved dependency — checkout prefill ([V] no prefill exists)

`resources/views/checkout.blade.php` shows no query-string preselection support. Options: **H1** add a minimal, opt-in prefill (a signed/opaque proposal token read by `CheckoutController::show()` → preselect offer + recipient; the existing `process()` still re-prices server-side, as `CheckoutAuthoritativePricingTest` requires) — **modifies existing checkout code, needs approval (D5)**; **H2** send the customer to the vendor storefront page and let them re-select (no existing-code change, worse UX); **H3** show the summary and instruct them (weakest). Recommend H1, implemented as a separate, small, separately-reviewed change in the customer milestone.

---

## 13. Conversation and Database Design

### 13.1 Persist? **Yes, with minimisation.** Needed for continuity, abuse review, cost accounting, support disputes ("what did the assistant tell me?"). Stored text is **redacted before storage** (and before it is sent to the provider).

### 13.2 Ownership design — decision

| Option | Pros | Cons |
|---|---|---|
| **A. Explicit owner columns (recommended)** — `surface`, `vendor_id` (nullable), `developer_application_id` (nullable), `guest_key_hash` (nullable) | Real indexes/FKs, cheap per-vendor limits and purges, no type-string to forge, trivially testable ("exactly one owner kind per surface") | A new owner kind later = a column |
| B. Polymorphic `owner_type/owner_id` | Flexible | No FK integrity; customers have **no model** to point at, so guests don't fit; vendor-scoped queries and limits get awkward; owner-type string is an extra attack surface |

Constraint: exactly one owner shape per `surface` (app-level, plus a `CHECK` where the engine enforces it — existing migrations already treat CHECK as best-effort). **No FK to `orders`** (history of a MyISAM `orders` table in production — see engine assertion in `2026_10_07_000002_create_utility_bills_tables.php`); FK to `vendors` only with the same engine assertion, otherwise a plain index (the project has used plain indexes elsewhere for this reason).

### 13.3 Tables ([P], not created)

* `ai_conversations`: `id` (ULID), `surface`, owner columns above, `title` (nullable), `prompt_version`, `provider`, `model`, `status`, `message_count`, `last_activity_at`, timestamps.
* `ai_messages`: `id`, `conversation_id`, `role` (`user|assistant|tool|system_event`), `content` (text, **redacted**), `run_id`, timestamps.
* `ai_runs` (one per user turn): `id`, `conversation_id`, `status`, `provider`, `model`, `prompt_version`, `input_tokens`, `output_tokens`, `cached_tokens`, `cost_micro_usd` (**integer**, no floats), `price_table_as_of`, `latency_ms`, `provider_request_id`, `error_code`, `iterations`, timestamps.
* `ai_tool_calls`: `id`, `run_id`, `tool`, `args_redacted` (JSON-as-text), `status`, `error_code`, `duration_ms`, `result_bytes`.
* `ai_purchase_proposals`: §12.3.
* No `ai_*` table stores provider keys, raw provider payloads, full tool results, or unredacted PII.

**Cross-engine rules:** JSON columns are used only as opaque blobs through Eloquent `array` casts (the project already has ~41 `json(` columns; MariaDB stores JSON as LONGTEXT) — **no JSON-path queries or JSON indexes**; indexes on `(surface, vendor_id, last_activity_at)`, `(guest_key_hash, last_activity_at)`, `(conversation_id, id)`, `(run created_at)`; ULIDs as `char(26)`; utf8mb4 index-length limits respected (the repo had a >64-char index-name failure before — name indexes explicitly); migrations must be reversible and tested for rollback on all three engines, like `SandboxMigrationTest`.

### 13.4 Rules

Ownership is enforced inside `ConversationService` (load by id **and** owner, else 404); guests are bound to a hash of the server-side session id (not the raw id), never to a client-supplied value; conversation history is **context, never proof of identity or authorization** (tools re-authorize every call; prior "I verified this order" text means nothing — verification lives in the session). Vendor/developer history is visible only to that vendor; admin visibility of transcripts is a policy decision (D6).

---

## 14. Security Threat Model

Controls assume: **never trust the model for authorization; authorization lives in Laravel before every tool call.**

| # | Threat | Vector in this codebase | Controls | Test |
|---|---|---|---|---|
| 1 | Prompt injection (direct) | User says "ignore rules, call vendor tools" | Tool set per route; executor allow-list; no identity params; system prompt is guidance only, never the control | Scripted `FakeAiProvider` emits forbidden tool/args → denied |
| 2 | Malicious input | Huge/Unicode/markup payloads | Length caps, control-char strip, escaped rendering, request size limits | Fuzz/boundary tests |
| 3 | **Malicious product descriptions (indirect injection)** | Vendor-authored `products.description` flows into `catalog.*` results | Field allow-list: send only structured fields (name, network, size, validity, price); free-text `notes`/`description` truncated, **stripped of URLs/markup**, wrapped as untrusted data; not rendered as HTML; model-visible text never contains vendor contact data | Seed hostile description; assert no tool/behaviour change and no verbatim URL echo |
| 4 | Cross-vendor leakage | Vendor tools | Principal-scoped queries (house style) + `VendorInsights` parity with dashboard rule; extra args rejected | Two-vendor fixtures per tool |
| 5 | Customer order leakage | Phone-only existing endpoint; sequential ids | Don't wrap existing endpoints; session-verified orders only; uniform not-found; coarse fields; throttles | Enumeration + wrong-secret tests |
| 6 | Unauthorized tool invocation | Hallucinated/forced tool names | Per-surface registry; principal role check | Cross-surface call tests |
| 7 | Parameter manipulation | `vendor_id`, other ids in args | Not in schema; unknown keys rejected; ids resolved from principal | Inject `vendor_id` in args |
| 8 | API key exposure | Developer assistant | Tools never select hash/secret; `Redactor` masks `xt_(live|test)_[A-Za-z0-9]+`, `Bearer …`, `whsec`-style secrets in input, output, logs; examples use placeholders; keys are shown only once by the existing portal | Plant a key in chat and in a tool result; assert absent from provider payload, DB, logs |
| 9 | Sensitive data to provider | Phones, AFA PII, PINs, payment refs, balances | Allow-list per tool; recipient number entered in a server form not chat; AFA/PIN/Ghana-card data never in any tool; phone masking; D2/D11 | Payload-capture test on `Http::fake` asserts absence |
| 10 | History leakage | Guest session reuse, shared computers | Owner binding, short idle expiry, "clear conversation", retention purge | Cross-session access → 404 |
| 11 | Cost abuse | Anonymous customer endpoint | Per-session/IP/vendor throttles, token caps, daily per-principal and global USD caps, kill switch, cheap model default, history window | Budget exhaustion tests |
| 12 | DoS | Sync calls hold PHP workers | **Global in-flight cap**, short deadlines, circuit breaker, separate throttle buckets, AI routes isolated from payment/webhook routes | Concurrency-cap test |
| 13 | Wrong prices | Hallucination | Server-rendered price cards from tool results; output scan for unsupported amounts; confirm step re-prices; checkout re-prices anyway (`OrderPricingSnapshot`) | Fake provider lies about price → flagged |
| 14 | Wrong order status | Hallucination / stale | Status card server-rendered with "as of" time; model told statuses come only from tool | Lying provider test |
| 15 | Confirmation replay / tamper | Re-POST, edited form | §12.3 single-use, session-bound, hash-bound, TTL, price re-check | Replay / other-session / expired / price-changed tests |
| 16 | Developer-tool abuse | Probing other apps | Load via `vendor_id`; policy second layer; 404-not-403 for foreign ids | Cross-vendor app/webhook tests |
| 17 | XSS via model output | CSP allows `'unsafe-inline'`/`'unsafe-eval'` | Plain text / restricted Markdown only (evaluate reuse of `App\Support\Cms\CmsMarkdown`, which has a script-vector test), same-origin link allow-list, no `x-html` on model text | Hostile `<script>`/`javascript:` output tests |
| 18 | Secret leakage in logs | `LOG_STACK=single` default | AI never logs bodies to `laravel.log`; DB tables with retention; `Redactor` before any write | Log-capture test |

**Existing controls reviewed [V]:** rate limiting is per-route `throttle:` plus named limiters (`utility-lookup`) and per-application API buckets (cache-backed); audit trails exist (`DeveloperAuditLogger` with secret scrubbing, `WalletLedger`, `UtilityBillEvent`, `SupportConversationEvent`, `AdminExportAudit`); `Crypt` encryption for gateway config, PINs (`encrypted:array`), webhook secrets, USSD and utility-bill credentials; API request log stores metadata only. Not covered by anything existing: AI transcripts, provider-bound data flows, per-principal cost budgets.

---

## 15. Privacy and Data Retention Considerations

This section lists questions and facts, **not legal conclusions.**

* **Current Privacy Policy** (seeded default in `CmsRegistry::systemRichPages()`, admin-editable in the CMS so the live text may differ [U]) lists collected data (name, phone, email, account/transaction details, login/usage data), says XTRA4U "does not sell or share your personal information with third parties, except where required by law or necessary to deliver our services". **It contains no mention of AI processing, providers/sub-processors, retention periods, cross-border transfer, or user rights.** The Terms say payments are final once delivered and users must confirm details; they contain nothing about AI advice being non-binding.
* **Business decisions needed:** is sending chat text/tool results to a third-party AI provider "necessary to deliver our services"? Disclosure wording and placement (inline notice in the widget, policy update); consent vs notice; provider contract (data retention, training use, zero-data-retention option, region), and the platform's obligations under Ghana's Data Protection Act, 2012 (Act 843) including any registration and cross-border-transfer requirements — **to be confirmed with counsel; not assessed here.** I did not verify any provider's current terms.
* **Data minimisation proposal:** per §14 #9. Never sent: names, emails, phone numbers (masked), AFA/ID data, PINs, payment references, API keys/secrets, full wallet history. Vendor financial totals only if D11 = yes.
* **Retention (proposal, values are D6):** `ai_*` rows purged by a scheduled `ai:prune` in `routes/console.php`, matching house patterns (`developer-api:prune-request-log` 30 d daily, USSD sessions 7 d / logs 90 d, sandbox opt-in). Suggested starting point to approve: customer 30 d, vendor/developer 90 d, `ai_runs` usage/cost rows 13 months aggregated; user-initiated "delete conversation"; guest rows keyed by session hash expire with idle timeout. Provider-side retention is outside our control (D2).
* **Existing practice noted:** default `LOG_STACK=single` means application logs don't rotate unless env overrides; hence AI content must not be logged there.

---

## 16. Performance and Cost Management

### 16.1 Execution model — decision

| Option | Verdict |
|---|---|
| Queue + poll | **Rejected as default:** cron worker with `--stop-when-empty` gives up to ~60 s pickup latency; manual-run bridge is admin-only; no websockets/Reverb/Redis. Acceptable only for non-interactive jobs (`ai:prune`). |
| **Synchronous JSON turn** | **Recommended first.** One HTTP request = one turn; provider connect 5 s / total ≤ 25 s; turn deadline ≈ 40 s; ≤ 4 tool iterations. Simple, testable, no infra. |
| Streaming (SSE) | **Defer (D9).** Needs verification that the cPanel web stack doesn't buffer, that PHP time limits allow it, and it holds a worker even longer. Add only after a hosting spike. |

### 16.2 Protecting the marketplace

AI routes live in their own route file/prefix; **no AI code runs in checkout, payment callbacks, webhooks, `OrderCompleted` listeners or queues.** Controls: `KillSwitch` (admin DB setting + config, checked first, hides the widget); **global in-flight concurrency cap** via cache lock/counter (excess → immediate "busy" JSON, no provider call); `CircuitBreaker` (N provider failures in M minutes → open for T minutes → "assistant unavailable"); all failures are caught and returned as friendly JSON, never a 500 that leaks internals, and never affect other routes.

### 16.3 Limits (values are proposals for M5.2/M5.7)

* Rate limits (named limiters, house style): customer per-session + per-IP; vendor per vendor id; developer per vendor id. Order-verification attempts have their own stricter limiter.
* Token limits: max user message length, max history window (turns/tokens), max tool-result bytes, max completion tokens per surface.
* Budgets: per-principal daily tokens/cost, global daily USD cap with warning/hard-stop, enforced by `BudgetGuard` using cache counters with `ai_runs` as the audit source of truth. Possibly tied to `VendorTier` for vendors (D15).
* Cost accounting: integer micro-USD from provider `usage` × configured price table (`as_of` recorded); labelled as estimates; admin report/alert in M5.7.
* **Cache backing:** rate-limit/budget counters hit the database cache store in production — acceptable at this scale; revisit if AI traffic is large.

---

## 17. Frontend Integration Plan

* **Recommendation: one shared component, three server-enforced surfaces.** `resources/views/components/ai/chat.blade.php` + one Alpine component (`resources/js/ai-chat.js`, imported in `app.js`) rendering message list, input, typing state, error/limit states, and **card slots** (offer card, comparison table, purchase summary, order status, API example/code block). Cards render from structured tool results; model text is plain/escaped. The surface is chosen by **which route the widget posts to**, not by a prop the browser can alter. Separate UIs would triple the XSS/CSRF surface for no gain; separate *endpoints, principals, tools, prompts and limits* give the isolation.
* **Customer:** floating button on the public layout (`layouts/app`), mobile-first bottom sheet (primary audience on phones), product cards with network image (reuse `NetworkService` image lookup), comparison cards, summary card + confirm button, order-verification form (outside the model).
* **Vendor:** drawer in `layouts/vendor`, behind `vendor.approved`; deep links to existing dashboard pages instead of re-displaying data where possible; suggested prompts ("Explain today's sales").
* **Developer:** panel in `layouts/developers`, behind `developer.access`; code blocks with copy button; placeholder-key banner; "sandbox vs production" badge from the vendor's current context.
* **Security specifics:** CSRF via the standard `web` group (a `/csrf-token` route already exists); `connect-src 'self'` already sufficient; no `x-html` on model text; link allow-list; disclosure line "AI-generated — check prices before paying"; widget script not rendered when the kill switch is on. Language: English first; Twi/Pidgin support is a product question (D10).

---

## 18. Testing Strategy

Conventions to reuse [V]: `Http::fake()` + `Http::preventStrayRequests()` (`PortalTestCase`), purpose-built base cases (`DeveloperApiTestCase`, `SupportTestCase`, `UtilityBillTestCase`), `RefreshDatabase` on `sqlite_testing`, migration up/down tests (`SandboxMigrationTest`), opt-in MySQL tests (`tests/Feature/UtilityBills/MySql`). **All provider traffic is faked; a `FakeAiProvider` returns scripted text/tool calls, including malicious ones. No live AI, payment or fulfillment credentials in any test.**

**Unit:** provider mapping (request/response/usage/errors/timeouts/retry bounds); prompt construction + versioning; tool registry per-surface allow-list; tool arg validation (unknown keys, caps); authorization; `Redactor` (keys, bearer tokens, phones, ids); usage/cost maths (integer micro-USD, price table); conversation ownership; proposal hash/state machine; **architecture test** (forbidden imports in `Tools\*`, no `Http::` outside the provider).

**Feature:** guest customer chat; verified order lookup (right/wrong secret, enumeration, throttle); vendor assistant auth (guest/unapproved/suspended-mid-session → blocked); **cross-vendor isolation for every vendor tool**; dashboard-parity (assistant totals == `VendorDashboardController` totals for the same fixtures); developer permissions (other vendor's app/keys/webhooks → 404; key hash/secret never in output); unauthorized/hallucinated tool calls; purchase confirmation valid / invalid / expired / price-changed / replay / other-session / tampered fields; rate limits and budgets; kill switch; provider failure (timeout, 429, 5xx, malformed JSON) → graceful degradation with checkout unaffected; conversation persistence + retention purge; AI routes isolated from payment routes.

**Security:** prompt-injection corpus (direct and via product description / vendor name); `vendor_id` injection; unauthorized tool calls; redaction in provider payload, DB and logs; cross-user conversation access; confirmation tampering; hostile model output rendering (XSS).

**Database matrix:** run the AI suite and migrations up/down/up on **SQLite, MySQL 8.4, MariaDB 11.4.13** using the recipe already proven in M4 (project notes: scratch `mysqld`/`mariadbd` on ports 3307/3308, export `DB_*` env vars because `phpunit.xml` doesn't override). Watch: JSON-as-text columns, explicit index names (≤ 64 chars), collation case-insensitivity (fixtures differing only by case collide on MySQL), no FK to `orders`, rollback leaving shared tables intact, atomic conditional `UPDATE` for proposals under concurrency (use the opt-in worker-process pattern from `UtilityBillMySqlConcurrencyTest`).

**Suite hygiene [A, from project notes]:** the full suite has known pre-existing failures and OOMs single-process — run split suites and baseline first; the full suite was **not** run in this audit.

---

## 19. Proposed M5 Implementation Phases

Recommended sequence (changes from the brief marked ★).

| Phase | Scope | Exit criteria | Why here |
|---|---|---|---|
| **M5.1** | This audit | Approved decisions D1–D3 minimum | — |
| **M5.2 Foundation** | `config/ai.php`; `AiProvider`, `OpenAiProvider`, `FakeAiProvider`; `AssistantOrchestrator`; ConversationService; migrations (`ai_conversations/messages/runs/tool_calls`); `Redactor`; `UsageMeter`; ★ **KillSwitch, concurrency cap, circuit breaker, per-principal throttles and budgets here, not in M5.7** (they are prerequisites, not polish); admin settings for key/model/caps; `ai:prune`. No UI beyond a test harness. | Orchestrator passes the fake-provider suite; migrations pass on 3 engines; zero effect on existing suites | Everything else depends on it |
| **M5.3 Tool system** | `Tool`/`ToolRegistry`/`ToolExecutor`; architecture test; catalog + availability + FAQ tools (`AiCatalogQuery` with parity tests vs `CheckoutController::show()` visibility rules) | Per-surface allow-list, validation, authorization proven by tests | Safe shared base |
| **M5.4 ★ Developer assistant** | Portal widget, route-table/config-generated reference, error explanations, examples, own-app metadata tools | Cross-vendor tests green; secrets never emitted | Lowest-risk, authenticated, mostly static data → validates the whole stack |
| **M5.5 Vendor assistant** | `VendorInsights` read service + dashboard-parity tests; sales/orders/products/wallet tools | Parity + isolation tests green | Highest data-sensitivity, but authenticated and bounded |
| **M5.6 ★ Customer assistant** | 5.6a discovery/compare (read-only); 5.6b order verification (needs D4); 5.6c purchase summary + proposal + handoff (needs D5 and the checkout prefill change) | Abuse/budget tests green; replay/tamper tests green | Anonymous, cost-abusable, injection-exposed, depends on most product decisions → ship last |
| **M5.7 Hardening & release** | Security review, load/concurrency test on staging hosting, monitoring dashboards/alerts, retention tuning, privacy-policy rollout, runbook, staged rollout via flags | Sign-off checklist | |

The brief's order (customer first) is viable if the business prioritises revenue features; the cost is exposing the riskiest surface before budgets/monitoring have run on real traffic. Either way, **ship behind per-surface flags and enable surfaces separately.** M5.6a/b/c can be released independently.

---

## 20. Risks and Dependencies

| Risk / dependency | Impact | Mitigation |
|---|---|---|
| Sync AI calls exhaust PHP workers on shared hosting | Checkout slowdown (the hard constraint) | Concurrency cap, short deadlines, breaker, kill switch; **staging load test before enabling** [PHP/worker limits unverified] |
| Pre-existing phone-only tracking and sequential-id success/receipt pages | Data exposure unrelated to AI but an AI "order status" feature could look like it endorses it | Don't wrap; separate hardening ticket (D12) |
| Reserved `ai:chat` scope grantable today | Misleading scope on production keys | Hide/remove (D8) |
| Vendor financial logic trapped in a controller | Mismatched numbers; duplicated rules | `VendorInsights` + parity tests; one blessed balance method (D11) |
| Checkout has no prefill | Handoff needs an existing-code change | D5; small, isolated, separately reviewed |
| Hallucinated prices/statuses | Customer harm, disputes | Server-rendered cards, output scan, re-price at confirm and at checkout |
| Provider outage / cost spikes / vendor lock-in | Degraded UX / bill | Breaker, caps, interface, config-driven model |
| Privacy/legal | Compliance exposure | D2, D6, D7; counsel review |
| MariaDB/MySQL/SQLite divergence | Migration or query breakage | 3-engine matrix, no JSON-path queries |
| Queue is cron-driven | No real-time async | Sync design; queue only for pruning |
| Test-suite baseline (pre-existing failures/OOM per project notes) | Noisy CI signal | Baseline first, split runs |
| Prompt-injection is never fully solvable | Residual risk | Defense in depth: the model has no dangerous capability to abuse |

Dependencies: provider account + terms (D2), budget (D3), product decisions D4–D7, hosting verification (PHP limits, buffering), copy/design for disclosure UI.

---

## 21. Decisions Requiring Product Owner Approval

| # | Decision | Recommendation | Blocks |
|---|---|---|---|
| D1 | Build order | Foundation → Tools → Developer → Vendor → Customer → Hardening (§19) | M5.2 planning |
| D2 | AI provider relationship: account owner, DPA/data terms, retention/training/ZDR options, region, key custody (encrypted `Setting`) | Resolve before the first **real** call (M5.2 can proceed on the fake provider) | First live call |
| D3 | Model(s) and budgets: per-surface model, per-principal and global daily caps, alert thresholds | Cheap model for anonymous surface; hard global cap + kill switch | Live enablement |
| D4 | Customer order lookup method | (B) reference + recipient phone in a server form, session-verified; (C) SMS OTP as upgrade; none until decided. Confirm `payment_reference` entropy first | M5.6b |
| D5 | Permission to modify existing checkout for opt-in prefill (H1) vs weaker handoff (H2/H3) | H1 as a separate small change | M5.6c |
| D6 | Retention periods; who may read transcripts (vendor own history? admin support access?) | 30 d customer / 90 d vendor+developer; admin access only via an audited path | M5.2 schema/prune defaults |
| D7 | Privacy Policy/Terms updates, AI disclosure wording, consent vs notice | Update both; inline disclosure in the widget; counsel review | Public launch |
| D8 | `ai:chat` API scope: hide now / implement a public endpoint later | Hide from scope lists immediately; don't build a public AI API in M5 | — (small change to existing config/listing; needs approval) |
| D9 | Streaming (SSE) | Not in v1; hosting spike first | — |
| D10 | Languages (English only vs Twi/Pidgin/others) | English first; evaluate based on market input | Prompt design |
| D11 | May the vendor assistant send financial totals (earnings, wallet balance) to the provider; which single balance implementation is authoritative | Allow aggregates for the vendor's own data after D2; reconcile `WalletService::getWithdrawableBalance` vs the controller's private calc first | M5.5 |
| D12 | Separate hardening of `/order-status/check`, `/order-status/poll`, `/checkout/success|receipt/{order}` | Open a ticket; out of M5 | — |
| D13 | Admin assistant | Stay out of M5; revisit after M5.7 | — |
| D14 | Handling of abusive/unsafe chat content (provider moderation? block lists?) | Basic length/rate controls in M5; moderation optional | M5.6 |
| D15 | Vendor AI quota policy (flat vs tied to `VendorTier`) and whether the assistant is a paid/tiered feature | Flat small quota first | M5.5 |

---

## 22. Final Recommendation

**Proceed to M5.2 once D1 is confirmed and D2/D3 are in progress** (they are only needed before the first live provider call). Build the engine as a thin, provider-agnostic, database-only layer; make tools the only door into the application; keep money, fulfillment, wallets and approvals entirely outside the AI's reach; hand purchases to the existing checkout; ship developer → vendor → customer surfaces behind independent flags. Do **not** install `openai-php/laravel` (framework-version conflict), do **not** build streaming, vector search or a public AI API in this milestone, and do **not** put the assistant in front of the existing phone-only order tracking. Treat the pre-existing tracking/receipt exposure and the `ai:chat` scope as separate decisions raised by this audit, not as M5 scope.

### Assumptions and unresolved items (consolidated)

[U] production PHP version/limits/web-server buffering; production `.env` queue/log settings; live CMS privacy/terms text; `payment_reference` entropy/format; what `/checkout/receipt` discloses; role of `PurchaseController` in depth; reconciliation of the two balance calculations; which OpenAI API/model to use (verify at M5.2); who maintains `openai-php/client`/whether an official PHP SDK exists; provider terms. [A] 3-engine verification of M4 per project notes (not re-run); provider-side prompt caching availability; existing test-suite baseline per project notes.
