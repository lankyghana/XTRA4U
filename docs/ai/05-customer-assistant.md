# M5.6 — Customer Assistant

Status: implemented, **uncommitted, undeployed, disabled by default, mock provider only**. Builds on `docs/ai/00-audit-and-architecture-plan.md`, `01-foundation.md` (M5.2), `02-tool-system.md` (M5.3), `03-developer-assistant.md` (M5.4) and `04-vendor-assistant.md` (M5.5), all unchanged in design.

**What this is:** a read-only, informational, stateless assistant on the public customer site (`/assistant`). A visitor, with no account and no login, can ask what XTRA4U sells, what the official catalogue price of a product is, whether a service is open, and common "how do I…" questions from the published FAQ. The answer links to the normal service page, where the customer buys through the normal checkout.

**What it is not:** it cannot place, track, verify, pay, refund or deliver anything. It does not know who the visitor is. It never computes a price. It stores no conversations. It does not hand the visitor to checkout on its own: the only links are server-built `/services/*` pages the visitor chooses to click.

---

## 1. Purpose and scope

| In scope | Out of scope (deferred, see §14) |
|---|---|
| Which services exist and whether each is open (data bundles, shop, result checkers, AFA registration) | Order tracking, order status, receipts |
| Search and read the **official** public catalogue: name, network/service, size, validity, **catalogue price** | Payment verification, the D4 verification workflow |
| Published FAQ answers (existing `cms_faqs` rows) | Checkout handoff (D5) that pre-fills or starts a purchase |
| Fail-closed enable/disable, rate limits, budgets, kill switch (all M5.2) | Any live AI provider / SDK; conversation history or retention |
| Server-built links to the public service pages | Utility Bills availability or prices, the legacy `/checkout` marketplace |

## 2. Access model: a guest assistant

* **No identity is accepted or implied.** The visitor is anonymous. `ToolContext::forGuest()` is built from a **hash of the server-side session id**, only ever to give the rate limiter and the budgets a per-visitor key. It is **not** proof of identity and **not** proof of ownership of any order, number or payment. No tool uses it to look anything up.
* The request body may contain exactly one field, `message`. A `customer_id`, `vendor_id`, `phone`, `order_ref`, tool name, context, history or anything else is **refused (422)**, not ignored.
* Tool arguments that look like identity or ownership are rejected by the existing M5.3 prohibited-argument list, and every customer tool schema is strict (unknown keys fail).
* The feature flag (`AI_ENABLED=true` and `AI_SURFACE_CUSTOMER=true`) is enforced by `EnsureCustomerAssistantEnabled`: while off, **both routes answer 404** and the page never renders.
* **Launch requirement, not met by this milestone:** legal and privacy sign-off. The assistant accepts free text from the public, and the page tells visitors not to type payment references, PINs or passwords, but nothing in this repository is a legal approval. Before enabling in production, the privacy notice and terms (`/privacy`, `/terms`, CMS-managed) must be reviewed and updated by whoever owns that decision, and that decision must be recorded outside this repository. This document does not claim it has happened.

## 3. Routes and HTTP contract

| Route | Name | Middleware | Purpose |
|---|---|---|---|
| `GET /assistant` | `customer.assistant.show` | web, `EnsureCustomerAssistantEnabled`, `throttle:60,1` | The page |
| `POST /assistant/messages` | `customer.assistant.message` | web (session, CSRF), `EnsureCustomerAssistantEnabled`, `throttle:customer-assistant` | One question, one answer |

`customer-assistant` limiter: **10 per minute and 60 per hour per IP**. On top of that the engine's own limits apply per guest session (`ai.rate_limits.customer`, 6/minute), the shared concurrency cap, the budgets and the circuit breaker. The IP limiter depends on the deployment's trusted-proxy configuration being correct; behind a proxy that is not trusted every visitor shares one IP.

`POST` rules, in this order: JSON only (415) → body ≤ 4000 bytes (413) → exactly one string field `message` (422) → a server session must exist (503) → the assistant. The message is limited to 500 characters. A success returns:

```json
{ "ok": true, "answer": "plain text", "links": [{"label": "...", "path": "/services/..."}], "tools_used": ["customer_search_products"], "request_id": "..." }
```

Failures use `{ "ok": false, "error": { "code", "message" } }` with 429 (+ `Retry-After`), 422, 503 or a generic 500. Every response carries `Cache-Control: no-store`. None of these routes is reachable through the public API, and no `/api/*` route exposes the assistant.

**Existing routes that the assistant must not expose, call or reuse, and does not:** `/order-status/check`, `/order-status/poll`, `/checkout/success/{order}`, `/checkout/receipt/{order}`. Architecture tests assert that none of those strings appears in any customer assistant file or view.

## 4. Architecture

```
Browser (Alpine) --{message}--> CustomerAssistantController
   -> ToolContext::forGuest(hash(session id))
   -> CustomerAssistant::ask()
        round 1: AiOrchestrator::run(system prompt + scrubbed question, 4 customer tools)
        run <= 4 requested tool calls through ToolExecutor (customer allowlist only)
        round 2: AiOrchestrator::run(... + tool results, NO tools offered; further calls ignored)
   -> cleaned answer + server-built links
```

Exactly the M5.4/M5.5 pattern: at most two orchestrator calls, no loop, no direct provider access (only `assertConfigured`). The provider is `mock`; no SDK or live adapter exists. The question is scrubbed by `PromptScrubber` (phone numbers, e-mails, long digit runs and keys are masked) before reaching the model, the answer is cleaned and bounded (2500 characters), and links come **only** from successful tool results, built by the server via `PublicCatalogueReadService::linkTargets()`. The model cannot add a link.

Files: `app/Services/Ai/CustomerAssistant/` (assistant, logger, reply, four tools), `app/Services/Catalogue/PublicCatalogueReadService.php`, `app/Http/Controllers/CustomerAssistantController.php`, `app/Http/Middleware/EnsureCustomerAssistantEnabled.php`, `resources/views/customer/assistant.blade.php`, plus registrations in `AiServiceProvider` (tools, limiter), `config/ai.php` (allowlist) and `routes/web.php`.

## 5. The official public catalogue

`PublicCatalogueReadService` mirrors what the public platform pages already show, and nothing more:

* A category (`data`, `shop`, `results`, `afa`) is served by the **admin-assigned platform vendor** (`PlatformServiceVendor`), who must be an **approved** vendor. An unassigned category, an unapproved vendor or a closed service (`ServiceAvailability`) yields nothing (`closed` / `unavailable` in the service list).
* Owned products need `is_active`; reseller listings additionally need the source product to be active **and** resellable. Result checkers come from `PlatformServiceCatalog`. These checks are in SQL (`whereIn` on the assigned vendors) wherever the data model allows.
* **Category lives inside the product `description` JSON**, which also holds private provider mappings. Since M5.7 the category and the search words are filtered **in the database** (SQLite and MariaDB JSON functions, with the storefront's exact default-category rules; PHP re-checks every candidate), and the bounded scan (`SCAN_LIMIT` = 400 rows per source) applies only to rows that already match. If more than 400 rows match, the result carries `results_may_be_incomplete` (see `06-production-hardening-and-launch-readiness.md` section 12). Only a whitelist of public keys (network, size, validity, notes…) is ever read from that JSON; provider, mapping and cost keys are never selected.
* A product is addressed by an opaque ref (`product_12`, `reseller_7`, `result_checker_3`), matched by a strict pattern. Anything invalid, hidden, inactive, closed or unknown returns the same `{found:false, "That product is not available."}`, so refs cannot be probed.
* The **price is the catalogue price** (`products.price`, or `selling_price` for a reseller listing, ordered through `Money::minor`). The assistant never receives a vendor cost, a commission, a tier price, a wallet or a provider mapping. Every product result carries a note that the amount payable is confirmed at checkout.

### Settings are read uncached (database cache store)

The catalogue decides "is this service open?" and "which vendor serves this category?" from two admin settings. The public pages read them through `Setting::get()`, a read-through cache (`Cache::remember`). On the **database cache store** (production) that read **writes** to the `cache` table on a miss, and for a setting that has **no row** (the normal state of `service_open.*`, since "open" is the default) it writes on **every** call, because a cached `null` cannot be told apart from a miss. The tools run inside the `ReadOnlyGuard`, which refuses every non-SELECT statement, so before the fix `customer_list_services`, `customer_search_products` and `customer_get_product` failed with `read_only_violation` on a cold cache, and permanently for unset settings. (`customer_search_help` reads only `cms_faqs` and was unaffected.)

The fix is a small **uncached read path** used only by `PublicCatalogueReadService`: `Setting::getFresh()`, `ServiceAvailability::isOpenFresh()/isClosedFresh()` and `PlatformServiceVendor::vendorIdForFresh()/resolveFresh()`. Each is the existing method minus the cache, with the same meaning (no row = open, a vendor only while approved) - `ColdDatabaseCacheTest` asserts the parity over every state. The existing cached methods are untouched, so the public pages behave exactly as before.

Why this and not the alternatives:

| Alternative | Why it was not chosen |
|---|---|
| Prime/warm the settings before the guarded window | Does not work: a setting with no row re-writes the cache on every read, so it still fails inside the guard even when "warm". It would also depend on the cache state and on when other pages ran. |
| Allow writes to `cache` / `cache_locks` inside the guard | Weakens the one control that makes "tools are read-only" a runtime fact, for every tool on every surface, and a cache table is a place a tool could store state. The guard is deliberately unchanged. |
| Catch the error and return an empty catalogue | Would present a closed or empty shop to customers when the real cause is an infrastructure detail - wrong answers, not safe ones. |
| Switch production to the array/file cache | Changes platform behaviour for everything to fix one reader. |
| Read the cache without writing (`Cache::get`) | Not write-free: the database store also issues a `DELETE` for an expired entry it finds. |

Behavioural difference to be aware of: because it reads the table, the assistant is never stale. The public pages may answer from the cache for up to an hour after a **direct** edit of the `settings` table (an admin save through `Setting::set()` always invalidates it), whereas the assistant reflects the table immediately. That is the more conservative direction: it never offers something an administrator has already closed.

## 6. Tools

All four implement `AiTool`, are registered before the registry is sealed, are `Customer`-surface only, allow-listed in `ai.tools.allowlist.customer`, use strict schemas, are read-only, bounded, and mark their output `untrusted: true`. None accepts an identity or ownership argument.

| Tool | Parameters | Returns |
|---|---|---|
| `customer_list_services` | none | Each service: label, availability (`available` / `closed` / `unavailable` / `see_page`), page path. Utility Bills is always `see_page` (availability is not known to this code). |
| `customer_search_products` | `query` (2–60), `category` (`data`/`shop`/`results`), `sort` (`price_asc`/`price_desc`/`name`), `page` (1–20), `limit` (1–10, default 6) | `ref`, `name`, `category`, `service`, `size`, `validity`, `price`, `currency` per item, plus `has_more` and `incomplete`. Search terms have `%`, `_`, `\` and control characters removed. |
| `customer_get_product` | `product_ref` (required, strict pattern) | The same fields plus public notes, or the uniform "not available". |
| `customer_search_help` | `query` (2–100), `limit` (1–5, default 3) | Active `cms_faqs` rows only: topic, question, answer (≤ 500 characters, Markdown and links stripped). Ranked in PHP after a bounded `LIKE` scan. |

The device-level safeguards from M5.3 apply: results are sanitized by `ResultSanitizer`, so phone numbers and e-mail addresses in a FAQ or product note are redacted in the model's view; tool failures return a generic `tool_error` with no SQL, table or class names.

## 7. Visibility and isolation

* **Surface isolation, exact:** developer 4 tools, vendor 6, customer 4. The registry offers each surface only its own definitions, the allowlists do not overlap, and a customer turn that asks for a vendor or developer tool receives an `unknown tool` rejection (tested). The other assistants contain no reference to the customer code.
* **No business code reachable.** The customer code imports only four models (`Product`, `ResellerProduct`, `Vendor`, `CmsFaq`) and no payment, wallet, commission, fulfilment, PIN/inventory, webhook, SMS/e-mail, vendor-dashboard, developer-API or order/transaction namespace. Token-level architecture tests forbid writes, raw SQL, `DB`, `Cache`, `Session`, `Storage`, `Http`, `Mail`, `Queue`, `Event` and filesystem/process functions in these files.
* A full tool-using turn is observed with `DB::listen`: it reads only from an allow-list of public-catalogue tables (`products`, `reseller_products`, `vendors`, `settings`, `network_services`, `vendor_result_checker_settings`, `cms_faqs`, plus framework plumbing such as sessions/cache; the test fails on any other table), every statement is read-only, and no table's contents change.

## 8. Price accuracy

The model never computes prices. It repeats the `price` string a tool returned; the system prompt forbids estimating, converting, discounting, adding up or "working out" prices, and says the amount payable is confirmed on checkout. Prices come from the same columns the public pages use, formatted from integer minor units (`Money::minor`) so ordering is exact. Totals, discounts, fees (mobile-money or gateway), wallet pricing and tier pricing are **not** in this assistant. **The AFA fee is deliberately not exposed** (the AFA service shows availability and a link only).

## 9. Privacy

* No identity, phone number, e-mail, order, payment or wallet data is read, stored or logged.
* The question is scrubbed before it reaches the model; the answer is cleaned after.
* The page warns visitors not to type payment references, PINs or passwords; the system prompt tells the model not to repeat them and not to ask for them.
* No cookies or storage are added by the assistant. The ordinary Laravel session cookie already exists on every page; the assistant stores **nothing** in it.
* Legal/privacy approval is a launch gate (§2).

## 10. Stateless behaviour

* No table, migration, model, session value, cache entry, file, `localStorage`/`sessionStorage`/IndexedDB or cookie holds a question or an answer; no history, conversation or transcript route exists; nothing is flashed to the session (JSON-only, so a failed validation cannot redirect back with old input).
* Every request is standalone: the provider receives the system prompt and **this** question only. The browser keeps the visible exchange in page memory and sends only `{message}`; "Start over" clears it; reload or close discards it.
* Tests run a complete tool-using turn with the `database` cache and session drivers and scan every table for canaries.

## 11. UI

`/assistant`, in the existing storefront layout, mobile-first. A labelled input with a character counter, example questions, a loading state, a disabled send while waiting, `aria-live` / `role="status"` regions, a clear message when rate-limited, and a "Start over" button. Rendering uses `x-text` only (no `x-html`), and links are re-validated client-side to start with `/services/`. When the feature is off the route is 404; when it is on but the engine is not configured, the page shows an "unavailable" state. **No navigation link was added** (that is a product decision for launch).

## 12. Logging

Through the existing M5.2 channel and `Redactor`: one `ai.customer_assistant.turn` event with counts, timings, a hashed principal (12 characters, never the session id) and the outcome, plus the engine's own request and tool events. **Never** the question, the answer, tool arguments or results, an IP address or a session id. Unexpected failures log the exception class only. Canary tests cover all of these.

## 13. Configuration and enabling

Ships off. To try it locally (mock provider only):

```
AI_ENABLED=true
AI_SURFACE_CUSTOMER=true
# AI_PROVIDER stays "mock"
```

* `config/ai.php` → `tools.allowlist.customer` lists the four tools. Kill switch: `php artisan ai:kill-switch engage|release`.
* **Customer budgets are not configured** (`AI_CUSTOMER_DAILY_REQUESTS` / `AI_CUSTOMER_DAILY_TOKENS` are unset), so the per-surface daily budget does not bind. The engine already refuses a live network provider without token limits, but set them deliberately before any real provider is connected; with the mock provider there is no cost.
* The per-IP limiter (`customer-assistant`) lives in `AiServiceProvider`.
* No migration, no new dependency, no environment variable beyond the existing surface flag.

## 14. Deferred work (explicitly not built)

| Item | Why it is deferred |
|---|---|
| **Order tracking / order status** | The existing lookups (`/order-status/check`, `/order-status/poll`) and receipt pages identify an order by guessable or phone-based input. A guest session hash is not proof of ownership, so an assistant cannot safely authenticate "this is your order". It needs a signed, customer-held proof first. |
| **Payment verification, D4 verification workflow** | Touches gateways, webhooks and wallet/vendor credit. Out of scope by brief and unsafe for an anonymous read-only assistant. |
| **D5 checkout handoff** | `CheckoutController::process` takes many client-supplied fields (recipient, phone, payer, method, intent), so a "buy this" button would either need to widen checkout or pre-fill unvalidated data. Only server-built links to `/services/*` pages are provided; the customer starts checkout there. |
| Utility Bills in the catalogue | Prices and availability come from the external provider with admin-owned commission; not part of the public catalogue read model. |
| The legacy `/checkout` marketplace | Per-vendor storefronts are not part of the official platform catalogue. |
| A live provider, answer-quality evaluation, spend controls against real pricing | The OpenAI adapter, hard budgets and the preflight exist since M5.7 (`06-production-hardening-and-launch-readiness.md`) but nothing is enabled and no real call has been made; answer-quality evaluation remains a separate milestone. |
| A navigation link, analytics, translations | Product decisions at launch. |

## 15. Known limitations

* No real model: answers come from the scripted mock; quality is untested against a real model.
* Phone numbers and e-mail addresses inside FAQ answers and product notes are redacted by the sanitizers, so a FAQ that quotes a support number will not show it through the assistant. Link people to the Contact page instead.
* `results_may_be_incomplete` appears only if a catalogue category has more than 400 rows that actually match, per source; there is no pagination beyond that scan.
* Customer budgets are unconfigured (§13). The per-IP limiter depends on trusted-proxy settings (§3).
* Legal and privacy approval has not been obtained (§2).

## 16. Testing

`tests/Feature/Ai/CustomerAssistant/` (109 tests): `CatalogueToolsTest` (visibility, pricing, search, sanitising), `HelpToolAndFailureTest` (FAQ, generic tool errors), `AccessAndRoutesTest` (flag, validation, limits, session, rate limiting, headers), `OrchestrationTest` (two-round flow, links, scrubbing, injection, UI source), `StatelessAndPrivacyTest` (no persistence, no table change, read-only SQL, logging canaries), `ArchitectureAndIsolationTest` (surface isolation, forbidden capabilities, blocked routes, deferred items absent). Http is faked and asserted empty. Existing tests that asserted an empty customer allowlist were narrowed to the new exact lists; their isolation checks are unchanged.

Run: `php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Ai/CustomerAssistant`. Also verified on MariaDB 11.4.13 (disposable local server, `DB_CONNECTION=mysql`). The failure-path tests make queries throw through `DB::beforeExecuting` instead of dropping tables, because DDL commits implicitly on MySQL/MariaDB.
