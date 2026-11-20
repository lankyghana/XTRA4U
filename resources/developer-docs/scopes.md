## How scopes work

Each API key carries a list of scopes chosen when the key was created. An endpoint needs one specific scope. If the key lacks it, the API answers HTTP 403 with the error code `MISSING_SCOPE`, and `error.details.required_scope` names the scope that is missing. Scopes cannot be changed on an existing key: create a new key with the scopes you need.

## Scopes you can grant today

| Scope | What it allows |
|---|---|
| `services:read` | `GET /networks` and `GET /services` |
| `products:read` | `GET /products` and `GET /products/{offer}` |
| `quotes:create` | `POST /quotes` |
| `orders:create` | `POST /orders` |
| `orders:read` | `GET /orders/{order}`, `GET /orders/{order}/status`, `GET /transactions/{reference}` |
| `payments:initiate` | `POST /orders/{order}/payments` |
| `webhooks:manage` | Declared, but no API endpoint uses it yet (see below) |
| `sandbox:simulate` | The two `/sandbox/...` endpoints. Test keys only |

## Scopes that are not usable

- `ai:chat` is declared in the platform but has no endpoint. It cannot be granted to a new key, and it is not part of the Developer API. Do not plan an integration around it.
- `webhooks:manage` can be granted, but webhooks are managed in the Developer Portal, not through the API, so no API call currently requires it.

## The sandbox:simulate scope

This scope only works on test keys (`xt_test_`). It cannot be put on a live key, and a live key is refused on the sandbox endpoints with `ENVIRONMENT_MISMATCH`. It does not allow starting a payment: that still needs `payments:initiate`. Likewise `payments:initiate` does not allow simulating outcomes.

## Choosing scopes

Grant the smallest set that your integration needs. A read-only dashboard needs only `services:read`, `products:read` and `orders:read`. A system that sells needs `products:read`, `quotes:create`, `orders:create`, `payments:initiate` and `orders:read`.

## Existing keys

A key created earlier may still carry a scope that can no longer be granted. Such a scope is simply inactive, because no endpoint requires it. It is not an error.
