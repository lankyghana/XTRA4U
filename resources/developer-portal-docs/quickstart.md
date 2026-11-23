## Base URL and headers

All endpoints live under `/api/v1` on the XTRA4U host, for example `https://xtra4u.com/api/v1`. Use HTTPS only. Every response is JSON.

| Header | When | Value |
|---|---|---|
| `Authorization` | Every request except `/health` | `Bearer xt_test_...` or `Bearer xt_live_...` (the `X-API-Key` header is also accepted) |
| `Accept` | Recommended | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Idempotency-Key` | `POST /orders` only (required) | A unique string you generate, up to 100 characters: letters, digits and `. _ : -` |

The key decides the environment: `xt_test_` keys reach the sandbox (no real money, nothing delivered) and `xt_live_` keys reach the real marketplace. The URLs and request shapes are identical, so build and test with a test key first. All values in the examples below (ids, references, numbers, prices) are illustrative.

## Check the API is up

`GET /api/v1/health` needs no key.

```bash
curl https://xtra4u.com/api/v1/health
```

```json
{
  "success": true,
  "message": "API is available",
  "data": { "status": "ok", "version": "v1", "time": "2026-11-23T10:15:00+00:00" },
  "meta": { "request_id": "req_..." }
}
```

## 1. List your products

`GET /api/v1/products` needs the `products:read` scope. Optional query parameters: `network`, `service`, `vendor`, `min_price`, `max_price`, `q`, `sort`, `page`, `per_page` (see the catalog guide).

```bash
curl "https://xtra4u.com/api/v1/products?network=MTN&per_page=10" \
  -H "Authorization: Bearer xt_test_yourkey" \
  -H "Accept: application/json"
```

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": "own_12",
      "type": "direct",
      "name": "MTN 5GB",
      "service": "data",
      "network": "MTN",
      "network_image_url": null,
      "size": "5GB",
      "validity": "30 days",
      "description": null,
      "price": 24.5,
      "currency": "GHS",
      "available": true,
      "seller": { "code": "VND123", "name": "Your Shop" }
    }
  ],
  "meta": {
    "request_id": "req_...",
    "pagination": { "current_page": 1, "per_page": 10, "total": 1, "last_page": 1 }
  }
}
```

## 2. Create a quote

`POST /api/v1/quotes` needs `quotes:create`. A quote freezes the price for one recipient for 10 minutes.

```bash
curl -X POST https://xtra4u.com/api/v1/quotes \
  -H "Authorization: Bearer xt_test_yourkey" \
  -H "Content-Type: application/json" \
  -d '{ "product_id": "own_12", "recipient_phone": "0241234567" }'
```

HTTP 201:

```json
{
  "success": true,
  "message": "Quote created successfully",
  "data": {
    "reference": "qt_01hxyz...",
    "product_id": "own_12",
    "product_name": "MTN 5GB",
    "network": "MTN",
    "size": "5GB",
    "seller": { "code": "VND123", "name": "Your Shop" },
    "recipient_phone": "0241234567",
    "price": 24.5,
    "currency": "GHS",
    "availability": "available",
    "expires_at": "2026-11-23T10:25:00+00:00"
  },
  "meta": { "request_id": "req_..." }
}
```

With a test key the reference starts with `sbx_qt_` and `data` also carries `environment: "sandbox"` and `simulated: true`.

## 3. Create an order from the quote

`POST /api/v1/orders` needs `orders:create` and an `Idempotency-Key` header. Retrying with the same key and the same quote returns the same order (HTTP 200, message "Order already exists for this Idempotency-Key", `meta.idempotent_replay: true`) instead of creating a second one.

```bash
curl -X POST https://xtra4u.com/api/v1/orders \
  -H "Authorization: Bearer xt_test_yourkey" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-2026-000142" \
  -d '{ "quote_reference": "qt_01hxyz...", "payer_phone": "0241234567", "payer_network": "MTN" }'
```

HTTP 201. `payer_phone` and `payer_network` are optional.

```json
{
  "success": true,
  "message": "Order created successfully",
  "data": {
    "id": "ord_abcdefghijklmnopqrstuvwxyz",
    "status": "awaiting_payment",
    "amount": 24.5,
    "currency": "GHS",
    "recipient_phone": "0241234567",
    "product": { "name": "MTN 5GB", "network": "MTN", "size": "5GB" },
    "seller": { "code": "VND123", "name": "Your Shop" },
    "payment": { "status": "unpaid", "reference": null },
    "fulfillment": { "status": "not_started" },
    "created_at": "2026-11-23T10:16:00+00:00"
  },
  "meta": { "request_id": "req_...", "idempotent_replay": false }
}
```

## 4. Start the payment

`POST /api/v1/orders/{order}/payments` needs `payments:initiate`. It takes no body. HTTP 202 means the payment was started, not that it succeeded.

```bash
curl -X POST https://xtra4u.com/api/v1/orders/ord_abcdefghijklmnopqrstuvwxyz/payments \
  -H "Authorization: Bearer xt_live_yourkey"
```

```json
{
  "success": true,
  "message": "Payment initiated. Payment is only confirmed by the platform after the gateway verifies it.",
  "data": {
    "order_id": "ord_abcdefghijklmnopqrstuvwxyz",
    "status": "pending",
    "amount": 24.5,
    "currency": "GHS",
    "reference": "PAY-1234567890",
    "authorization_url": "https://checkout.example/pay/abc",
    "flow_type": null,
    "checkout_config": null
  },
  "meta": { "request_id": "req_..." }
}
```

`authorization_url`, `flow_type` and `checkout_config` depend on the payment gateway XTRA4U has active (for example a redirect gateway returns an `authorization_url` and leaves the other two empty). Treat them as opaque and follow whichever is present. With a test key no gateway is used: the response has `environment: "sandbox"`, `simulated: true`, `flow_type: "simulated"` and a null `authorization_url`.

## 5. Check the order status

`GET /api/v1/orders/{order}/status` needs `orders:read`. Poll every few seconds at most, or use webhooks.

```bash
curl https://xtra4u.com/api/v1/orders/ord_abcdefghijklmnopqrstuvwxyz/status \
  -H "Authorization: Bearer xt_live_yourkey"
```

```json
{
  "success": true,
  "message": "Order status retrieved successfully",
  "data": {
    "id": "ord_abcdefghijklmnopqrstuvwxyz",
    "status": "processing",
    "payment": { "status": "paid", "reference": "PAY-1234567890" },
    "fulfillment": { "status": "processing" }
  },
  "meta": { "request_id": "req_..." }
}
```

`status` is one of `awaiting_payment`, `payment_pending`, `processing`, `completed` or `failed`. `GET /orders/{order}` returns the full order, and `GET /transactions/{reference}` looks a payment up by its reference.

## 6. Try the whole flow in the sandbox

With a test key (created with the `sandbox:simulate` scope) a payment never succeeds by itself. You choose the outcome:

```bash
curl -X POST https://xtra4u.com/api/v1/sandbox/orders/sbx_ord_abcdefghijklmnopqrstuvwxyz/simulate \
  -H "Authorization: Bearer xt_test_yourkey" \
  -H "Content-Type: application/json" \
  -d '{ "scenario": "success" }'
```

Send exactly one of `event` or `scenario`. `GET /api/v1/sandbox/simulations` lists the allowed values. See the sandbox guide.

## Errors

Errors use one envelope. Branch on `error.code`, not on `message`.

```json
{
  "success": false,
  "message": "The request failed validation.",
  "error": {
    "code": "VALIDATION_FAILED",
    "details": { "fields": { "recipient_phone": ["Invalid Ghana mobile number."] } }
  },
  "meta": { "request_id": "req_..." }
}
```

A missing or wrong key gives HTTP 401 `INVALID_API_KEY`. A key without the needed scope gives 403 `MISSING_SCOPE` with `error.details.required_scope`. Too many requests gives 429 `RATE_LIMITED` with a `Retry-After` header. The full list is in the errors guide.

## Rate limits

Requests are limited per application per minute (60 unless your application has a different limit, shown in the portal). Authenticated responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`. See the rate limits guide.

## Receive webhooks

Instead of polling, register an HTTPS endpoint in the portal (application page, Webhooks). XTRA4U sends a signed POST for events such as `order.completed` or `payment.failed`. Verify the `X-XTRA4U-Signature` header (`t=<unix time>,v1=<hex digest>`) with HMAC-SHA256 over `t`, a dot, then the raw request body, using the endpoint's signing secret.

```php
// PHP example. $secret is the endpoint's signing secret.
$raw = file_get_contents('php://input');            // the raw body, exactly as received
$header = $_SERVER['HTTP_X_XTRA4U_SIGNATURE'] ?? '';
parse_str(str_replace(',', '&', $header), $parts);  // t=...&v1=...

$expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $raw, $secret);
$fresh = abs(time() - (int) ($parts['t'] ?? 0)) <= 300;

if (! $fresh || ! hash_equals($expected, $parts['v1'] ?? '')) {
    http_response_code(400);
    exit;
}
http_response_code(200);  // any 2xx acknowledges; de-duplicate on the event "id"
```

```js
// Node.js example (Express). Use the raw body, not the parsed JSON.
const crypto = require('crypto');
function verify(rawBody, header, secret) {
  const parts = Object.fromEntries(header.split(',').map(p => p.split('=')));
  const expected = crypto.createHmac('sha256', secret).update(`${parts.t}.${rawBody}`).digest('hex');
  const fresh = Math.abs(Date.now() / 1000 - Number(parts.t)) <= 300;
  const a = Buffer.from(expected), b = Buffer.from(parts.v1 || '');
  return fresh && a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

See the webhooks guide for the event list, request headers and the retry schedule.
