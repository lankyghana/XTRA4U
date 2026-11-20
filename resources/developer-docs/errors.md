## Response envelope

Every response from `/api/v1` is JSON in one envelope.

Success:

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {},
  "meta": { "request_id": "req_..." }
}
```

Error:

```json
{
  "success": false,
  "message": "The request failed validation.",
  "error": { "code": "VALIDATION_FAILED", "details": {} },
  "meta": { "request_id": "req_..." }
}
```

Branch on `error.code`, which is stable, and not on `message`, which is for humans. `error.details` is an object that may be empty. Responses from a test key also carry `meta.environment` set to `sandbox`. Unexpected server failures return `INTERNAL_ERROR` (HTTP 500) with no internal detail.

## Validation errors

HTTP 422 with `VALIDATION_FAILED`. `error.details.fields` maps each field name to a list of messages.

## Error codes

| HTTP | Code | When |
|---|---|---|
| 400 | `IDEMPOTENCY_KEY_REQUIRED` | `POST /orders` without a valid `Idempotency-Key` header |
| 401 | `INVALID_API_KEY` | Missing, malformed or unknown key |
| 401 | `API_KEY_REVOKED` | Key revoked |
| 401 | `API_KEY_EXPIRED` | Key expired |
| 403 | `MISSING_SCOPE` | Key lacks the scope; see `details.required_scope` |
| 403 | `VENDOR_NOT_APPROVED` | Owning vendor is not approved right now |
| 403 | `APPLICATION_INACTIVE` | Application suspended or revoked |
| 403 | `ENVIRONMENT_MISMATCH` | Key environment not valid for the endpoint |
| 403 | `FORBIDDEN` | Key not permitted to do this |
| 404 | `NOT_FOUND` | No such resource for your application |
| 404 | `OFFER_UNAVAILABLE` | Product does not exist or is not available to you |
| 404 | `QUOTE_NOT_FOUND` | No such quote |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method for the path |
| 409 | `QUOTE_ALREADY_USED` | The quote already produced an order |
| 409 | `QUOTE_PRICE_CHANGED` | Price changed since the quote; get a new quote |
| 409 | `IDEMPOTENCY_CONFLICT` | Same `Idempotency-Key` used for a different quote |
| 409 | `ORDER_NOT_PAYABLE` | The order can no longer be paid |
| 409 | `PAYMENT_ALREADY_INITIATED` | Payment already started; poll the order status |
| 409 | `SIMULATION_NOT_ALLOWED` | Sandbox step not allowed in the order's current state |
| 410 | `QUOTE_EXPIRED` | Quote expired; get a new quote |
| 422 | `VALIDATION_FAILED` | Bad or missing fields |
| 429 | `RATE_LIMITED` | Too many requests; see `Retry-After` |
| 500 | `INTERNAL_ERROR` | Unexpected failure; quote `meta.request_id` to support |
| 502 | `PAYMENT_INITIATION_FAILED` | The payment could not be started; try again shortly |

Some other codes exist in the platform but are not currently returned by any endpoint. Do not build logic that depends on them.
