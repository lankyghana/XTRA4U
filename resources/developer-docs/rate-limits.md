## The limit

Requests are limited per application, per minute, not per key. Creating more keys does not raise the limit. The limit for an application is the first that applies of: a limit set on the application, the limit of its plan, or the platform default (60 requests a minute unless the platform has configured otherwise). You can see the limit that applies to each of your applications in the Developer Portal.

## Rate limit headers

Responses from authenticated endpoints include:

- `X-RateLimit-Limit`: your requests-per-minute limit
- `X-RateLimit-Remaining`: requests left in the current minute

Every response from `/api/v1` also carries an `X-Request-Id` header. The same value is in `meta.request_id` of the body. Quote it when you contact support.

## When you exceed it

The API answers HTTP 429 with the error code `RATE_LIMITED`, `X-RateLimit-Remaining` set to 0, a `Retry-After` header (in seconds) and `error.details.retry_after_seconds`. Wait that long before retrying.

## Other limits

- `GET /health` is limited to 60 requests a minute and needs no key.
- Failed authentication attempts are limited separately, per IP address (20 failures a minute by default). That also gives 429 `RATE_LIMITED`.
- Order status and transaction reads on live keys may ask the payment gateway for the latest result. The platform throttles those gateway checks (about one every 10 seconds per order), so polling faster than that returns the last known state rather than anything new.

## Good practice

- Do not poll in a tight loop. Poll an order status every few seconds at most, and prefer webhooks.
- Back off when you receive 429 and honour `Retry-After`.
- Cache catalog responses on your side for a short time.
- Reuse the same `Idempotency-Key` when you retry creating an order.
