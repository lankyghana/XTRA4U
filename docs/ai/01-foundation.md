# M5.2 — AI Engine Foundation

Status: implemented. Builds on `docs/ai/00-audit-and-architecture-plan.md` (unchanged).

**What this is:** a provider-agnostic, safe-by-default engine core. **What it is not:** an assistant. There is no chat UI, no route, no tool, no business-data access, no conversation storage and no real provider adapter. Everything is OFF until configured, and nothing in the marketplace calls it.

---

## 1. Structure

```
config/ai.php                               all settings (safe defaults, no secrets)
app/Providers/AiServiceProvider.php         registers the shared offline mock only (added to bootstrap/providers.php)
app/Console/Commands/AiKillSwitch.php       php artisan ai:kill-switch engage|release|status
app/Services/Ai/
  AiOrchestrator.php                        the ONE entry point: run(AiRequest): AiResult
  Contracts/AiProvider.php                  provider interface
  Providers/MockAiProvider.php              offline, scriptable, no I/O
  Providers/AiProviderManager.php           resolves ai.provider ('mock'; 'openai' fails closed - not implemented)
  Data/                                     AiRequest, AiMessage, AiRole, AiSurface, AiResponse, AiUsage, AiToolCall, AiResult
  Exceptions/                               AiException + 9 typed subclasses, each with a safe userMessage()
  Safety/                                   KillSwitch, AiRateLimiter, ConcurrencyGuard, CircuitBreaker, CircuitPermit
  Usage/                                    UsageMeter, BudgetGuard, BudgetReservation, PriceTable, TokenEstimator
  Logging/                                  AiLogger, Redactor
tests/Feature/Ai/                           87 new tests (+ Support/FakeLiveProvider)
```

No migrations. No routes. No views. No middleware. No listeners. No queue jobs. No new dependencies.

## 2. Request lifecycle

`AiOrchestrator::run()` is synchronous and bounded. Gates run cheapest-first; a request that fails one never reaches the next, and **never reaches the provider**:

| # | Gate | Refusal |
|---|---|---|
| 1 | engine + surface switch (config AND runtime kill switch) | `AiDisabledException` (`ai_disabled`) |
| 2 | provider resolved and `assertConfigured()` (no network) | `AiConfigurationException` (`ai_misconfigured`) |
| 3 | request validation + clamping (size, context length, output tokens, timeout) | `AiInvalidRequestException` |
| 4 | per-principal, per-surface rate limit | `AiRateLimitedException` (`ai_rate_limited`, `retryAfterSeconds`) |
| 5 | circuit breaker | `AiCircuitOpenException` (`ai_unavailable`) |
| 6 | shared concurrency slot | `AiBusyException` (`ai_busy`) |
| 7 | hard budget reservation | `AiBudgetExceededException` (`ai_budget_exhausted`) / `AiConfigurationException` |
| 8 | provider call: bounded timeout, bounded retries, turn deadline | `AiProviderException` |

Every failure is an `AiException` with a stable `errorCode()` and a generic, safe `userMessage()` (never mentions providers, limits or internals). An unexpected non-AI exception anywhere fails **closed** as `AiConfigurationException`, carrying only the exception *class* name.

## 3. Provider abstraction

`Contracts\AiProvider`: `name()`, `isLive()`, `assertConfigured(?string $model)`, `send(AiRequest): AiResponse`.

* `send()` makes **one attempt**, honours `$request->timeoutSeconds`, never sleeps or retries, and throws `AiProviderException` built from **sanitised** information only (kind + short text — never headers, credentials, or raw bodies).
* `AiProviderException` kinds: `timeout`, `unavailable`, `rate_limited`, `authentication`, `bad_request`, `invalid_response`. `isRetryable()` = timeout/unavailable only. `isTransient()` (counts toward the circuit breaker) = timeout/unavailable/rate_limited/invalid_response; bad-request and authentication errors prove the provider answered and do not.
* `AiRequest` carries surface, an opaque server-derived `principalKey`, messages, optional model/limits and provider-neutral tool schemas. `AiResponse` is normalised (text, tool calls, `AiUsage` — null when the provider reports nothing, never guessed — finish reason, provider request id).
* **Tool calls are carried, never executed.** There is no tool system until M5.3.
* **`MockAiProvider`**: offline; scriptable (`respondWith`, `failWith`, `timeoutOnce`, `queue`); records calls; refuses to run in production unless `AI_MOCK_IN_PRODUCTION=true`. It is the default provider, so local development and tests need no key.
* **OpenAI is intentionally not implemented.** Selecting `AI_PROVIDER=openai` fails closed with a configuration error and makes no request. The endpoint, request format and model must first be verified against OpenAI's official documentation (M5.1 §7/§10); that adapter is the first deliverable that needs a decision on credentials and terms (D2/D3). No SDK was installed and Laravel was not upgraded.

## 4. Configuration (`config/ai.php`)

| Key | Default | Meaning |
|---|---|---|
| `enabled` (`AI_ENABLED`) | `false` | Global switch (config half). |
| `provider` (`AI_PROVIDER`) | `mock` | `mock`, or `openai` (not implemented → fails closed). |
| `default_model` (`AI_DEFAULT_MODEL`) | `null` | Placeholder. No model name is shipped; a live provider refuses to run while null. |
| `providers.mock.allowed_in_production` | `false` | The mock may never answer real users silently. |
| `providers.openai.api_key` / `base_url` | env, `null` | Read only by the future adapter; never logged or echoed. |
| `surfaces.{customer,vendor,developer}.enabled` | `false` | Per-surface release switches. |
| `request.timeout_seconds` / `max_timeout_seconds` | 20 / 30 | Per attempt; caller values are clamped. |
| `request.deadline_seconds` | 40 | Whole turn; no attempt may outlive it. |
| `request.max_retries` | 1 (hard cap 2) | Timeout/unavailable only; 0 disables. |
| `request.max_request_bytes` / `max_context_messages` / `max_output_tokens` | 16000 / 20 / 512 | Size, context window, completion allowance. |
| `rate_limits.<surface>.per_minute` | 6 / 20 / 20 | Per principal. Missing/invalid → configuration error. |
| `concurrency.max_in_flight` / `lock_seconds` | 2 / 45 | Shared slots (see §7). |
| `limits.global` / `limits.surfaces.<s>` | all `null` | `daily_requests`, `daily_tokens`, `daily_cost_micro_usd`. `null` = not configured, `0` = block. Env: `AI_GLOBAL_*`, `AI_CUSTOMER_*`, `AI_VENDOR_*`, `AI_DEVELOPER_*`. |
| `pricing` | `[]` | USD per million tokens per provider/model. None shipped. |
| `circuit_breaker` | 5 / 120 s / 60 s | threshold / window / cooldown. |
| `logging.channel` (`AI_LOG_CHANNEL`) | `null` | Log channel (null = default). |

`.env.example` has only commented placeholders. `.env` was never opened.

## 5. Kill switch

Both halves must allow a request: `config('ai.enabled') === true` **and** no runtime flag. The runtime flag is a shared-cache entry: `php artisan ai:kill-switch engage` refuses everything immediately (no redeploy, no config re-cache); `release` removes it (the engine still needs `AI_ENABLED=true`); `status` reports. It **fails closed**: if the cache cannot be read the engine is treated as switched off. A disabled engine does not resolve a provider or touch the network (tested by selecting the unimplemented `openai` provider and still getting `ai_disabled`).

## 6. Usage accounting and budgets

* **Counters** (shared cache, daily, keys `ai:usage:{YYYYMMDD}:{scope}:{metric}`; scopes `global` and `surface.<name>`; metrics `requests`, `tokens`, `cost_micro_usd`, `successes`, `failures`; 3-day TTL). `UsageMeter::snapshot()` is the foundation for later admin reporting. **Counts only** — no prompts, responses, identities or message text are stored anywhere.
* **Per-request metadata** (provider, model, surface, request id, tokens, duration, outcome, attempts, cost) goes to the redacted structured log (§9), not to a table. **No conversation/message/usage tables exist**; retention for conversation content has not been approved.
* **Hard budgets are enforced before the provider call.** `BudgetGuard::reserve()` atomically increments requests and a pessimistic token estimate, compares to the limit, and rolls every increment back (also when the cache fails part-way) if any limit would be exceeded; so two processes racing for the last tokens cannot both win.
* **Settlement rules (M5.2 safety verification).** Budget is released only for tokens that *provably* were not processed:

  | Case | Tokens | Requests |
  |---|---|---|
  | Refused before the provider (disabled, misconfigured, rate limit, circuit, busy, budget) | nothing reserved / fully rolled back | not counted |
  | Success, usage known | estimate replaced by actual (lower releases only the unused part; higher is recorded in full) | 1 |
  | Success, usage unknown | estimate **stands**; with pricing, a conservative cost (dearer rate × estimate) is charged to the cost budget (the caller still sees `null` cost) | 1 |
  | Timeout / unavailable after sending / invalid response / foreign adapter exception | estimate **stays charged** (+ conservative cost) | 1 |
  | Rate-limited / authentication / bad request, or `AiProviderException::unavailable(requestSent: false)` | released | 1 |
  | Retry | a retry is a new provider request: it reserves again through the same atomic check; the earlier ambiguous attempt stays charged; if the budget refuses, no retry is sent and the original error is returned | +1 per attempt |
  | Bookkeeping fails after a good answer (settle, breaker) | never refunded; breaker errors are swallowed, a failed settlement fails closed with the estimate left charged | 1 |

  Settlement and failure are at-most-once per `BudgetReservation` (`close()` flips before any counter is touched). Releasing a counter that no longer exists (expired/flushed) creates nothing, so a counter can never go negative and hand out headroom. A counter that cannot be incremented is an error, never "0 used". Residual: the estimate is `bytes/3 + max_output`; if a provider's real usage exceeds it, the real figure is recorded but that request has already been admitted (overshoot bounded by the estimate error).
* **Missing budgets are never silently unlimited for something that spends money:** a *live* provider requires a configured daily **token** limit at both the global scope and the request's surface scope, otherwise the request is refused as `ai_misconfigured` before any reservation. The offline mock may run without limits (still counted). Non-integer or negative limit values are configuration errors.
* **Cost** = tokens × configured price (USD per million tokens == micro-USD per token), integer micro-USD, **only when pricing is configured and usage is reported**; otherwise `null`. Pricing is never guessed. The cost limit is checked against cost already recorded, so the last in-flight request(s) can overshoot it slightly; request and token limits cannot overshoot. Costs are labelled estimates.

## 7. Rate limiting, concurrency, circuit breaker (all on the shared cache)

None of these use PHP process-local state, Redis, or a queue worker (production queue is cron-driven).

* **Rate limit**: one atomic `Cache::increment` on a clock-aligned one-minute window key per `(surface, sha256(principalKey), window)`; the returned value decides admission. Laravel's `RateLimiter` is **not** used: its `attempt()` is check-then-hit and its `hit()` has a reset-to-1 fallback; on the database cache store with 8 simultaneous processes the original code admitted 13 requests against a limit of 5 (multi-process test, §11). Trade-off: a fixed window lets up to 2× the allowance through across a window boundary. The principal hash, not the identifier, appears in cache keys.
* **Concurrency**: `ConcurrencyGuard` takes one of N named cache locks (`ai:slot:0..N-1`) for the duration of the call and releases it in `finally`. Locks have a TTL (≥ the turn deadline + 5 s), so a crashed process cannot hold a slot forever. When all slots are taken the request is refused immediately (`ai_busy`) — it never queues, because a queued synchronous request would hold a PHP worker. This is what protects checkout from AI traffic.
* **Timeouts and retries**: provider timeout ≤ `max_timeout_seconds` and ≤ the time left in the turn; at most 1 + min(`max_retries`, 2) attempts; only timeouts/unavailable are retried; a retry starts only if a full attempt still fits inside the deadline; no sleeping.
* **Circuit breaker** (per provider): `failure_threshold` transient failures within `window_seconds` open the circuit for `cooldown_seconds`, during which requests are refused without touching the provider (and without consuming budget). After the cooldown exactly **one** probe (lock-guarded) is let through: success closes the circuit, failure re-opens it. Settings are validated at admission so a misconfigured breaker fails closed before the provider.

## 8. Failure behaviour

Everything fails closed with a safe `AiException`: disabled, unknown/unimplemented provider, missing/invalid settings, cache outage, provider timeout/error, foreign exceptions from an adapter (replaced by `AiProviderException::unexpected()`), logging failure (swallowed — it can never break or reveal anything about a request). The marketplace is never affected: nothing in checkout, payments, webhooks, fulfillment or the queue calls the engine.

## 9. Logging and privacy

* `AiLogger` has typed methods only (`completed`, `rejected`, `failed`); **no parameter can carry a prompt, response, message, tool argument, header or credential.** A reflection test enforces this.
* Fields: request id, surface, a 12-char one-way hash of the principal key, provider, model, outcome, error class/code, redacted error detail, token counts, duration, attempts, cost. No stack traces, no chained exceptions.
* `Redactor` is a second layer applied to every string and context: masks bearer/basic credentials, `Authorization` lines, `key=value` secrets, `sk-…`, `xt_live_/xt_test_…`, `whsec_…`, emails, Ghana-style phone numbers (local, spaced, `+233`, `233`), long digit runs (payment references/cards), and long opaque tokens; drops context keys such as `prompt`, `message(s)`, `content`, `response`, `text`, `authorization`, `token`, `api_key`, `phone`, `reference`, `arguments`, `payload` (token *counts* are exempt).
* `AiException` redacts its own message and **never chains a previous exception** (an HTTP client exception can carry request headers including the API key).

## 10. API scope handling (`ai:chat`)

`ai:chat` stays declared in `config/developer_api.php` but is listed in the new `unavailable_scopes`. `ApiKeyService::availableScopes()` (declared minus unavailable) is now the single source for the portal's key form, `StoreApiKeyRequest`, and `ApiKeyService::validateScopes()`, so **no new key can receive it** (portal or service). **Existing keys are untouched**: not revoked, not rewritten, still authenticating (no route requires the scope, so it is inert).

Behaviour to know about (found by inspecting the code, not in the M5.1 report): **key rotation creates a new key row from the old key's scopes**, so it would otherwise have re-granted the scope or failed validation. Rotation drops **only** the scopes listed in `unavailable_scopes` (unknown scopes still fail validation, as before); the old key keeps its stored scopes until revoked. A key whose *only* scope was `ai:chat` is refused with `ROTATION_NOT_POSSIBLE` before anything is touched: the original key is not revoked or rewritten, no replacement is issued, and the message says *"the original key has not been changed and remains active … revoke the original explicitly if you want to retire it"*. The portal (`ApiKeyController::rotate`) appends that same sentence to **any** refused rotation. A failed rotation is one rolled-back transaction, so it never silently revokes. When the AI API ships, remove the entry from `unavailable_scopes` in the same change.

`tests/Feature/DeveloperApi/DeveloperApiTestCase::makeKey()` defaulted to "every declared scope"; it now defaults to every *grantable* scope (the helper's stated intent), which is the only existing-test change.

## 11. Testing

`tests/Feature/Ai/` (offline; `Http::fake()` + `Http::assertNothingSent()` in every AI test's tearDown):

| File | Covers |
|---|---|
| `ProviderAbstractionTest` | mock success/normalisation, scripted order, limit clamping, provider errors, retry/transient classification, tool calls carried not run, mock refused in production, request validation/size limits |
| `ConfigurationAndKillSwitchTest` | shipped defaults are off/null, disabled ⇒ no provider/no network, surface off, runtime switch + fail-closed + command, unknown/`openai` provider, missing settings, no tables, no public AI routes |
| `UsageAndBudgetTest` | usage from reported tokens, unknown usage, cost only from pricing, global/per-surface/zero/cost limits, rollback on refusal, failure accounting, live-provider budget/model requirements, garbage limits |
| `ResilienceTest` | concurrency slots (full, in-flight, release), rate limits, circuit open/threshold/reset/cooldown/single probe/failed probe, timeouts, retries and caps, deadline, foreign exceptions, fail-closed engine errors |
| `LoggingAndRedactionTest` | metadata-only logs for success/failure/rejection, no prompt/response/phone/key/principal in logs, redactor data set, logger signature guard |
| `AiChatScopeTest` | scope declared-not-grantable, portal form, create rejected, service rejected, other scopes OK, existing key unchanged and working, rotation behaviour |

Two safety controls were mutation-checked (live-budget requirement disabled → 2 tests fail; breaker threshold unreachable → 3 tests fail), then restored.

Run: `php artisan test tests/Feature/Ai` (full suite: `php -d memory_limit=1536M vendor/bin/phpunit --no-progress`; `php artisan test` still hits the known 128 MB single-process limit). 

### Verified results (2026-11-18)

| Run | Result |
|---|---|
| `tests/Feature/Ai` on SQLite (in-memory, array cache) | 87 passed, 337 assertions |
| Full suite on SQLite, single process (`php -d memory_limit=1536M vendor/bin/phpunit --no-progress`) | 2111 tests, 14702 assertions: **1 error + 5 failures + 8 skipped — exactly the recorded baseline** (`MigrationTest`, `ExampleTest`, 2× `SupportAttachmentsTest`, 2× `VendorAfaFulfillmentOwnershipTest`); no new failures |
| Engine tests (79, the non-DB AI classes) on **MySQL 8.4.8** with `CACHE_STORE=database` | OK, 307 assertions |
| `AiChatScopeTest` + all of `tests/Feature/DeveloperApi` on **MySQL 8.4.8** | 503 tests OK, 1 skipped (SQLite-only) |
| Engine tests (79) on **MariaDB 11.4.13** with `CACHE_STORE=database` | OK, 307 assertions |
| `AiChatScopeTest` + all of `tests/Feature/DeveloperApi` on **MariaDB 11.4.13** | 503 tests OK, 1 skipped; all 79 tables InnoDB after a full migrate |

The two server runs used disposable, freshly initialised, localhost-only servers (ports 3307/3308) with a scratch database; they were stopped afterwards. No migration was added, so "database compatibility" here means: all 121 existing migrations apply on both engines, and the engine's counters, rate-limit keys and lock slots work on the **database cache store** (the production default) — verified by inspecting the `cache` table (6 `ai:usage:*` keys after one request, `cache_locks` empty afterwards; the rate-limit key contains a SHA-256 of the principal, never the raw identifier). The cache lock/increment behaviour is therefore proven on MySQL and MariaDB, but **not under real multi-process contention** (the tests are single-process; the "second request while one is in flight" cases are simulated by re-entering the engine from inside the mock provider).

### Safety verification addendum (2026-11-20)

| Run | Result |
|---|---|
| `tests/Feature/Ai` on SQLite (array cache) | 125 tests, 502 assertions, 6 skipped (the opt-in multi-process tests) |
| Full suite on SQLite, single process | 2149 tests, 14867 assertions: 1 error + 5 failures + 14 skipped (8 baseline + 6 opt-in) — the same six recorded failures, no new ones |
| `tests/Feature/Ai` + `tests/Feature/DeveloperApi`, `CACHE_STORE=database`, incl. the 6 multi-process tests, **MariaDB 11.4.13** | 620 tests, 3716 assertions, OK (1 skipped, SQLite-only) |
| Same, **MySQL 8.4.8** | 620 tests, 3761 assertions, OK (1 skipped) |
| Multi-process tests alone, 3 consecutive runs on each engine | all green |
| Mutation checks (each reverted) | original rate limiter → 13 admitted vs 5; concurrency guard max+1 → 2 vs 1; budget comparison disabled → 3 tests fail; failures-always-release → 9 fail; non-idempotent close → 1 fails; unmetered retry → 5 fail |

* New tests: `BudgetLifecycleTest` (settlement matrix, retries, partial reservations, idempotency, bookkeeping failures), extra rotation tests in `AiChatScopeTest`, and the opt-in `tests/Feature/Ai/MultiProcess/AiMultiProcessTest` (+ `worker.php`).
* **Multi-process method:** N independent `php worker.php` processes boot against one MySQL/MariaDB database with `CACHE_STORE=database` (set in the child's environment so boot-time singletons bind to it, as in production), announce "ready", and are released together by a "go" file. Scenarios: in-flight limit (1 of 6, 3 of 8), global request budget (8×4 attempts vs 5), global token budget, surface token budget with a plentiful global budget (partial reservations rolled back), rate limit (8×3 vs 5), exactly one half-open probe. Workers assert no HTTP was sent. Run: `AI_MULTIPROCESS_TESTS=1 DB_CONNECTION=mysql … php vendor/bin/phpunit tests/Feature/Ai/MultiProcess` (skipped otherwise; truncates `cache`/`cache_locks` of the target DB).
* **Defects found and fixed:** timeouts/5xx/garbage replies refunded the whole reservation; a failure after a good answer refunded it; settle/fail were not idempotent; a cache error mid-reservation leaked partial counters; retries were unmetered; the rate limiter admitted more than its limit under real contention; the half-open probe could be taken after the circuit had already closed; rotation refusals did not say the original key stays active.
* **Not verifiable here:** production's `cache` / `cache_locks` tables must be InnoDB (row locks are what make the increments and locks atomic; production once had a MyISAM `orders` table — check `information_schema.TABLES`). The tests ran on freshly created InnoDB schemas.

## 12. Intentionally deferred

OpenAI adapter (needs verified endpoint/model/terms and D2/D3) · tool system (M5.3) · any business tool, order lookup, verification, checkout handoff, purchase, payment, wallet, commission, fulfillment, PIN, inventory, vendor-admin or API-key tool · conversation/message/usage tables and `ai:prune` (retention not approved) · all three assistants, UI, routes, widget · streaming · admin settings screen for key/model/caps · admin cost reports · per-vendor/tier quotas · the AI public API / `ai:chat` endpoint · hardening of the existing order-tracking endpoints (separate ticket, D12).

## 13. Differences from the M5.1 plan

* Usage counters are cache-only (no `ai_runs` table) because conversation/usage persistence is not yet approved; the M5.1 `ai_*` tables are untouched.
* The "admin DB setting" for the kill switch is a shared-cache flag plus an Artisan command, since there is no admin settings page for AI yet.
* Per-surface `enabled` flags were added (M5.1 §19 promised independent surface flags).
* Budget gate order: concurrency slot is taken before budget reservation so "busy" refusals consume no budget (M5.1 listed budgets/limits generally).
* Key rotation handling (§10) was not anticipated in M5.1.
