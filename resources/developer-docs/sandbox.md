## What the sandbox is

A test key (`xt_test_...`) reaches the sandbox. The whole order flow works: catalog, quote, order, payment, status, transaction lookup and webhooks. Nothing it does has a real-world effect: no real payment, no delivery of data, no wallet or commission change, no SMS or email.

The catalog and prices come from your vendor's real products (read only), so quotes in the sandbox match what a live key would see.

## How to recognise sandbox data

- Responses carry `meta.environment` set to `sandbox`, and resources carry `environment` and `simulated`.
- Ids are prefixed: `sbx_qt_` for quotes, `sbx_ord_` for orders, `sbx_pay_` for payments, `sbx_ful_` for fulfilment, `evt_sbx_` for webhook events.
- A live id used with a test key (or the reverse) is simply `NOT_FOUND`.

## The workflow

1. `POST /quotes` returns a quote.
2. `POST /orders` with an `Idempotency-Key` creates a test order in `awaiting_payment`. You may add your own `client_reference` (test keys only).
3. `POST /orders/{order}/payments` starts a simulated payment (`payment_pending`). It never succeeds by itself.
4. `POST /sandbox/orders/{order}/simulate` chooses the outcome.

## Simulating outcomes

Both endpoints need a test key with the `sandbox:simulate` scope. A live key gets `ENVIRONMENT_MISMATCH`. A key without the scope gets `MISSING_SCOPE`.

`GET /sandbox/simulations` lists the allowed events and scenarios. `POST /sandbox/orders/{order}/simulate` takes a JSON body with exactly one of `event` or `scenario`.

| `event` | Needs | Result |
|---|---|---|
| `payment.succeeded` | payment started | payment paid |
| `payment.failed` | payment started | payment and order failed |
| `fulfillment.processing` | payment paid | order processing |
| `fulfillment.completed` | processing | order completed |
| `fulfillment.failed` | processing | order failed |

| `scenario` | Steps it runs |
|---|---|
| `success` | payment.succeeded, fulfillment.processing, fulfillment.completed |
| `payment_failed` | payment.failed |
| `fulfillment_failed` | payment.succeeded, fulfillment.processing, fulfillment.failed |

A single `event` is strict: an out-of-order step gives 409 `SIMULATION_NOT_ALLOWED`. A `scenario` skips steps that are already applied and replaying a finished scenario returns HTTP 200 with `idempotent_replay` true. You cannot set statuses or amounts directly: the fields `status`, `payment_status`, `fulfillment_status`, `amount` and `paid` are rejected.

## Webhooks in the sandbox

Webhook endpoints are created for one environment. A test endpoint receives events for test orders. The body also contains `environment` set to `sandbox` and `simulated` set to true, and there is an `X-XTRA4U-Environment` header with the value `sandbox`.

## Moving to live

Create a production key (`xt_live_`) with only the scopes you need. The URLs and request shapes are the same. A test key never reaches live data, whatever the request says.
