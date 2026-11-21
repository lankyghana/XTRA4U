# M5.5 — Vendor Assistant

Status: implemented, **uncommitted, disabled by default, mock provider only**. Builds on `docs/ai/00-audit-and-architecture-plan.md` (unchanged), `docs/ai/01-foundation.md` (M5.2), `docs/ai/02-tool-system.md` (M5.3) and `docs/ai/03-developer-assistant.md` (M5.4, unchanged — its "out of scope: vendor assistant" row described M5.4 and is still true of M5.4).

**What this is:** a read-only, informational, stateless assistant inside the existing, protected **vendor dashboard**. A signed-in, approved vendor can ask about their *own* sales, orders, earnings, commission, products and store information. It answers from six read-only tools that read the same figures the dashboard shows.

**What it is not:** it cannot create, change, cancel, refund, pay, withdraw, fulfil, price, activate or deactivate anything. It stores no conversations. It never sees customer phone numbers or emails, payment references, PINs, wallet balances, withdrawals, API keys or provider data. **No real model is connected**: the only provider is the offline scripted mock, so today the feature demonstrates the whole secured pipeline but cannot give a genuine AI answer.

---

## 1. Purpose and scope

| In scope | Out of scope (not built here) |
|---|---|
| Today / yesterday / this week / this month / all-time dashboard figures | Customer assistant |
| Find and read the vendor's own orders (bounded, safe fields) | Any live AI provider / OpenAI adapter / SDK |
| The vendor's own products and reseller listings, and whether they are on sale | Conversation persistence, history, retention |
| Sales vs commission vs earnings, per source | Any write: orders, payments, refunds, prices, products, inventory, PINs, fulfilment, wallets, withdrawals, payouts, commissions, store settings, API keys, webhooks, SMS/email, admin operations |
| Public store name/URL/status | Customer contact details, payment references, wallet balance, withdrawals |
| Fail-closed enable/disable, rate limits, budgets, kill switch (all M5.2) | Admin AI settings UI, changing any dashboard calculation |

## 2. Authentication and approval

Nothing about authentication was added or loosened.

* Both routes sit inside the existing `vendor.approved` group: the `vendor` guard plus the live approval check. Guests go to the existing vendor login; unapproved or suspended vendors are denied and signed out exactly as on every other vendor page.
* Approval is read **live** on every request (a session that outlives approval is refused) and **again on every tool call** by M5.3's `ToolContextVerifier`.
* The controller builds `ToolContext::forVendor()` from `Auth::guard('vendor')->user()`. The request body is never read for identity. No new guard, login, token, table or identity concept exists; tests assert it.
* A web (customer/admin user) session, a Developer API bearer key and a developer-surface context cannot use the assistant or its tools.

## 3. Routes and HTTP contract

| Method | Path | Name | Middleware |
|---|---|---|---|
| GET | `/vendor/assistant` | `vendor.assistant.show` | `web`, `vendor.approved` |
| POST | `/vendor/assistant/messages` | `vendor.assistant.message` | `web` (CSRF), `vendor.approved`, `throttle:vendor-assistant` |

These are the only two routes. There is no history, conversation, transcript or delete endpoint (a `GET` on the message URL is 405).

Request: JSON only, exactly `{"message": "<string>"}`.

| Check | Result |
|---|---|
| Not JSON | `415` (so a failed form post can never flash typed text into the session) |
| Body > 6000 bytes | `413` |
| Not exactly one string field `message` (`vendor_id`, `tool`, `tools`, `history`, `surface`, `user_id`… are refused, not ignored) | `422` |
| Empty/whitespace or > 1000 characters (checked on what was sent, and again after masking) | `422` |
| HTTP throttle 10/min per vendor (IP fallback) | `429` |
| Engine off / surface off / kill switch / tools missing / provider misconfigured / budget / breaker / provider failure | `503`, fixed safe message |
| M5.2 per-principal limit | `429` + `Retry-After` |
| Anything unexpected | `500`, generic; only the exception **class** is logged |

Response: `{"ok":true,"answer":"…","tools_used":["…"],"request_id":"…"}` with `Cache-Control: no-store`. The answer is plain text, bounded to 4000 characters, with control characters removed and key-shaped strings masked.

## 4. Architecture

```
vendor browser ── POST {message} ──► Vendor\AssistantController      (vendor.approved, CSRF, throttle)
                                       │  ToolContext::forVendor(authenticated vendor)
                                       ▼
                                  VendorAssistant::ask()             (one bounded turn)
        round 1: system + message + 6 vendor tool definitions ──► AiOrchestrator ──► mock provider
        ≤ 4 tool calls ──► ToolExecutor ──► vendor tool ──► VendorDashboardReadService / scoped queries
        round 2: system + message + tool results (untrusted), NO tools ──► AiOrchestrator ──► mock provider
                                       ▼
                         plain-text answer (scrubbed, bounded)
```

* At most **two provider requests** and **one batch of at most four tool calls** per question. There is no agent loop; tool calls proposed in round 2 are discarded unexecuted. A test queues extra scripted tool-call replies and proves they are never consumed.
* Every provider request goes through `AiOrchestrator`, so all M5.2 gates apply (kill switch, flags, rate limit, budget, breaker, concurrency, size limits, logging). The assistant never talks to a provider directly (an architecture test checks this).
* The system prompt and the vendor's message are identical in both rounds. Tool results are `tool` messages inside an `untrusted: true` envelope. A tool result that would not fit the request-size ceiling is replaced whole by an "omitted" notice, never cut in half.
* Prompts are masked with `PromptScrubber` before they reach the provider (phone numbers, emails, long digit runs, key-shaped strings), and so are answers.

## 5. The dashboard read service (`app/Services/Vendor/VendorDashboardReadService`)

The dashboard's read logic was extracted from `VendorDashboardController` into this service **without changing any business definition**. The controller now consumes the service, and so do the tools — there is exactly one implementation of every figure.

Method | Purpose
---|---
`salesSummary($vendorId, $filter)` | sales / earnings / commissions for a period, plus per-source figures (product orders, AFA, result checkers)
`dateRange`, `PERIODS` | the period → date-range rule
`afaStats`, `resultCheckerStats` | the AFA and result-checker figures (moved verbatim)
`visibleOrdersQuery($vendorId)` | the Orders page ownership/visibility query
`ownProductsQuery`, `resellerListingsQuery`, `publiclyAvailableListingsQuery`, `catalogueCounts`, `listingIsPubliclyAvailable` | catalogue and storefront-availability rules
`orderEarningsRecord($vendorId, $orderId)` | the vendor's own recorded transaction for one order
`analytics($vendorId)` | the exact variables the Analytics page renders

**Method:** characterization tests (`DashboardCharacterizationTest`, 19 tests) were written and run against the *unmodified* controller first, recording its real behaviour; the refactor was then made; the same tests plus the existing dashboard tests passed unchanged afterwards. `DashboardReadServiceParityTest` additionally proves the dashboard cards, the filtered-stats endpoint, the Analytics page, the service and both money tools agree for all five periods, and that the tool availability rule matches the real public storefront page.

### Dashboard quirks that were deliberately NOT changed

These are existing behaviours. The assistant reproduces them (it must agree with the dashboard); they are documented here, not "fixed":

1. **Two definitions of "completed" transactions.** The dashboard cards count transactions whose `payment_status` is `completed` **or** `successful`; the Analytics page counts only `completed`.
2. **An unknown `?filter=` means "today".** The assistant's tools accept only the five valid periods and reject anything else.
3. **Commission covers product-order transactions only.** AFA registrations and result-checker orders record no commission, so the assistant reports their commission as *unavailable* (`null`), never `0` and never inferred.
4. **Analytics status breakdown has no payment filter**, so its "pending" bucket includes unpaid checkouts.
5. **The `index()` recent-orders ownership rule is broader than the Orders page rule** (any `owner_vendor_id`, no `is_reseller_order` requirement). The assistant deliberately uses the narrower **Orders page** rule (`visibleOrdersQuery`), which is the one that is paired with the order-detail page, and does *not* reuse the recent-orders rule.
6. **"This week" differs by page:** Analytics runs to end of week, the dashboard to end of today.
7. **Dead fallback:** `result_checker_orders.vendor_profit` is `NOT NULL DEFAULT 0`, so the `COALESCE/CASE` fallback in the result-checker earnings query can never fire. Kept as is.
8. **No "yesterday's order count" exists** anywhere on the dashboard, so the assistant reports it as unavailable rather than computing a new one.
9. **Sales is not earnings.** Earnings is a *recorded* figure (`vendor_earning`, `owner_earning`, `reseller_earning`), not sales minus commission, because resellers and providers are credited differently. The tool says so in its definitions, and a test pins a case where sales − commission ≠ earnings.

## 6. Tools

All six are vendor-surface-only, take **no identity argument of any kind** (`vendor_id`, `owner_id`, `user_id`, `customer_id`, `actor_id`, `account_id`, `reseller_id`, `vendor`, `role`, `is_admin`, `permissions`, `surface`, `context`, `scope` are all rejected as `prohibited_argument` before the tool runs — 16 variants × 6 tools are tested), and are allow-listed in `config('ai.tools.allowlist.vendor')`. They are registered in `AiServiceProvider` and the registry is sealed afterwards. Tool names use underscores like the M5.3/M5.4 tools.

| Tool | Arguments | Returns |
|---|---|---|
| `vendor_get_dashboard_summary` | `period` (today, yesterday, this_week, this_month, all_time; default today) | sales / earnings / commissions for the period, per-source figures, the Analytics order count for the period (*unavailable* for yesterday), all-time status counts, top products, a 7-day earnings trend |
| `vendor_search_my_orders` | `status`, `from`, `to` (YYYY-MM-DD, ≤ 366 days), `product` (2–60 chars, LIKE-escaped), `order_ref`, `limit` (1–10) | order summaries only (below), `has_more` |
| `vendor_get_my_order` | `order_ref` (required) | one order summary plus the vendor's own recorded earnings for it, or the generic not-found answer |
| `vendor_list_my_products` | `status` (all/active/inactive), `name`, `limit` (1–20, default 10) | the vendor's own products and reseller listings, with `on_sale` and a plain-language `reason_not_on_sale`, and catalogue counts |
| `vendor_get_earnings_summary` | `period` | gross sales, commission on product orders, recorded net earnings, per-source breakdown, definitions, "unavailable" notes |
| `vendor_get_my_store_info` | none | store name, public URL, code, account status, on-sale counts, whether AFA registration is offered |

Order summary fields: `ref`, `product`, `amount_paid_by_customer`, `status`, `your_role` (`seller` or `product_owner`), `is_affiliate_order`, `created_at`, `paid_at`, `delivered_at`. Money is a two-decimal string in GHS.

M5.3 limits still apply to every result (8192 bytes, 50 items, depth 5, strings 1000); results are never silently cut.

## 7. Multi-vendor isolation

* **Ownership is in the SQL.** The trusted vendor id from `ToolContext` is a condition of the same query as the record id (`WHERE id = ? AND (vendor_id = ? OR …)`); there is no fetch-then-check. A test reads the query log and asserts exactly one orders query with both bindings.
* **An inaccessible order looks identical to a nonexistent one**: the same `{found:false}` data, byte for byte, for another vendor's order and for an unused number. Search never returns another vendor's order either.
* **Order ownership** is the Orders page rule: the seller (`vendor_id`), or the product owner of an affiliate/reseller order (`owner_vendor_id` with `is_reseller_order = true`, or the legacy `resellerProduct.owner_vendor_id` link), restricted to paid/completed payment and a non-Pending status. A third vendor sees nothing.
* **Products:** only products the vendor created and listings the vendor created. A reseller never sees the owner's other products; an owner never sees resellers' listings.
* **Mutation check:** weakening the seller condition in `visibleOrdersQuery` made 7 tests fail across characterization, isolation, order and orchestration suites; the file was then restored byte-for-byte.

## 8. Financial accuracy

* Financial figures come **only** from `VendorDashboardReadService`. The assistant does no arithmetic of its own, and the system prompt forbids the model from "working out" figures.
* Always stated with period and currency (GHS). Sales, commission and earnings are separate fields with definitions.
* **Wallet balance is never used as earnings** and is not returned at all; withdrawals/payouts are not part of any tool (the earnings tool says so under `unavailable`).
* Missing figures are `null` with an explanation (AFA/result-checker commission, yesterday's order count). Nothing is invented; an empty vendor gets zeros.
* Provider (platform) commission fields and platform-level figures are never exposed.

## 9. Privacy

Never selected, returned, logged or sent to the provider: customer/recipient phone numbers, mobile-money numbers, emails, payment references, gateway or provider data, delivered PINs, external mappings or supplier ids, vendor email/password/balances/wallet, API keys. `OrderFields::LIST_COLUMNS` is an explicit allow-list of columns, so what is never selected can never leak through a later edit; architecture and canary tests check this.

**One thing the store tool states honestly:** the public storefront *does* display the vendor's account phone number to customers. The assistant still does not return phone numbers; the store tool says that a number is displayed and that it can be changed in account settings (an earlier draft wrongly claimed the page had no contact details, and a test against the real storefront caught it).

## 10. Stateless behaviour

No tables, migrations, models, session data, cache entries, files or browser storage hold conversations. The page keeps messages in a JavaScript array in memory; refreshing clears them and the UI says so. Each request carries only the current message — earlier turns are never re-sent (tested). A DB-backed cache/session canary scan after a full tool-using turn finds nothing. A turn changes no business table (tested with row hashes).

## 11. UI

`/vendor/assistant`, in the vendor layout, Blade + Tailwind + Alpine. Example question chips; the textarea and button are disabled while a request is in flight, with a loading state; errors show as a "Problem" message. All assistant text is rendered with `x-text` (escaped) — no `x-html`, `innerHTML` or markdown renderer. No file upload, no storage APIs. When the engine/surface is off or tools/provider are not ready the page shows an "unavailable" state and the nav link ("Assistant") is hidden.

## 12. Logging

Only through the existing M5.2 channel and `Redactor`: one `ai.vendor_assistant.turn` event with counts and timings (rounds, tool calls requested/run/ok, follow-up calls ignored, answer length, duration, a hashed principal) and the engine's own request/tool events (tool name, status, argument *count*, result size). Never the prompt, the answer, tool arguments, tool results or any order/customer/financial value. Unexpected failures log the exception class only. Canary tests cover prompts, answers, order service names, customer phones, payment references and amounts.

## 13. Configuration and enabling

Ships off. To try it locally (mock provider only):

```
AI_ENABLED=true
AI_SURFACE_VENDOR=true
# AI_PROVIDER stays "mock"
```

The vendor allowlist in `config/ai.php` lists the six tools; the customer allowlist is still empty. Kill switch: `php artisan ai:kill-switch engage|release`.

## 14. Testing

`tests/Feature/Ai/VendorAssistant/` — characterization, read-service parity, access control, surface/feature control, multi-vendor isolation, order tools, product/earnings/store tools, orchestration, stateless + logging, regression/architecture. Http is faked and asserted empty in every test. Time is frozen at Wednesday 2026-11-18 15:00 so every expected figure is a literal that can be checked by hand. Run: `php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Ai/VendorAssistant`. Existing M5.3/M5.4 tests that asserted an empty vendor allowlist, exactly four registered tools or exactly one assistant route were narrowed to the new reality; no assertion was removed.

## 15. Known limitations and deferred work

* No real model: answers come from the scripted mock. A live adapter, spending limits against real pricing, and prompt/answer quality evaluation are separate milestones.
* Order summaries have no line-item detail and no delivery/failure reasons (provider data is excluded on purpose).
* Dashboard inconsistencies listed in §5 are documented, not resolved; fixing them is a product decision.
* Single-turn only: there is no follow-up context by design.
* Not run against MySQL/MariaDB for this milestone unless stated in the final report.
