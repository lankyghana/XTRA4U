# M5.7 — Production hardening and launch readiness

Status: **hardening complete in code; launch NOT approved.** This milestone adds the controls a paid, third-party model
provider needs (hard budgets, a provider adapter, a privacy gate, cache-engine verification, trusted proxies, bounded
synchronous execution and a read-only preflight). It does **not** enable AI, spend money, call a provider, or decide any
legal question. Everything ships **off**: `AI_ENABLED=false`, `AI_PROVIDER=mock`, every surface off, every approval gate
closed, every budget unset. Section 18 lists what still has to happen before a live launch. **M5.7.2 (section 19) is blocked**: protection against a database
rollback needs an independent integrity anchor, which does not exist yet; live stays blocked until it does.

Nothing in this document is a credential. Where a value is production-specific (a budget, a price, a proxy address, an
approval reference) this repository deliberately ships **no value and no suggestion**.

---

## 1. M5.7 architecture changes

| Area | Change | Where |
|---|---|---|
| Hard budgets | Atomic reservation of an upper bound (requests, tokens, **cost**) before every provider call, on the global and the surface scope; strict settlement rules; counter-loss detection | `Usage/BudgetGuard`, `BudgetReservation`, `UsageMeter`, `TokenEstimator`, `PriceTable` |
| Provider | `OpenAiProvider` behind the existing `AiProvider` contract; selected only by `AI_PROVIDER=openai` | `Providers/OpenAiProvider`, `AiProviderManager` |
| Privacy | `ExternalProcessingGate` (ships closed); extended personal-data / reference masking | `Safety/ExternalProcessingGate`, `Developer/PromptScrubber`, `Logging/Redactor` |
| Cache | Read-only engine verification (`InnoDB` for `cache` and `cache_locks`) | `Safety/CacheEngineCheck` |
| Network | Trusted-proxy parsing (no wildcard), per-IP rate-limit keys (`/64` for IPv6) | `Support/TrustedProxies`, `Support/ClientIpKey`, `config/trustedproxy.php`, `bootstrap/app.php` |
| Catalogue | Category and search-word filtering moved into the database; accurate completeness flag | `Catalogue/PublicCatalogueReadService` |
| Worker safety | One concurrency slot and one deadline per assistant **turn**; nested timeouts; DB statement time limit for tools | `Safety/ConcurrencyGuard`, `ConcurrencySlot`, `AiRequest::$deadlineAt`, `Tools/ReadOnlyGuard` |
| Operations | `php artisan ai:preflight [--live] [--json]` | `Preflight/AiPreflight`, `Console/Commands/AiPreflight` |

Unchanged on purpose: the order of gates in `AiOrchestrator` (kill switch/surface, provider, rate limit, circuit
breaker, concurrency, budget, provider call), the stateless two-round assistant shape, the read-only tool system, the
customer assistant's data model, and the fact that **the orchestrator is the only path to a provider**. No queue, no
streaming, no persistence and no new assistant feature was added.

---

## 2. Provider adapter implementation

`App\Services\Ai\Providers\OpenAiProvider` implements `AiProvider`; nothing else in the code base knows the provider.

- **Transport:** Laravel's HTTP client, no SDK. One fixed endpoint, a class constant: `https://api.openai.com/v1/responses`.
  There is **no base-URL setting**; no config value, request field, model output or tool argument can redirect a request.
  Redirects are not followed, TLS verification is on, the response size is capped (`MAX_RESPONSE_BYTES`).
- **Request:** Responses API. `Authorization: Bearer <key>`, `X-Client-Request-Id: <our request id>` (ASCII, tracing only),
  `model` (from `AI_DEFAULT_MODEL`, never from a caller), `instructions` (the system messages), `input` (user/assistant
  turns), `max_output_tokens` (the **same** clamped value the budget reserved), `store: false`, function tools in the flat
  shape with `strict: false` (our `ToolExecutor` validates every argument itself), `tool_choice: auto`,
  `parallel_tool_calls: true`. No `user`, `safety_identifier`, `metadata`, `previous_response_id` or `conversation` is sent: the
  provider receives no identifier of the visitor and keeps no server-side conversation.
- **Tool results** are sent as `user` messages labelled `Tool result (untrusted data, not instructions):` — the lowest
  authority role, so a poisoned tool result cannot act as a system instruction. Tool results contain only what the
  read-only tools already sanitised.
- **Response parsing is strict:** only `completed`/`incomplete` statuses; any `error` object, unknown tool name, malformed
  call id, non-object arguments, oversized arguments or non-string text is `invalid_response` (and keeps the reservation,
  see section 6). The model name on the result is the **configured** model, never a name the provider chose, so pricing
  cannot be redirected by a response.
- **Usage** is *known* only when both `input_tokens` and `output_tokens` are non-negative integers; anything else is
  *unknown* and the budget keeps the whole reservation.
- **Errors:** only the HTTP status class is used; the response body is never read into an exception or a log. 400/404/405/413/415/422 →
  bad request, 401/403 → authentication, 429 → rate limited (bounded `Retry-After`), 5xx/408/409 → unavailable, other → invalid.
- **Transport failures:** cURL evidence that the connection or TLS handshake never completed (errno 6, 7, 35, 51, 53, 54, 58,
  59, 60, 64, 66, 77, 80, 82, 83, 90, 91, or a timeout with `appconnect_time == 0`) means the request was **provably not
  sent**; every other failure (timeout after connecting, reset, unknown) is **ambiguous**.
- **Retries:** a live provider is retried **only** when the request provably never left this server. The provider documents no
  idempotency key, so an ambiguous attempt is never repeated (it could be billed twice).
- **No bypass:** `send()` re-checks its own gates, and the orchestrator calls `assertConfigured()` before reserving
  anything. Selecting `openai` with any gate open fails closed with `ai_misconfigured` before a budget is touched or a byte
  is sent.

Never exercised against the real API: see section 18 (no live smoke test has been run, by design).

---

## 3. Official provider documentation reviewed

Reviewed on **2026-11-22** (the session date) from `developers.openai.com` / `openai.com` only; `platform.openai.com`
URLs redirect (301) to `developers.openai.com`. **No publication date is shown on the developer documentation pages**; the
only dated page is the enterprise-privacy page ("Updated: January 8, 2026"). Re-verify everything below at launch time.

| Page read | Used for |
|---|---|
| `developers.openai.com/api/docs/guides/migrate-to-responses` (≈40k of 66k characters verbatim, remainder via summary) | Responses API is recommended for new projects; Chat Completions "remains supported" |
| `developers.openai.com/api/reference/resources/responses/methods/create` (verbatim for `max_output_tokens`, `store`, `parallel_tool_calls`, `tool_choice`, `tools`, function-call item shapes) | request/response shapes |
| `developers.openai.com/api/reference/resources/chat` (≈40k of 229k verbatim) | comparison only; not used by the adapter |
| `developers.openai.com/api/reference/overview` (full) | authentication, `X-Client-Request-Id`, response headers |
| `developers.openai.com/api/docs/guides/error-codes` (full) | error classes, `Retry-After`, billing 429 codes |
| `developers.openai.com/api/docs/guides/your-data` (full) | retention, training, ZDR |
| `openai.com/enterprise-privacy/` (full, "Updated: January 8, 2026") | training default, DPA, certifications |
| `developers.openai.com/api/docs/models`, `/pricing` (full) | that prices are published per 1M tokens (**no price was copied into the repository**) |
| `developers.openai.com/api/docs/guides/function-calling`, `/reasoning`, `/token-counting`, `/production-best-practices` (partly via summary) | tool calling, output cap includes reasoning tokens, token counting |

Facts the adapter relies on: endpoint `POST https://api.openai.com/v1/responses`; `Authorization: Bearer`; flat function-tool
shape; `max_output_tokens` minimum **16** and it "includes visible output tokens and reasoning tokens"; `function_call`
output items carry `call_id`, `name` and JSON-string `arguments`; `usage.input_tokens` / `output_tokens`; **no
`Idempotency-Key`** on these endpoints; `X-Client-Request-Id` ≤ 512 ASCII characters; API data is not used for training by
default; abuse-monitoring logs are retained up to 30 days by default; Zero Data Retention is available to approved
customers; a DPA can be executed through a form linked from the enterprise-privacy page.

**Not read, so not relied upon:** the deprecations page, the rate-limits guide, the data-residency guide, the sub-processor
list, the changelog, and the Chat Completions function-calling section.

**Unresolved by the pages read:** the default of `store` is stated inconsistently (Responses reference: `true`; Chat
reference: `false`; migration guide: "stored by default for new accounts"). The adapter therefore sets `store: false`
**explicitly** on every request and does not depend on a default. Whether abuse-monitoring retention still applies when
`store` is false is a question for the privacy review (section 5), not something this code decides.

**Pricing and models:** the pages list several model families and per-1M-token prices. None is hard-coded; the operator copies
the prices for exactly the configured model, with the date (section 4). The adapter has no default model.

---

## 4. Provider configuration requirements

A live provider runs only when **all** of the following hold. `php artisan ai:preflight --live` checks the automatic ones.

| Requirement | Setting | Notes |
|---|---|---|
| Engine and surface on | `AI_ENABLED=true`, `AI_SURFACE_<CUSTOMER\|VENDOR\|DEVELOPER>=true` | per surface, independently |
| Provider selected | `AI_PROVIDER=openai` | the default stays `mock` |
| API key | `AI_OPENAI_API_KEY` | read lazily, never logged or echoed; 20–256 characters of `[A-Za-z0-9._-]` |
| Explicit model | `AI_DEFAULT_MODEL` | no default is shipped; `[A-Za-z0-9][A-Za-z0-9._:-]{0,99}` |
| Pricing for that model | `AI_OPENAI_INPUT_USD_PER_MILLION`, `AI_OPENAI_OUTPUT_USD_PER_MILLION`, `AI_OPENAI_PRICING_AS_OF` | copied from the official price list on the recorded date; none shipped |
| Finite budgets | `AI_GLOBAL_DAILY_{REQUESTS,TOKENS,COST_MICRO_USD}` and the same for each enabled surface | positive integers; null/blank/non-integer = configuration error; `0` = hard block |
| Privacy approval record | `AI_EXTERNAL_PROCESSING_*` (section 5) | gate ships closed |
| Suitable cache | database store on InnoDB (section 8) | the test `array` store is rejected on purpose |
| Nested timeouts | `ai.request.*` | connect ≤ attempt ≤ max attempt ≤ round ≤ whole turn (validated; section 11) |
| Output cap | `ai.request.max_output_tokens` ≥ 16 | provider minimum |
| Proxy topology decided | `TRUSTED_PROXIES` **or** `AI_DIRECT_CONNECTIONS_CONFIRMED=true` | required when the public customer surface is on (section 9) |
| Worker capacity attested | `AI_CUSTOMER_WORKER_CAPACITY_CONFIRMED=true` | required when the public customer surface is on (section 11) |

`.env.example` documents every name as a **commented, blank placeholder**. Do not put production values in `.env.example`,
in tests, or in this document.

---

## 5. Privacy and external-processing approval requirements

Sending visitor text to a third party is a **legal and privacy decision**. This code cannot make it and does not claim to
have made it.

**The gate.** `ai.external_processing.approved` is `false` by default and a live provider refuses to run until a
**complete record** exists:

```
AI_EXTERNAL_PROCESSING_APPROVED=true             # a literal true; "yes"/"1"/"on" do not open it
AI_EXTERNAL_PROCESSING_APPROVAL_REF=<ticket or document reference of the signed-off privacy review>
AI_EXTERNAL_PROCESSING_APPROVED_BY=<name or role of the approver>
AI_EXTERNAL_PROCESSING_APPROVED_ON=<YYYY-MM-DD, a real date, not in the future>
```

All four are required together. This stops a casual `…APPROVED=true` and leaves an audit trail, but **it is a record, not
proof**: an environment variable cannot show that the approval is genuine. Only the names of missing settings are ever
reported — never a value (and `ai:preflight` never prints the reference, the approver or the date).

**Privacy review checklist (to be completed by the accountable person before the gate is opened).** None of this is done by
this repository.

- [ ] Data protection assessment covers sending anonymous visitor questions and vendor/developer questions to the provider.
- [ ] Data processing agreement executed with the provider; sub-processors reviewed.
- [ ] Retention understood: abuse-monitoring retention (documented as up to 30 days), whether Zero Data Retention is wanted, and
      the unresolved `store` default (the adapter sends `store:false`).
- [ ] Training use confirmed as off for this account/project.
- [ ] Data residency / cross-border transfer position decided for Ghana-resident users.
- [ ] Privacy notice and the assistant page wording updated to say a third-party AI service processes questions.
- [ ] Decision recorded on which surfaces may use the provider (customer is public and anonymous; vendor and developer are authenticated).
- [ ] Provider-side spending limit configured at the provider account/project as a second, independent cap.
- [ ] Incident contacts and the disable procedure (section 15) agreed.

**Data minimisation — what the model receives.** Per request: the fixed system prompt, the visitor's (scrubbed) message, tool
definitions, and the sanitised results of read-only tools over the *public* catalogue/help content (customer surface). It
never receives: payment references, phone numbers, e-mail addresses, API keys or secrets (masked before sending, below);
order, wallet, transaction or PIN data (no tool can read them); the visitor's session or IP (no identifier field is sent; the
adapter sets none); supplier costs, external mappings or other vendors' private fields (tools return public fields only).
Raw prompts and raw responses are **not logged**; log context is metadata only (ids, counts, status, usage) and passes through
`Redactor` as a second layer.

**Masking before sending (`PromptScrubber`).** Authorization header lines; bearer credentials; `sk-…`, `xt_live_…`, `xt_test_…`,
`whsec_…` key shapes; `api_key=` / `secret:` / `password=` values; platform payment/registration references (`XTRA4U-<…>`,
`AFA-<…>`); Ghana phone numbers (`0XX XXX XXXX`, `+233…`, `233…`, spaced or dashed); international `+CC…` numbers; e-mail
addresses; any run of nine or more digits; any opaque run of 40+ characters. The **public customer surface additionally
masks any 20–39 character run that mixes letters and digits** (a heuristic for unknown reference shapes).

**Limits of the PII heuristics (read this before relying on them).** They match *shapes*, not meaning.

- Names, postal addresses, free-text descriptions of a person, Ghana Card / voter / licence numbers and other national ids
  are **not** detected.
- A short or unusually shaped reference (for example `ABC12`, `pay_12345`) passes through. A phone number written with
  unusual separators, or split by words, can pass through.
- A reference whose long segment has no digit (`XTRA4U-Environment`) is deliberately **not** masked, because header names and
  product words look the same; the strict mode on the customer surface can still over-mask a long header-like name.
- The developer and vendor surfaces do not use the 20–39 character heuristic (their subject matter is full of long names);
  they use the specific shapes above only.
- Masking is applied to what the visitor typed and to what the model answers, not to text the visitor never typed (for
  example a long public product description is sent as the public catalogue holds it).

Treat the masking as a safety net for accidents, **not** as a guarantee that no personal data can reach the provider.

---

## 6. Budget reservation and settlement behaviour

> **M5.7.1 supersedes the storage described in the first M5.7 draft.** Budget accounting and the emergency kill switch no longer
> live in the cache at all; they live in three InnoDB tables that `php artisan cache:clear` cannot touch. Section 6A describes the
> durable design; the rules below (what is reserved, how outcomes settle) are unchanged.

Before **every** provider request (including each retry), `BudgetGuard::reserve()` atomically adds a **provable upper bound**
to the day's counters on the global scope and the surface scope:

- **1 request**;
- **tokens** = input upper bound + output cap, where the input bound = `bytes(system + messages)` + `tool_schema_multiplier` (2) × `bytes(tool definitions)` +
  `per_message_overhead_tokens` (16) × messages + `fixed_overhead_tokens` (512), and the output cap is the clamped `max_output_tokens`
  that is also sent to the provider. The bound relies on a token being at least one byte of UTF-8 for these byte-level tokenisers; it is
  deliberately generous and is **not** an exact count;
- **cost** (micro-USD) = `ceil(input_bound × input_price + output_cap × output_price)` using the configured per-model prices.

Inside one database transaction the guard locks the global and the surface counter rows, and raises each counter with a conditional
`UPDATE … WHERE counter + n <= limit`; if either scope would exceed a limit the transaction rolls back (no partial reservation
survives) and the request is refused with `ai_budget_exhausted` before any network I/O.

| Outcome | What happens to the reservation |
|---|---|
| Success, usage known | replaced by the reported usage and its actual cost (only the unused part is released) |
| Success, usage unknown/garbled | the **full reservation stays** (never treated as zero) |
| Failure that **may** have been processed (timeout after connecting, `5xx`, malformed reply, reset) | the **full reservation stays** |
| Failure that provably was **not** processed (authentication, bad request, rate limited, connection never made) | tokens and cost released; the attempt still counts as one request |
| Retry (only after a provably-unsent attempt) | a **new, independent ledger reservation** (linked by `retry_of`) taken in the same transaction that closes the previous attempt; an earlier ambiguous attempt's hold remains. If the new reservation is refused, nothing changes and the caller closes the previous attempt |
| Settlement repeated | idempotent: a reservation closes once (a state transition under a row lock), so a duplicate settle changes nothing |
| Reported usage **above** the reservation | recorded in full, logged at `critical` (`ai.budget.bound_violated`), and a **day-scoped integrity flag** (a durable row) blocks live execution until the day rolls over or the cause is understood |

**Live prerequisites.** A live provider needs explicit **integer** limits for requests, tokens and cost on the global scope *and*
the request's surface, plus a complete price entry for the model. Missing, blank or non-integer = `ai_misconfigured`
(never "unlimited"); a configured `0` is a hard block (`ai_budget_exhausted`).

**Counter loss.** Counters are checked against the ledger on every reservation (section 6A). A missing row, a negative value or any
counters-versus-ledger mismatch refuses the request (`ai_misconfigured`) — a lost or damaged counter never resets a budget to
zero. Days roll over on the application timezone date.

---

## 6A. Durable budget architecture (M5.7.1)

**Root cause being fixed.** In M5.7 the usage counters, the "day is open" marker, the budget-violation flag and the emergency kill switch
were all cache entries. `php artisan cache:clear`, a flushed or restarted cache server, or deleting rows from the `cache` table
therefore zeroed the day's spending **and** re-enabled AI that had been shut off. A cache is not a place to keep a financial limit or a safety switch.

**Storage** (migration `2026_11_23_000001_create_ai_durable_budget_and_kill_switch_tables`, explicit `InnoDB`, idempotent, MariaDB/MySQL and SQLite compatible):

| Table | Purpose |
|---|---|
| `ai_usage_counters` | one row per (`day`, `scope`) where scope is `global` or `surface.<name>`: `requests`, `tokens`, `cost_micro_usd`, `successes`, `failures`. **Unique (`day`, `scope`).** |
| `ai_budget_reservations` | the ledger: one row per reservation (`id` primary key, `day`, `surface`, `state`, `retry_of`, the held `requests/tokens/cost_micro_usd`, the original reserved figures, `created_at`, `closed_at`). Numbers, ids and states only — never prompt or response text |
| `ai_runtime_flags` | `kill_switch` (`engaged` / `released`) and the per-day `budget_violation:{day}` integrity flag |

Nothing in the budget or kill-switch classes touches the cache (a test enforces it).

**The invariant.** For every (day, scope), the counter equals the sum of the ledger rows that count towards it. Every reservation
verifies this under its lock. A missing counter row for a day that has ledger rows, a missing ledger row, a negative counter or any mismatch is
treated as damage: the request is refused with `ai_misconfigured`, nothing is created, and an empty counter is **never** read as "zero used".

**Reservation (one transaction, `DB::transaction`, 3 attempts for deadlocks).**
1. Counter rows are created if absent (`insertOrIgnore`, safe to race).
2. The rows are locked in a fixed order (global before surface) — a harmless write first so SQLite takes its write lock up front, then `SELECT … FOR UPDATE`.
3. Invariant and integrity flag are checked.
4. The reservation id is looked up (idempotent reserve: the same id returns the same reservation; an id used for another surface is refused).
5. For a retry, the previous attempt is closed in this same transaction.
6. Limits are checked in PHP and enforced again by the conditional `UPDATE … WHERE counter + n <= limit` (exactly one row must change).
7. The ledger row is inserted. Any failure rolls the whole transaction back: there is no half reservation.

**States.** `open` → `settled` (usage known; the held amount is replaced by the actual figures), `settled_unknown` (provider succeeded but
usage unknown: the **full** reservation stays), `failed_unknown` (the request may have been processed: the **full** reservation stays),
`released` (certainly not processed: tokens and cost returned, the request is still counted). A close is a transition under a row lock and
therefore happens at most once. A reservation stays `open` if the process dies before settling: it keeps its hold (the conservative choice);
`ai:preflight` warns about old open reservations.

**Provider gate.** `OpenAiProvider::send()` refuses (`AiProviderException`, certainly-not-sent) unless the kill switch is released **and** the request names a
reservation that is open, in the ledger, for the same surface — checked before the payload is built, the key is read, or any HTTP call is made. The orchestrator
re-checks the kill switch after reserving and before every retry and releases the reservation when it stops. The mock provider obeys the same reservation rules.
No code path calls a provider without the ledger and the switch (tests assert this, and that `OpenAiProvider` is the only class that uses HTTP).

**Failure handling.** Every database failure while reserving, settling or reading limits fails **closed** (`ai_misconfigured`; the request is not sent). A failure
while settling leaves the reservation `open` with its full hold. A failure is never answered by creating new counters at zero.

**Retained cache dependence (unchanged, limitation).** The per-IP/per-principal **rate limits, the concurrency slots and the circuit breaker are still cache based**
and reset when the cache is cleared. They protect capacity, not money: a cache clear can briefly allow more concurrent requests or reset an open breaker, but it
cannot give budget back or re-enable a shut-off AI.

---

## 7. Hard-budget guarantees and limitations

**What is guaranteed (and tested, including 8 simultaneous PHP processes on MariaDB 11.4.13 with the database cache store):**

- *Admission:* at every instant, `settled usage + all held reservations ≤ limit` for requests, tokens and cost, on every scope. Concurrent
  processes cannot jointly overshoot a limit: exactly as many requests are admitted as fit, the rest are refused.
- A refusal on one scope leaves no residue on another.
- Unknown or ambiguous outcomes never lower the counted spend.
- A lost or unreadable counter stops live execution instead of restarting from zero.
- *Durability (M5.7.1):* clearing the whole cache — `cache:clear`, a cache-server restart, deleting the `cache` table — never gives budget back and never re-enables AI.
  Tested for the global and surface request, token and cost limits, with reservations held open across the clear, and with a clear in the middle of a provider call.
- Retries hold independent reservations; unknown provider outcomes keep the full reservation; duplicate settlement and duplicate reservation ids change nothing.

**What is *not* guaranteed:**

1. **The bound is only as good as its assumptions.** If the provider counts tokens differently from the bound (a new tokenizer, hidden
   overhead) and reports more than was reserved, the overshoot is detected *after the fact*: it is recorded, logged at `critical`
   and stops live execution for the day. At most the requests already in flight can exceed the limit before that.
2. **Money is computed from the configured prices.** Wrong, stale or copied-for-the-wrong-model prices make the cost limit wrong.
   The provider's own billing is authoritative; set a provider-side project/organisation limit as an independent cap.
3. **The durable tables can still be lost by direct database action.** A whole-cache flush is now harmless (M5.7.1), but `TRUNCATE`/`DELETE` of the three AI tables, a database
   restore from an older backup, or dropping the schema removes the history. Partial loss is detected (counters versus ledger; section 6A) and stops live execution; **loss of the
   counters and the ledger together is indistinguishable from a quiet day** — a missing day is just a day without usage. Restoring a backup restores the older spend figure, so
   after any restore, keep the kill switch engaged until the day's provider-side spend has been reconciled. A deleted `kill_switch` row or table counts as *engaged*.
   Do not edit these tables by hand.
4. **Provider billing for a request that was abandoned or timed out is not documented** by the pages read; the engine therefore
   treats it as billed (the reservation stays) — conservative for the budget, but it can strand headroom for the day.
5. **The reservation is a worst case**, so the limit is reached earlier than the real spend would suggest when many requests are in
   flight: size the daily limit to hold several worst-case requests (the preflight check `budget_fits_a_request` fails when a limit cannot
   hold even one; `max_request_cost` prints the worst-case figure).
6. Redis and Memcached are not exercised by this repository's tests (section 8).
7. The limits are per **application day**, not a rolling window.
8. **Stale open reservations stay charged.** A worker killed between reserving and settling leaves its hold in place for the day (never refunded automatically,
   because the provider may have processed it). `ai:preflight` warns about them; reconcile with the provider before adjusting anything.
9. **Ledger scan cost.** Each reservation verifies the counters against the day's ledger rows under the lock — O(rows today). That is fine for the intended volumes
   (limits are hard-capped in requests per day); revisit it if the daily request limit is raised to very large values.
10. **Rate limits, concurrency slots and the circuit breaker are cache based** (section 8) and reset on a cache clear.
11. A **SQLite file database** is not a supported shared store for the database cache across processes (the cache's read-then-write raises "database is locked"); production is MariaDB.

---

## 8. Cache engine requirements

Rate limits, the concurrency slots and the circuit breaker depend on the shared cache giving **atomic counters and
mutually exclusive locks across processes**. (Budgets and the kill switch no longer do: they are in the database, section 6A. The cache verdict still gates the live provider.)

`CacheEngineCheck` (read-only: `SELECT`s against `information_schema`, never an `ALTER`/`CREATE`/write) classifies the configured default store:

| Store | Verdict |
|---|---|
| `database` on MariaDB/MySQL with **both** `cache` and `cache_locks` on **InnoDB** | suitable — verified on **MariaDB 11.4.13** |
| `database` with a missing table, or MyISAM/any non-InnoDB engine | **not suitable** (MyISAM locking and atomicity are not what the budgets assume; production's `orders` table was once found to be MyISAM, so engines are never assumed) |
| `database` on SQLite | not suitable |
| `redis`, `memcached` | suitable *by design*; **not exercised** by this repository's tests. Requirements: persistence on, no eviction of `ai:*` keys |
| `file`, `array`, `null`, `dynamodb`, anything else | not suitable |

The verdict gates the live provider (the provider refuses to run on an unsuitable cache) and is shown by `ai:preflight`. Converting a MyISAM table to InnoDB is a
deliberate DBA action; this code never does it. Expired rows are indexed by `2026_10_09_000002_index_cache_expiration`.

---

## 9. Trusted-proxy configuration

The anonymous customer endpoint is limited **per client IP** (10 per minute and 60 per hour). That is only meaningful if the
application knows the real client address.

- `TRUSTED_PROXIES` is a comma-separated list of the **literal addresses or CIDR ranges of the proxies/load balancers that sit directly in
  front of the application**. Only requests that arrive from one of them have `X-Forwarded-For`, `-Host`, `-Port`, `-Proto` believed
  (the prefix and AWS-ELB variants are not honoured). Everyone else's forwarded headers are ignored.
- **No wildcard.** `*`, `**`, `REMOTE_ADDR`, hostnames, malformed entries and ranges wider than `/8` (IPv4) or `/16` (IPv6) (so `0.0.0.0/0` and
  `::/0` cannot sneak in) are **rejected and ignored**, never half-trusted; `ai:preflight` fails and reports how many were dropped.
- **No address is built in or guessed.** The production proxy/CDN addresses were not supplied to this milestone; they must be obtained from the hosting provider /
  CDN documentation or the server's network configuration and entered by the operator.
- **Direct connections:** if the application is not behind any proxy, set `AI_DIRECT_CONNECTIONS_CONFIRMED=true` to attest that, and leave `TRUSTED_PROXIES` empty.
- **Neither set** while the customer surface is enabled = the preflight **fails** (`proxy_topology`). Behind an unlisted proxy every visitor would share
  one address: the per-IP limit would throttle everyone together (fail-safe, but an outage of the feature, not a spoofing hole).
- Client resolution: the rightmost address that is not a trusted proxy is the client, so a client cannot choose its own address by prepending one.
- **Key shape:** IPv4 keys are the address; IPv6 keys are the **/64** prefix (a subscriber owns the whole prefix; keying by full address would let one visitor
  rotate through 2⁶⁴ keys); an absent or unparseable address maps to one shared `unknown` bucket (stricter, never looser).
  Every limiter uses `ClientIpKey::of($request->ip())`, so the minute and hour limits and the vendor/developer fallbacks agree.

The proxy tests use RFC 5737 / RFC 1918 documentation ranges only.

---

## 10. Rate-limit and concurrency behaviour

Layers, outermost first (each refuses before the next is consulted):

1. **HTTP per IP** (customer): 10 requests/minute and 60/hour per `ClientIpKey`, in the shared cache. Page view: 60/minute.
   Vendor and developer endpoints: 10/minute per authenticated vendor (IP-keyed only if unauthenticated).
2. **Engine per principal:** `ai.rate_limits.<surface>.per_minute` (customer 6, vendor 20, developer 20 by default), per guest session / vendor.
3. **Circuit breaker:** 5 provider failures in 120 s open it for 60 s; afterwards **exactly one** probe request is allowed (verified across 8 processes).
4. **Concurrency slots:** `ai.concurrency.max_in_flight` (default 2) cache-lock slots across **all** PHP processes. A refused request fails
   **immediately** (`ai_busy`, HTTP 503): it never waits and so never holds a worker.
5. **Budget reservation** (section 6).

An assistant **turn** (round 1 + tools + round 2) holds **one** slot for its whole length, not one per round: the orchestrator *borrows* the turn's slot. The slot's
TTL is at least `max(lock_seconds, deadline + 5, turn_deadline + 5)`, so it cannot expire mid-turn, and it also expires on its own if a process dies.

---

## 11. Request deadlines and worker-exhaustion safeguards

The assistants are **synchronous**: a visitor's turn holds a PHP worker while the provider answers. There is no queue and no streaming.

- **Timeouts nest** (validated for a live provider; a violation is `ai_misconfigured`):
  `connect_timeout (5) ≤ timeout (15) ≤ max_timeout (20) ≤ deadline (20) ≤ turn_deadline (40)` seconds by default.
- **One deadline per turn:** both rounds carry the same absolute `deadlineAt`. A provider attempt is clamped to the time left; a retry only starts if a
  full attempt still fits; a round with **under 2 seconds** left is refused (`ai_busy`) instead of started.
- **Tools are bounded:** each tool call has a time limit, the batch has a call cap, and on MariaDB/MySQL every statement a tool runs is given a **server-side
  statement time limit** (`max_statement_time` / `max_execution_time`, from `ai.tools.limits.max_execution_seconds`), reset afterwards. On MariaDB 11.4.13 a runaway
  query is cut off by the server after the limit; SQLite (tests/local) has no such limit.
- **Bounded public concurrency:** `max_in_flight` turns at once, system-wide (section 10).
- **What the code cannot know:** the web server / PHP-FPM request timeout and the number of workers. The web-server timeout **must exceed
  `turn_deadline_seconds`** (+ margin), and `max_in_flight` × the longest turn must leave workers for checkout and payments. This is an operator
  attestation (`AI_CUSTOMER_WORKER_CAPACITY_CONFIRMED`) and a manual item in the preflight; **until it is confirmed, keep the customer assistant disabled.**
  If worker capacity cannot be shown, the public customer assistant stays off — that is the safe, documented default.

---

## 12. Catalogue category filtering and completeness

The customer assistant reads the *public* catalogue through `PublicCatalogueReadService`, which must show exactly what the storefront shows.

- **Before M5.7** the service read the first 400 rows of a vendor's products in id order and filtered category and search words in PHP, so a matching product
  hidden behind 400 rows of another category was invisible, and the `incomplete` flag could not be trusted.
- **Now** the category (and the public-field search words) are decided **in the database** with the storefront's own default rules: a product belongs to its
  `category` if the description is valid JSON containing a non-empty **string** `category`; otherwise (no description, empty, plain legacy text, malformed JSON, a JSON
  array/scalar, no key, null/empty/non-string `category`) it belongs to the configured default category (`storefront.default_category`). The expression is nested in
  `CASE` branches so malformed JSON never reaches a JSON function. Case matters (`"DATA"` is a different, unknown category, as on the storefront).
- **Dialects:** SQLite (`json_valid`, `json_type`, `json_extract`) and MariaDB (`JSON_VALID`, `JSON_TYPE`, `JSON_EXTRACT`, `JSON_UNQUOTE`) — both **tested**. The same
  SQL is chosen for MySQL, but **MySQL was not tested**; an engine with neither dialect falls back to filtering in PHP.
- **PHP remains the authority:** the SQL only narrows. Every candidate is re-checked in PHP with the original strict rules (so a collation that compares case-insensitively
  can only produce extra candidates that PHP drops, never extra visible products).
- **Search words** match only the public text (name, service — or network when there is no service — size, validity), case-insensitively; `%`, `_` and `\` are removed
  from words, never treated as wildcards; every value is a bound parameter, and the raw SQL lives in three audited private helpers (an architecture test enforces this).
- **Completeness:** the scan window (`SCAN_LIMIT = 400`) now bounds only rows that **already match**. `incomplete` is `true` only when more than 400 matching rows
  exist in a category for the owner's own products or for reseller listings; 400 matching rows is complete, 401 is not. 600 non-matching rows never set it.
- Hidden/inactive products, vendors that are unassigned or unapproved, closed categories, non-resellable sources and inactive listings stay invisible (tested with
  more than 400 rows, on SQLite and MariaDB 11.4.13).

---

## 13. Preflight command usage

```
php artisan ai:preflight            # report; exit 0 unless AI is enabled with an unrecognised provider
php artisan ai:preflight --live     # strict: exit 1 unless EVERY automatic live-provider gate passes
php artisan ai:preflight --json     # machine-readable (combine with --live)
```

The command is **read-only**: no network request, no prompt, no provider, no order/payment/notification, no write to configuration, a cache key or a table. It only reads
configuration, table metadata and a few cache keys. A pass **never enables AI**. It reports one of four situations and never the words "production ready":

| State | Meaning |
|---|---|
| `disabled` | **Safe because AI is disabled** (engine off, kill switch engaged, or no surface enabled). No assistant answers and no provider is called. |
| `mock` | **Configured for mock operation.** Enabled surfaces answer from the offline mock only; no external call is possible. |
| `live`, blocked | A live provider is selected but a gate fails: every request fails closed before reaching the provider. |
| `live`, every automatic check passes | **Ready for a separately approved activation** — still not "production ready": manual items and explicit approvals remain. |

Each check is `PASS`, `WARN`, `FAIL` (a live-provider gate that is not met — *not* unsafe while AI stays disabled or on the mock), `MANUAL` (cannot be verified from code: web-server timeout,
legal sign-off, spend approval, never clearing the cache) or `INFO`. Secrets, the approval reference, the approver and proxy addresses are never printed.

---

## 14. Safe mock-mode operation

The default and the only mode tested end-to-end in production-like conditions: `AI_PROVIDER=mock` (offline, scripted, no network). With `AI_ENABLED=true` and a
surface enabled the assistants answer with the mock provider's canned output — useful for exercising the pages, never for real answers. The mock **refuses to run in a
production environment** unless `AI_MOCK_IN_PRODUCTION=true`, so it cannot silently answer real visitors. Mock usage is counted the same way, and an unset budget is allowed
for the mock only. The test suite proves that the mock path makes no HTTP request.

---

## 15. How to disable AI immediately

In order of speed:

1. `php artisan ai:kill-switch engage [--reason=…]` — a durable switch (the `kill_switch` row of `ai_runtime_flags`); takes effect for **all** processes on their next request,
   **wins over configuration**, blocks every assistant surface and every provider request, and has no business side effects. `ai:kill-switch status` shows the state; `release` re-enables
   (only when the cause is resolved).
   - **It ships engaged.** The migration seeds `engaged`, re-running it never changes the value, and **AI stays off until an operator runs `php artisan ai:kill-switch release`**.
   - Only the exact stored value `released` lets AI run. A missing row, a missing table, an unknown value or any database error counts as **engaged** (fail closed).
   - `cache:clear`, a cache restart, an application restart, a deploy or a new PHP process **cannot** release it. If the command cannot write the row it exits `1` and says nothing was changed.
   - Because it is a database value, a restore of an older database backup restores the older switch state too — re-check `ai:kill-switch status` after any restore. **Section 19 (M5.7.2) closes this once an independent anchor is configured:** an older row can then no longer release AI. Without the anchor it remains true.
2. Set `AI_ENABLED=false` (and/or the surface flags `AI_SURFACE_CUSTOMER|VENDOR|DEVELOPER=false`), then refresh the configuration cache (`php artisan config:clear`
   / `config:cache` as the deployment does).
3. Set `AI_PROVIDER=mock` (the mock then refuses to run in production).
4. **Revoke or rotate the provider API key at the provider** — the only step that stops spending even if the application is misbehaving.
5. Remove the public link/route exposure at the web server if the customer page must disappear entirely.

`cache:clear` is now safe with respect to budgets and the kill switch (it does not give budget back or re-enable AI), but it still resets the rate limits, concurrency slots and
circuit breaker (sections 8 and 10), so avoid it while a live provider is enabled.

---

## 16. Production monitoring requirements

Nothing here is wired to an alerting system by this milestone; the **operator must** do so before launch. Log channel: `ai.logging.channel`; every event is metadata only
(ids, counts, status, usage, durations) and masked by `Redactor`.

| Signal | Source | Alert when |
|---|---|---|
| Budget bound violated | `ai.budget.bound_violated` (critical) | any occurrence — live execution is stopped for the day |
| Request failures | `ai.request.failed` (`error_code` is `ai_unavailable` for every provider failure; the fixed, provider-text-free `error_detail` tells them apart, e.g. "The provider rejected the credentials.") | any credentials rejection; sustained others |
| Rejections | `ai.request.rejected` (field `error_code`: `ai_budget_exhausted`, `ai_misconfigured`, `ai_busy`, `ai_unavailable`, `ai_disabled`) | sudden rise; any `ai_misconfigured` in production |
| Circuit breaker | `ai_unavailable` outcomes | opening more than once an hour |
| Spend | `UsageMeter::snapshot()` (cost/tokens/requests per scope) vs. the provider's own usage dashboard | reconciliation gap, or > the approved daily figure |
| Preflight | scheduled `php artisan ai:preflight --live --json` | any regression from the approved baseline |
| Worker saturation | web-server/FPM worker utilisation; `ai_busy` rate | checkout latency rising while AI is on |
| Cache health | InnoDB engine; deadlocks/lock waits on `cache` / `cache_locks` | any |
| Durable store | `ai:preflight` `durable_budget_store` (tables, InnoDB, ledger consistency) and the stale-open-reservation warning; lock waits on `ai_usage_counters` | any failure; open reservations older than the request deadline |
| Customer assistant turns | `ai.customer_assistant.turn` / `.error` | error ratio |

Monitor the **provider** side too: account-level spend alert and project limit, key-usage anomalies, and the provider's status page.

---

## 17. Incident response and provider failure handling

**Provider failure behaviour (automatic).** `5xx`, `429`, timeouts and malformed replies surface to the visitor as a generic message (no provider text, no key, no
body); a live provider is not retried after an ambiguous attempt; repeated failures open the circuit breaker (visitors get `ai_unavailable` until the cool-down and a single
probe succeed); the rest of the marketplace is unaffected (the assistants hold no business state).

| Incident | First actions |
|---|---|
| Provider outage / sustained errors | nothing required (circuit breaker); if prolonged, engage the kill switch to stop probing; check the provider status page |
| `authentication` errors | engage the kill switch; verify the key was not revoked or rotated; do **not** retry in a loop |
| Suspected key leak | revoke the key at the provider **first**, then engage the kill switch; rotate; review provider usage for the exposure window |
| Unexpected spend / `bound_violated` | engage the kill switch; compare `UsageMeter::snapshot()` with the provider dashboard; correct prices/limits/bound before re-enabling (the day flag clears at the date rollover; never clear the cache) |
| Ledger/counter mismatch (`ai_misconfigured` on every call; `ai:preflight` `durable_budget_store` fails) | someone edited, truncated or restored the AI tables; keep the kill switch engaged, reconcile the day against the provider's usage, restore the tables from a consistent backup or leave AI off until the next day; **never** zero counters by hand while spending is uncertain |
| Privacy complaint / personal data suspected in prompts | engage the kill switch; logs hold no prompt text, so reconstruction relies on the provider's retention — follow the privacy incident procedure and the DPA; review `PromptScrubber` coverage |
| Prompt-injection / abuse report | tools are read-only and allow-listed; engage the kill switch while investigating; keep the transcript the reporter supplies (the system stores none) |
| Worker exhaustion / checkout slowdown | engage the kill switch (immediate), then review `max_in_flight`, the turn deadline and the web-server timeout |

After any incident: record the timeline, whether spend or personal data was involved, and re-run `ai:preflight --live` before releasing the kill switch.

---

## 18. Outstanding launch blockers

M5.7 completes the engineering controls. A live launch still requires **all** of the following, none of which this repository or this milestone provides:

1. **Legal / privacy approval** of external processing (section 5), the DPA, and the user-facing notice. No approval is claimed anywhere.
2. **Production budget values** — requests, tokens and cost limits for the global scope and each enabled surface — approved by the person accountable for the spend. None are shipped or suggested.
3. **Model choice and pricing** copied from the provider's official price list for exactly that model, with the date (the review of section 3 recorded no page date for the developer docs).
4. **A provider-side spending cap** configured at the provider account/project as an independent safeguard (not verified from here).
5. **Production proxy addresses** (`TRUSTED_PROXIES`) or confirmation of direct connections. Not supplied; not guessed.
6. **Worker capacity and web-server timeout** confirmed for synchronous turns (section 11), or the customer assistant stays disabled.
7. **Production cache engine verified** — `ai:preflight` on the production host must show `cache` and `cache_locks` on InnoDB (verified here only on a disposable MariaDB 11.4.13).
8. **A real live smoke test**, with a real key under an approved, tiny budget — never performed. The adapter has only been run against HTTP fakes; the official response shapes were taken from documentation, and
   fields the pages did not verbatim confirm (the full `status` enum, error wrapper, `usage` detail) are handled defensively (unknown ⇒ fail closed or unknown usage).
9. **Monitoring and alerting** (section 16) wired to a real destination, and an on-call owner.
10. **Redis/Memcached or MySQL**, if ever used instead of MariaDB + database cache, need their own verification: they were **not tested**.
11. Re-verification of the provider documentation at launch (it carries no publication date) and of the unresolved `store` default.
12. The six known pre-existing suite failures (outside the AI engine) are unrelated to this work but should be triaged separately.
13. **Apply the M5.7.1 migration in production** and confirm `ai:preflight` reports `durable_budget_store` passing (all three tables on InnoDB, ledger consistent). Until it is applied the kill switch
    is unreadable and therefore engaged — AI cannot run, which is the intended failure mode. After it is applied the switch is **engaged**; releasing it is a deliberate operator step.
14. Back-up/restore and database-administration procedures for the three AI tables (section 7, item 3), and a decision on who may run `ai:kill-switch release`.

15. **(M5.7.2) Independent integrity anchor** (19.4): a location outside the database's restore domain, with its own backup lifecycle, a key, an operator attestation and the one-time `ai:integrity initialise`. **Not available; this is the blocking item.** Without it a database restore can silently forget spend and re-enable AI.
16. **(M5.7.2) Provider-side spending cap confirmed** and referenced (19.8). A statement only; not verifiable from here.
17. **(M5.7.2) Cache controls** (19.9): `cache:clear` and cache restarts reset rate limits and the circuit breaker, and a lost lock store can briefly double in-flight capacity. Avoid them while live; a cache outage fails closed. Redis and Memcached are untested.
18. **(M5.7.2) Restore ownership**: a named owner and a rehearsed run of 19.6.

A green `ai:preflight --live` is a *necessary* condition for requesting activation, never a sufficient one.

---

## 19. Budget integrity and recovery protection (M5.7.2)

**Status: BLOCKED on infrastructure that does not exist yet.** The protocol below is implemented and tested, but it needs an *independent*
anchor location that this project does not have. No such service, host, volume or credential was invented. Until one is provisioned, attested and
verified, **live stays blocked** (`ai:preflight --live` fails `integrity_anchor`) and the kill switch stays engaged. A truthful blocker is shipped
instead of a false guarantee.

### 19.1 The threat: the database is rolled back, not attacked

M5.7.1 made the budget counters, the ledger and the kill switch durable. All three live in **one database**, so anything that moves that database
backwards in time moves spend and the safety switch backwards **together**, and nothing inside the database can notice. A hash chain, a sequence
number or a checksum stored in the same database does **not** help: a restore returns the chain, the number and the checksum to the older, still
self-consistent state. This document therefore does not claim that any same-database mechanism solves the problem, and a test
(`DatabaseRollbackDetectionTest`, the "control without an anchor" test) shows the rollback is invisible without the anchor.
A separate schema on the same server is **not** independent either: the same restore, the same snapshot or the same host failure takes it too.

### 19.2 Scenario analysis

"M5.7.1 alone" is the durable database with no anchor. "With anchor" is M5.7.2 with a working independent anchor. Every "detected" entry
below is a **tested** behaviour (`tests/Feature/Ai/Integrity/*`); nothing here is claimed for an anchor that is not independent.

| # | Scenario | M5.7.1 alone | With an independent anchor |
|---|---|---|---|
| 1 | Both budget tables deleted or truncated | Detected as damage only when a counter is missing for a day that still has ledger rows; a **fully empty** pair looks like a new day and spending restarts from zero. **Cannot detect.** | Detected (the anchor holds records the ledger lacks); provider blocked, kill switch engaged, reconciliation required |
| 2 | Restore from an older backup | **Cannot detect** (the old state is internally consistent; spend since the backup is forgotten and the kill switch may read `released`) | Detected: the anchor sequence is ahead of the database; reservations missing; latched |
| 3 | Point-in-time recovery | **Cannot detect** (same as 2) | Detected the same way, for any recovery point earlier than the anchor's last accepted write |
| 4 | Ledger restored, counters not | Detected (counters differ from the ledger) | Detected, and also against the anchor |
| 5 | Counters restored, ledger not | Detected (counters differ from the ledger) | Detected, and also against the anchor |
| 6 | Kill-switch row restored to an older `released` state | **Cannot detect**; AI re-enables silently | Detected: the anchor generation and state disagree with the row, so the switch reads **engaged**; a lone row can no longer release AI |
| 7 | Corruption or partial recovery | Detected only where it breaks the counters-equal-ledger invariant | Detected where it differs from the anchor's per-reservation records or totals |
| 8 | Restore while provider requests are in flight | **Cannot detect** (the in-flight reservations are gone) | Detected at settlement and at the provider gate: the anchor knows the reservation, the database does not; latched; no send |
| 9 | Anchor unavailable | not applicable (no anchor) | Fails closed: no reservation, no send; the kill switch reads engaged. Nothing is latched, because an unreachable anchor is not evidence of damage |
| 10 | Anchor itself replaced, deleted or rolled back | not applicable | Detected when it disagrees with the database (it is *behind* the database, or its identity differs from the one the database is bound to). An anchor that is lost **and** a database that is also rolled back is not detectable by anyone; that is why the anchor needs its own backup lifecycle (19.4) |

Residual risks that remain **even with** an independent anchor: an operator who runs a recovery command with a false reason; both stores destroyed in
the same event (the anchor's separate backup lifecycle is the mitigation); the provider-side cap (19.8) being absent; and everything in 19.10.

### 19.3 Architecture

```
reserve:  [DB transaction]  lock gate row -> verify anchor vs DB -> ANCHOR.reserve (assigns seq) -> insert ledger row (anchor_seq)
send:     the provider gate re-checks the kill switch, the ledger row AND the anchor record -> only then HTTP
settle:   ANCHOR.close first -> then the DB transition   (the database may only ever be HIGHER than the anchor, never lower)
```

* **`IntegrityAnchor`** (contract). Authoritative, append-only record of: an anchor id, a monotonically increasing sequence, a kill-switch state with a
  generation, a reconciliation status, and one record per reservation. Totals are *derived* from the records. Operations: `read`, `initialise`,
  `reserve`, `close`, `setKillSwitch`, `setReconciliation`. Anything unknown fails closed.
* **`FileIntegrityAnchor`** (the reference driver) is one HMAC-SHA256 authenticated JSON document, written under an exclusive lock with
  temp-file + fsync + atomic rename, plus a best-effort audit log. It is a *reference implementation of the protocol*; it is exactly as independent as
  the directory it is pointed at. The configuration is refused unless the path is absolute, exists, is writable and lies outside `base_path`,
  `database_path` and `storage_path`, and the key is at least 32 characters.
* **`UnconfiguredIntegrityAnchor`** is the default (`AI_INTEGRITY_ANCHOR_DRIVER=none`). Every operation fails; therefore live cannot run.
* **Independence cannot be verified by code.** Preflight therefore also requires an operator *attestation* and a *reference* to where the
  independence is recorded (`AI_INTEGRITY_ANCHOR_INDEPENDENCE_ATTESTED`, `AI_INTEGRITY_ANCHOR_INDEPENDENCE_REF`). That is a statement by a person, not a measurement.
* **Binding.** The database stores the anchor id (`integrity_anchor_id`, 16 hex characters) and the kill-switch generation in `ai_runtime_flags`. A database that
  is bound to a different anchor, or to none, is refused.
* **Sequence.** `ai_budget_reservations.anchor_seq` (nullable, unique) is the only schema change (migration
  `2026_11_24_000001_add_integrity_anchor_sequence_to_ai_budget_reservations`; additive, idempotent, reversible, existing rows untouched; the M5.7.1 migration is not modified).

**Ordering rules (all tested, including with injected faults and in separate PHP processes):**

| Situation | Behaviour |
|---|---|
| The anchor confirms, then the database fails | The provider is **not** called. The charge stays in the anchor (conservative). The next request finds the anchor ahead, latches, and an operator must reconcile |
| The database would accept but the anchor fails or is unknown | Nothing is sent; the database transaction rolls back |
| Unknown outcome of any anchor write | Fail closed: treated as not confirmed |
| Close: anchor accepted, database failed | The database stays higher than the anchor (the safe direction); settled later by reconciliation |
| Retry of a reservation | The previous attempt is closed in the same anchor write that creates the new one |

**Verification rules.** The anchor sequence must equal the highest `anchor_seq` in the ledger (behind = rollback; ahead = anchor replaced).
Every anchor record of the day must exist in the ledger with the same sequence and charges that are **not lower**. Database counters must be **not lower**
than the totals derived from the anchor. A database that is *higher* than the anchor is tolerated (it is the conservative direction).

### 19.4 What the operator must provide (the blocker)

1. A location **outside the database's restore domain**: a different host or volume, with credentials that the database host's backup and restore tooling cannot
   write, and an **independent backup lifecycle** (its own snapshots and retention, never restored together with the database). Ideally append-only or object-lock storage.
   On the current cPanel host, a directory beside the application or on the same account or volume does **not** qualify.
2. A driver for it. `FileIntegrityAnchor` fits a mounted, independently backed-up volume. A remote object store or a second host needs a new driver implementing `IntegrityAnchor`;
   that is future work and nothing is assumed about it here.
3. `AI_INTEGRITY_ANCHOR_DRIVER`, `AI_INTEGRITY_ANCHOR_PATH`, `AI_INTEGRITY_ANCHOR_KEY` (kept outside the database), the attestation and its reference.
4. `php artisan ai:integrity initialise --reason="..."` once, with the kill switch left engaged afterwards.
5. A recorded owner for restore procedures (19.6).

Until all five exist: `ai:preflight --live` fails `integrity_anchor`, `OpenAiProvider::configurationProblems()` reports it, and every live reservation is refused.

### 19.5 Kill switch

* **Disabled by default.** The migration seeds `engaged`; `ai:integrity initialise` leaves it engaged.
* **With an anchor**, AI runs only if **all** hold: the database row is `released`, the anchor state is `released`, reconciliation is `ok`, and the database generation equals the
  anchor generation. Anything else (missing, unreadable, mismatched, older) reads as **engaged**. A restored older database therefore cannot silently re-enable AI.
* Survives restarts, deploys and `cache:clear` (no cache is involved).
* `release` writes the anchor first and then the database, and refuses unless readiness and integrity verification both pass. **Releasing never bypasses** the budget limits,
  the privacy approval gate, the surface flags or the provider configuration; it only removes this one stop.
* `engage` writes the database even when the anchor is unreachable (the safe direction), then reports the failure.
* Recovery is always an explicit operator action. Nothing auto-releases.

### 19.6 Restore and recovery procedure

Applies to **any** database restore, point-in-time recovery or suspected damage while live is configured. **Do not release the kill switch to "see if it works".**

1. **Stop first.** `php artisan ai:kill-switch engage --reason="restore in progress"`. If the database is not yet restored, set `AI_ENABLED=false` as well.
2. Restore the database. Do **not** restore or touch the anchor location.
3. `php artisan ai:integrity verify` (read-only). It reports each problem class. A restore will normally show: anchor sequence ahead of the database, missing reservations, an older kill-switch generation.
4. Decide, and write the decision down. Missing history is **never** treated as zero use and no figure is invented:
   * **The database is the older copy and the anchor is authoritative (the normal case):** `php artisan ai:integrity reconcile --reason="..."`. It restores missing records from the anchor's own figures, never lowers a charge, rebuilds
     the counters from the ledger, and marks reconciliation `ok` **only if** verification then passes. If you restored onto a database that was never bound to this anchor, add `--rebind-database`.
   * **The anchor is older than a database you trust (anchor lost or rolled back):** `reconcile --adopt-database-rows`, or, if the anchor is gone, create a new one with `initialise --accept-database-history --replace-bound-anchor`. This
     **accepts the database's history as the truth**; do it only when you can show that nothing was spent which the database does not show (provider usage dashboard, invoices).
5. `php artisan ai:integrity verify` must be clean. Compare the day's usage with the provider's own usage dashboard for the same period.
6. Only then, deliberately: `php artisan ai:kill-switch release`. If integrity is still unproven the command refuses and says why.
7. Record who decided what, and the references used.

If integrity cannot be proven, **the provider stays blocked, the switch stays engaged, and an explicit recovery decision is required.** There is no automatic path out.

### 19.7 Commands

| Command | Effect |
|---|---|
| `ai:integrity status` / `verify` | read-only; no change; exit 1 when a problem is found |
| `ai:integrity initialise --reason= [--accept-database-history] [--replace-bound-anchor]` | create the anchor from the current ledger, number the rows, bind the database; the switch stays engaged |
| `ai:integrity reconcile --reason= [--rebind-database] [--adopt-database-rows]` | repair the database from the anchor (never lowers a charge); never releases the switch |
| `ai:kill-switch engage / release / status` | `release` additionally requires integrity to verify; `status` shows the anchor state |

### 19.8 Provider-side spending cap

`AI_PROVIDER_SPENDING_CAP_CONFIRMED` plus `AI_PROVIDER_SPENDING_CAP_REFERENCE` record that an account owner has set a hard cap in the provider's own
dashboard and where that is documented. `ai:preflight --live` fails `provider_spending_cap` until both are given. **This is a statement, not a check:** no provider API is called, no cap
value is suggested or assumed, and nothing here confirms that a cap exists. It is the one control that still limits spend if every control in this application, and the anchor with it, were lost.

### 19.9 Cache-backed controls (reviewed, not rewritten)

Tested in `CacheControlsTest` against the database cache store.

| Event | Rate limits | Circuit breaker | Concurrency slots | Budget / anchor / kill switch |
|---|---|---|---|---|
| `cache:clear` | reset | reset (closed) | **not** cleared (they live in `cache_locks`) | unaffected (no cache is used) |
| Cache restart, `cache_locks` truncated, lock store lost | reset | reset | lost; capacity can briefly reach up to **2x** `max_in_flight` while old holders finish | unaffected |
| Cache unavailable | the request is refused **before** any reservation or provider call (fails closed) | same | same | unaffected |
| Concurrent loss during a race | a few extra requests inside the budget may pass the rate limit | one extra probe is possible | brief overshoot of capacity | **budget still exact** |

These controls limit **capacity and request rate, not money.** Money is limited by the durable ledger and the anchor, which never read the cache. Launch blockers that follow from this: do not use
`cache:clear` or restart the cache while live; Redis and Memcached were **not** tested and no claim is made for them.

### 19.10 Tested guarantees versus operational assumptions

**Tested here** (a disposable in-memory and file SQLite database and a disposable MariaDB 11.4.13; no real HTTP, no real key, no production data):
the ordering rules; every comparison rule; latching on a mismatch; rollback, truncation, older-backup, point-in-time and in-flight-restore detection against a *file* anchor in a *separate directory*; kill-switch
generation and fail-closed behaviour; reconcile never lowers a charge and never releases; concurrent reservation and settlement from 5 to 8 separate PHP processes sharing one anchor; the migration (fresh, rollback, re-apply, data kept, InnoDB, unique index).

**Operational assumptions, NOT proven by any test:**
* that the configured anchor location really is independent of the database's restore domain (an attestation by a person);
* that it has its own backup lifecycle and that nobody restores it together with the database;
* that the HMAC key is stored somewhere a database restore cannot reach, and is rotated by a process that exists;
* that a lost anchor is recovered only through the 19.6 procedure, with real usage evidence;
* that the provider-side cap exists;
* that a filesystem anchor's fsync, rename and lock semantics hold on the production volume (a network filesystem may not provide them; verify before relying on it);
* MySQL, Redis and Memcached behaviour (untested).

### 19.11 Acceptance

M5.7.2 is **not fully accepted.** The code, migration, commands, preflight checks and tests are complete; the milestone is **blocked** on 19.4 (independent anchor infrastructure and its attestation) and on
the provider-side cap confirmation. Neither is something this repository can supply.

---

## Testing summary

| Suite | What it proves |
|---|---|
| `tests/Feature/Ai/StrictBudgetTest` | reservation bounds, settlement rules, rollback, counter loss, idempotency |
| `tests/Feature/Ai/OpenAiProviderTest`, `CustomerAssistant/OpenAiCustomerFlowTest` | adapter request/response mapping, error and transport classification, retry restrictions, every gate, no real HTTP |
| `tests/Feature/Ai/ExternalProcessingGateTest`, `PrivacyScrubbingTest` | the approval gate, safe defaults, masking and its non-masking |
| `tests/Feature/Ai/CacheEngineCheckTest`, `MariaDbEngineTest` (opt-in) | cache verdicts; real InnoDB / MyISAM / missing-table verdicts; read-only; statement time limit |
| `tests/Feature/Ai/TrustedProxiesTest` | direct, trusted, untrusted, spoofed, invalid configuration, IPv6 `/64`, limiter key consistency |
| `tests/Feature/Ai/PreflightTest` | every gate individually, combined readiness, no secrets, read-only, exit codes |
| `tests/Feature/Ai/CustomerAssistant/CatalogueCategoryFilterTest` | >400 rows, every category state, visibility, reseller path, completeness (SQLite and MariaDB) |
| `tests/Feature/Ai/CustomerAssistant/TurnDeadlineAndSlotTest` | deadlines, nested timeouts, one slot per turn |
| `tests/Feature/Ai/MultiProcess/AiMultiProcessTest` (opt-in) | 8 real PHP processes on MariaDB 11.4.13 + database cache: hard cost/request caps, unknown usage, counter loss, turn slot cap, circuit probe |

| `tests/Feature/Ai/DurableBudgetTest` | cache clear never gives budget back (global/surface × requests/tokens/cost), held reservations, mid-call clear, integrity flag, idempotent reserve/settle, unknown-outcome matrix, retries, fail-closed DB faults, no `Cache::` in the budget code, no text in the ledger |
| `tests/Feature/Ai/DurableKillSwitchTest` | ships engaged, every surface, cache clear / restart cannot release it, unreadable state fails closed, command failure exit code, shutdown between reserve and send and during a retry, no side effects |
| `tests/Feature/Ai/DurableProviderGuardTest` | the real OpenAI adapter (HTTP faked) refuses without a valid open reservation or with an engaged switch; guard order; only the adapter uses HTTP |
| `tests/Feature/Ai/DurableMigrationTest` | fresh/re-applied/rolled-back migration, unique constraints, seed never overridden, other tables untouched, InnoDB (MariaDB) |
| `tests/Feature/Ai/MultiProcess/AiMultiProcessTest` (M5.7.1 additions) | cache flushed repeatedly during a race, two-surface race, counter loss, duplicate settlement by 8 processes, concurrent retries, shutdown surviving cache clears and fresh processes |

| `tests/Feature/Ai/Integrity/*` (M5.7.2) | rollback detection (scenarios 1-10 of 19.2, plus a control showing the rollback is invisible without the anchor), anchor protocol and faults, kill-switch generation and recovery, reconcile, the real adapter gated by the anchor (HTTP faked), cache controls, integrity preflight, the `anchor_seq` migration |
| `tests/Feature/Ai/MultiProcess/AnchoredMultiProcessTest` (opt-in) | 5 to 8 separate PHP processes sharing one anchor and one database: hard limits, cache flushes during a race, unreachable anchor, database failure after the anchor accepted, settlement races |

Opt-in suites run with `DB_CONNECTION=mysql … AI_MULTIPROCESS_TESTS=1 AI_MARIADB_TESTS=1 CACHE_STORE=database` against a **disposable** database (they truncate the cache tables and the durable AI tables, and create/drop a scratch table).

**Running the multi-process tests.**
- MariaDB 11.4 + database cache (the production shape): migrate the disposable database, then `CACHE_STORE=database DB_CONNECTION=mysql DB_HOST=… DB_DATABASE=… AI_MULTIPROCESS_TESTS=1 AI_MARIADB_TESTS=1 php vendor/bin/phpunit tests/Feature/Ai`.
- SQLite: a file database (fully migrated) with `AI_MULTIPROCESS_TESTS=1`; the worker uses the `file` cache (the database cache cannot be shared across processes on SQLite) and the five
  cache-exactness tests are skipped. This proves the durable ledger's concurrency, not the database cache.
