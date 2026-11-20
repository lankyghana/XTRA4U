# M5.3 — AI Tool System

Status: implemented. Builds on `docs/ai/00-audit-and-architecture-plan.md` (unchanged) and `docs/ai/01-foundation.md`.

**What this is:** the provider-independent, read-only, surface-aware machinery to *define, discover, validate, authorize and execute* AI tools. **What it is not:** an assistant. No business tool exists, no tool is allow-listed on any surface, no route/UI/conversation storage exists, no provider adapter exists, and nothing in the marketplace calls it. With the shipped configuration every tool call is answered `unknown_tool`.

---

## 1. Structure

```
app/Services/Ai/Tools/
  Contracts/AiTool.php            the one tool contract (read-only by contract)
  Schema/ToolParameter.php        one scalar parameter (string|integer|number|boolean|enum) + bounds
  Schema/ToolParameterType.php
  Schema/ToolSchema.php           closed parameter list; strict validation; provider-neutral JSON schema
  ToolArguments.php               validated arguments (all a tool ever sees of the model's input)
  ToolContext.php                 immutable, server-built identity/ownership/authorization carrier
  ToolActorType.php               Vendor | Guest
  ToolContextVerifier.php         re-verifies a context against the database on EVERY call
  ToolRegistry.php                registration, duplicate rejection, per-surface availability, sealing
  ToolExecutor.php                the only door from model output to application code
  ToolTurn.php                    per-turn call/time/call-id bookkeeping
  ToolLimits.php                  validated limits + prohibited-argument matcher (from config)
  ResultSanitizer.php             plain-data, redaction and size enforcement on results
  ToolResult.php, ToolResultStatus.php   ok | rejected | failed, with fixed generic messages
  ReadOnlyGuard.php               runtime "SELECT-only" guard around tool execution
  ToolLogger.php                  typed, redacted logging
  Exceptions/                     AiToolRegistrationException, AiToolContextException (AiException subclasses),
                                  ToolInputRejected, ToolWriteBlockedException (internal)
config/ai.php                     new `tools` block (allowlist [empty], limits, prohibited names)
app/Providers/AiServiceProvider   registers ToolRegistry + ReadOnlyGuard singletons; seals the registry in boot()
tests/Feature/Ai/Tools/           107 tests (+ Support/FakeTool, test-only)
```

No migrations, routes, views, controllers, middleware, listeners or jobs. No dependency was added; Laravel was not upgraded. **No M5.2 file's behaviour changed** (`AiToolCall`, `AiRequest::$tools`, the orchestrator, budgets, kill switch, limiter, breaker and logger are untouched).

## 2. The contract (`AiTool`)

```php
name(): string                       // ^[a-z][a-z0-9_]{2,63}$   (underscores, not dots: valid on every provider)
description(): string                // 1-300 chars
surfaces(): list<AiSurface>          // where the tool is DESIGNED to be used
schema(): ToolSchema                 // closed list of business parameters
authorize(ToolContext): bool         // extra check, runs before EVERY execution
handle(ToolArguments, ToolContext): array   // do the (read-only) work, return plain data
```

Nothing refers to OpenAI or any SDK. Adapters translate `ToolRegistry::definitionsFor($surface)` (`[{name, description, parameters: <JSON schema>}]`, `additionalProperties:false`) into their wire format. There is **one** contract and **no write-capable variant**; a test asserts this.

Deviation from the M5.1 plan: names use underscores (`catalog_search_offers`), not dots, because several providers reject dots in function names.

## 3. Registry

* `register(AiTool $tool)` takes **objects**, never class names (a string is a `TypeError`). Validates name, description, ≥1 valid surface, no prohibited parameter names, schema size ≤ 4 KB. **Duplicates throw**, even from a different object.
* `seal()` closes the registry for the life of the process. `AiServiceProvider::boot()` registers (currently nothing) and then seals. Anything else that tries to register afterwards — a request handler, a job, a database-driven loader — gets `AiToolRegistrationException`. There is no code path that registers from config, a database row or model output.
* Availability on a surface needs **both**: the tool lists the surface in `surfaces()` **and** its name is in `config('ai.tools.allowlist.<surface>')`. The allowlist ships **empty** for all three surfaces; a malformed allowlist (not an array) means *nothing* is available.
* `get($name)` throws for unknown tools; `resolve($name, $surface)` returns the tool or `null` (used on every execution); `forSurface()` / `definitionsFor()` list only what is available on that surface.

## 4. Trusted execution context (`ToolContext`)

The model never supplies, sees or influences identity. A context is built by **trusted server code** from an authenticated actor:

| Factory | Surface | Input | Result |
|---|---|---|---|
| `ToolContext::forVendor(Vendor $v)` | vendor | the vendor from guard `vendor` (behind `vendor.approved`) | actor=vendor, `actorId = vendorId = $v->id`, cap `vendor.approved` |
| `ToolContext::forDeveloper(Vendor $v)` | developer | the portal-authenticated vendor (Developer API is vendor-owned) | as above + cap `developer.portal` |
| `ToolContext::forGuest(string $serverSessionId)` | customer | the **server-side** session id | actor=guest, `guestKey = sha256(sessionId)` (only the hash is kept) |

* The constructor is **private**, the class is `final` and `readonly`, and `__unserialize()` throws. There is no route from client data (JSON, session blob, queue payload) to a context. `ToolExecutor::execute()` is typed `ToolContext`; arrays/objects/strings are a `TypeError`.
* Factories refuse an unsaved, forged or not-approved vendor (`AiToolContextException`, code `ai_tool_context_invalid`).
* **Customers are anonymous visitors** in this platform, so there is no customer id: the owner of guest-scoped data is the session hash.
* Even a well-formed context is **re-verified on every call** (`ToolContextVerifier`): internal consistency (surface ⇔ actor ⇔ ids ⇔ capabilities) **and** a database read that the vendor still exists and is still `is_approved`. A vendor suspended between two calls of one turn loses tool access on the next call (`invalid_context`).

## 5. Argument validation (`ToolSchema::validate`)

Model arguments are untrusted. In order, any failure stops processing and returns a rejection with *parameter names and problem codes only, never the submitted values*:

1. **Size/complexity:** ≤ `max_arguments` keys and ≤ `max_argument_bytes` of JSON → else `arguments_too_large`. A list instead of an object → `invalid_arguments`.
2. **Prohibited names** → `prohibited_argument`. Names are compared after stripping non-alphanumerics and lower-casing, against `ai.tools.prohibited_argument_names` (user/vendor/customer/actor/account/tenant/owner/application/session/guest/admin ids, `role`, `permissions`, `scope(s)`, `is_admin`, `is_approved`, `authenticated`, `surface`, `context`, `impersonate`, …). Any name that merely *contains* one of the `…id` identity tokens is also refused (`target_vendor_id`, `forVendorId`). The same list is applied at registration, so a tool cannot even declare such a parameter.
3. **Unexpected names** → `invalid_arguments`/`unexpected` (never silently ignored).
4. **Required** (null counts as absent; optional nulls are dropped).
5. **Exact type, no coercion:** `"5"` is not an integer, `1` is not a boolean, arrays/objects are `unsupported_value` (no nested structures exist).
6. **Laravel validation rules** generated from the declaration (`min`/`max`/`in`/`regex`/`boolean`…) via `Validator::make`.

Parameter types are `string` (explicit max length ≤ 1000, optional min/pattern), `integer`/`number` (explicit min **and** max), `boolean`, `enum` (≤ 50 short values). There is deliberately **no type** for SQL, class names, model names, paths, URLs or expressions.

## 6. Execution pipeline (`ToolExecutor::execute`)

Every call, every time; nothing after a failed step runs; a failed tool is **never retried** by the executor.

```
0   turn limits     calls per turn (rejected calls count), tool-time budget
0b  kill switch     M5.2 engine + surface switch (config AND runtime flag) → tools_disabled
1   call shape      id ^[A-Za-z0-9_-]{1,64}$, name pattern, id not reused in the turn
2-3 tool + surface  registry->resolve(name, context->surface): exists, declared, allow-listed → else unknown_tool
4   arguments       §5
5   context         ToolContextVerifier (consistency + vendor still approved)
6   authorization   tool->authorize(context); false, non-true or an exception ⇒ not_authorized
7   execute         handle() inside ReadOnlyGuard, timed
8   normalise       ResultSanitizer; every failure becomes a generic code
```

"Unknown" and "not available on this surface" are indistinguishable to the model (`unknown_tool`), so it cannot probe which tools exist elsewhere. **Listing a tool in a request does not make it executable**: step 3 re-evaluates the registry and allowlist at call time (tested by revoking the allowlist after definitions were issued).

## 7. Results

`ToolResult` is `ok`, `rejected` (not executed) or `failed` (executed, no usable result). Model-facing JSON:

```json
{"tool_call_id":"call_1","tool":"…","status":"ok","untrusted":true,"data":{…},"truncated":false}
{"tool_call_id":"call_1","tool":"…","status":"rejected","untrusted":true,"error":{"code":"…","message":"<fixed sentence>","problems":[{"parameter":"…","problem":"…"}]}}
```

* Messages come from a fixed table. **Exception messages, SQL errors, class names, paths and stack traces never reach the model** (nor the logs: only the exception *class*).
* The call id and tool name are echoed only if well-formed; hostile names appear as `unknown` / `(invalid name)`.
* `toAiMessage()` yields an `AiMessage` with role `tool` for a future provider round-trip.
* Codes: `unknown_tool`, `invalid_call`, `duplicate_call`, `invalid_arguments`, `prohibited_argument`, `arguments_too_large`, `invalid_context`, `not_authorized`, `tools_disabled`, `too_many_tool_calls`, `turn_time_exceeded` (rejected); `tool_error`, `tool_timeout`, `read_only_violation`, `invalid_result`, `result_too_large` (failed).

## 8. Resource limits (`config('ai.tools.limits')`, all validated; invalid ⇒ fail closed)

| Key | Default | Meaning |
|---|---|---|
| `max_calls_per_turn` | 4 | Calls honoured per turn; **rejected calls count too** so invalid-call loops stop. The turn counter (`ToolExecutor::beginTurn()`) is created by trusted code; the model cannot reset it. |
| `max_arguments` / `max_argument_bytes` / `max_string_length` | 8 / 2048 / 500 | Argument count, JSON size, per-string ceiling |
| `max_result_bytes` / `max_result_items` / `max_result_depth` | 8192 / 50 / 5 | Over any of these the result is **refused** (`result_too_large`), never silently cut — a truncated list would look complete |
| `max_result_string_length` | 1000 | Longer strings are truncated and the result is flagged `truncated:true` |
| `max_execution_seconds` | 5 | Per call. **Enforced after the fact**: PHP cannot interrupt synchronous code, so a late result is discarded (`tool_timeout`). Real bounds on slow work are indexed, limited queries |
| `max_turn_seconds` | 15 | Total tool time per turn; once spent, later calls are `turn_time_exceeded` |

There is no agent loop here: the executor runs the calls it is given and returns. How many provider round-trips a turn may take is for the assistant milestone to bound (M5.1 planned ≤ 4), through the existing M5.2 orchestrator gates, which are unchanged.

## 9. Read-only enforcement

* **Contract:** one interface, no write variant.
* **Runtime (`ReadOnlyGuard`):** while `handle()` runs, every statement on the default DB connection must be a plain read (`SELECT`/`SHOW`/`EXPLAIN`/`DESCRIBE`, or a `WITH` with no write keyword). `INSERT/UPDATE/DELETE/REPLACE`, DDL, `FOR UPDATE`, `INTO OUTFILE`, `PRAGMA`, savepoints and stacked statements throw before reaching the database → `read_only_violation`. It does nothing outside `run()`. Note that cache/session/queue writes through the database connection are blocked too (intended). It cannot see file, HTTP or other-connection writes.
* **Architecture test (`ToolArchitectureTest`, token-based, ignores comments):** nothing under `App\Services\Ai\Tools` may reference payment/gateway/wallet/commission/affiliate/fulfillment/PIN/inventory/payout/withdrawal/API-key/webhook/order/transaction/SMS/mail/notification/checkout code; may not call write-ish methods (`save`, `create`, `update`, `delete`, `insert`, `increment`, `raw`, `statement`, `dispatch`, `put`, …), file/shell/network/eval functions, or the `Http/Storage/File/Queue/Event/Bus/Process/Artisan/Schema/Redis/Session` facades; only `ReadOnlyGuard` may use `DB`, and only `DB::connection()`. Also asserted: no class under `app/` implements `AiTool`, no AI routes/migrations/controllers/views, `ai:chat` still unavailable.

## 10. Security boundaries

* **Identity** only from `ToolContext`; ids/permissions in arguments are refused, and cannot be declared by a tool.
* **Tenant isolation is the tool's job, enforced by the context**: future tools must scope every query by `$context->vendorId` / `$context->guestKey` (the house style) and add policy checks where a policy exists. The infrastructure guarantees the context is genuine and current; it cannot know what a future query selects.
* **Prompt injection:** results are labelled `untrusted`, returned as data, cannot add tools, scopes or permissions (the allowlist and context are server state), and nothing in a result is ever interpreted as an instruction. Free text that users can author (product names, notes) should still be trimmed and stripped of URLs/markup by the tool that returns it, as M5.1 §14 requires.
* **Data minimisation:** tools return allow-listed arrays. The sanitiser additionally drops credential-looking keys (`password`, `secret`, `api_key`, `key_hash`, `*_token`, `delivered_pins`, `pin`, `cvv`, …) and passes every string through `Redactor` (keys, bearer tokens, emails, Ghana phone numbers, long digit runs). It is a safety net, not a licence to return whole records.
* **Logging:** one event, `ai.tool.call`, with typed fields only — tool name (only if well-formed), surface, 12-char principal hash, status, error code, internal reason, argument *count*, result bytes, duration, exception class. No argument values, results, call ids or messages.
* **M5.2 gates apply:** the kill switch/surface switch is checked per call. Tools never touch budgets, rate limits, concurrency slots or the circuit breaker.

## 11. Adding a business tool in a later milestone

```php
final class ExampleVendorTool implements AiTool
{
    public function name(): string { return 'vendor_example'; }
    public function description(): string { return 'Returns … for the signed-in vendor. Use when …'; }
    public function surfaces(): array { return [AiSurface::Vendor]; }
    public function schema(): ToolSchema {
        return new ToolSchema(ToolParameter::integer('days', 'How many days back (1-30).', 1, 30));
    }
    public function authorize(ToolContext $c): bool { return $c->can(ToolContext::CAP_VENDOR_APPROVED); }
    public function handle(ToolArguments $a, ToolContext $c): array {
        // scope EVERY query by $c->vendorId; select only the columns you return; no writes
        return ['days' => $a->int('days', 7), /* … */];
    }
}
```

1. Register it in `AiServiceProvider::boot()`: `$registry->register($this->app->make(ExampleVendorTool::class));` (before `seal()`).
2. Add its name to `config('ai.tools.allowlist.vendor')`.
3. Add its tests: two-vendor fixtures proving no cross-tenant data, parity tests against the dashboard rules it mirrors, argument/authorization/limit tests, and a no-HTTP assertion.
4. Build the surface's `ToolContext` from the authenticated actor in the (future) controller, `$executor->beginTurn()` per assistant turn, pass `registry->definitionsFor($surface)` as `AiRequest::$tools`, run `$executor->executeTurn($response->toolCalls, $context, $turn)`, and feed `$result->toAiMessage()` back. That orchestration loop (with its own iteration cap) belongs to the assistant milestone.

## 12. Testing a tool

Extend `Tests\Feature\Ai\Tools\ToolTestCase` (engine on, offline mock, fresh unsealed registry, `RefreshDatabase`, Http asserted empty in `tearDown`). Use `$this->enable($tool, AiSurface::Vendor)` (registers *and* allow-lists), build contexts with `$this->vendorContext($vendor)` / `ToolContext::forGuest()`, and call `$this->callTool($name, $args, $context)`. `Support/FakeTool` is a test-only configurable tool (closures for `handle`/`authorize`) that records its calls. Never fake a context with anything but the factories.

## 13. Explicitly unsupported in M5.3

Any write-capable tool or execution mechanism · business tools (catalog, orders, verification, wallet, API-key, webhook, payment, fulfillment, PIN, inventory…) · nested/array/object arguments · arguments that name SQL, classes, models, paths or URLs · tools registered at runtime, from config class names, or from the database · an agent/tool loop · provider adapters or tool-call wire formats · conversation/tool-call persistence (`ai_tool_calls`) · routes, UI, admin screens · interrupting a running tool · per-tool rate limits or per-vendor quotas · the `ai:chat` API scope.

## 14. Verified results

See the final report for the run that accompanied this milestone. Mutation checks performed (each reverted): authorization skipped → 4 tests fail; read-only guard inert → 1; prohibited-name check off → 6; allowlist ignored → 5; per-turn limit off → 2; context re-verification off → 1; result redaction off → 1.
