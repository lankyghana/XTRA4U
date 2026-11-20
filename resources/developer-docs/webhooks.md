## What webhooks are

XTRA4U can send an HTTP POST to your server when an order or payment created through the API changes state. Webhook endpoints are managed in the Developer Portal, on the application page, not through the API. An application can have up to 5 endpoints. Each endpoint is for one environment (test or live) and subscribes to a chosen list of events.

## Events

`order.created`, `order.processing`, `order.completed`, `order.failed`, `payment.pending`, `payment.succeeded`, `payment.failed`. Only these exist.

XTRA4U does not keep a history of order states. If an order passes through several states between two checks, the in-between events are not invented. Treat webhooks as a prompt to read the order, and the order endpoint as the source of truth.

## Endpoint rules

The URL must be `https`, on port 443, with a public DNS host name. IP addresses, `localhost` and internal or private names are refused, redirects are never followed, and a 3xx answer counts as a failure. The address is checked again before every delivery.

## What your server receives

A POST with a JSON body and these headers: `Content-Type` (application/json), `User-Agent` (XTRA4U-Webhooks/1.0), `X-XTRA4U-Event-Id`, `X-XTRA4U-Event-Type`, `X-XTRA4U-Delivery-Id`, `X-XTRA4U-Delivery-Attempt` and `X-XTRA4U-Signature`. Test deliveries also have `X-XTRA4U-Environment`.

The body is `{"id": "evt_...", "type": "order.completed", "api_version": "v1", "created": <unix time>, "data": {"order": {...}}}`. The body is identical on every retry of the same event.

## Verifying the signature

`X-XTRA4U-Signature` looks like `t=<unix time>,v1=<hex digest>`. To verify:

1. Take the raw request body bytes, exactly as received (do not re-encode the JSON).
2. Build the string: the value of `t`, a dot, then the raw body.
3. Compute HMAC-SHA256 of that string using the endpoint's signing secret, as lowercase hex.
4. Compare with `v1` using a constant-time comparison.
5. Reject the request if `t` is more than 5 minutes from your clock.
6. De-duplicate on the event `id`, because delivery is at least once.

The signing secret is shown once, when the endpoint is created or its secret is rotated.

## Responses and retries

Reply with any 2xx status to acknowledge. Your response body is not read. Anything else is a failed attempt. A failed delivery is retried after 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours (six attempts in all). After that it is marked failed, and you can retry a failed delivery from the portal.

## When approval is lost

If the vendor loses approval, its webhook endpoints are disabled and pending deliveries are cancelled. Re-approval does not re-enable them: enable them again in the portal.
