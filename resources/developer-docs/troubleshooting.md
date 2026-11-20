## First steps for any error

1. Read `error.code` in the response. Do not rely on the message text.
2. Note `meta.request_id` (also the `X-Request-Id` header). Support can find the call with it.
3. Check the key's environment: test keys (`xt_test_`) and live keys (`xt_live_`) see different data, and ids from one do not exist in the other.
4. Look at your application in the Developer Portal for key status, scopes and expiry.

## 401 INVALID_API_KEY

The key is missing, mistyped, truncated or not a real key. Send it as a Bearer token in the `Authorization` header, or in the `X-API-Key` header. Check for trailing spaces and for a key copied from a masked display. A key is shown in full only once.

## 401 API_KEY_REVOKED or API_KEY_EXPIRED

Keys expire after 365 days by default and revoked keys never work again. Create a new key in the portal (or rotate the old one) and deploy it.

## 403 MISSING_SCOPE

`error.details.required_scope` names the missing scope. Create a new key that includes it; scopes cannot be added to an existing key. If the missing scope is `sandbox:simulate`, it only works on test keys.

## 403 VENDOR_NOT_APPROVED or APPLICATION_INACTIVE

The vendor account that owns the application is no longer approved, or the application was suspended or revoked. Keys of an unapproved vendor are revoked and stay revoked after re-approval, so create new keys. Contact XTRA4U support about approval.

## 403 ENVIRONMENT_MISMATCH

You called a sandbox-only endpoint with a live key, or the key's environment is not valid. Use a test key.

## 404 NOT_FOUND or OFFER_UNAVAILABLE

For an order or transaction: it does not exist for your application, or you are using a live id with a test key (or reverse). For an offer: it is not one of your own active products or listings, or the service is closed. Use ids exactly as the API returned them.

## 409 on orders and payments

`QUOTE_ALREADY_USED`: that quote already made an order. `QUOTE_PRICE_CHANGED`: ask for a new quote. `IDEMPOTENCY_CONFLICT`: you reused an `Idempotency-Key` for a different quote; use a fresh key per new order and the same key only when retrying the same order. `PAYMENT_ALREADY_INITIATED`: do not start payment twice; poll the status. `ORDER_NOT_PAYABLE`: the order is already paid or failed.

## 410 QUOTE_EXPIRED

Quotes last about 10 minutes. Create a new quote, then a new order.

## 422 VALIDATION_FAILED

`error.details.fields` lists each bad field. Common causes: an invalid Ghana mobile number, `payer_network` not one of `MTN`, `TELECEL`, `AIRTELTIGO`, or a missing `product_id` or `quote_reference`.

## 429 RATE_LIMITED

You went over the per-application limit or failed authentication too often. Wait for `Retry-After` seconds and slow down. See the rate limit guide.

## An order stays in payment_pending

A payment is only confirmed when the gateway verifies it. Check `GET /orders/{order}/status`. With a test key a payment stays pending until you simulate an outcome.

## Webhooks not arriving

Check that the endpoint is enabled and subscribed to the event, that it is for the right environment, that the URL is https on port 443 with a public host name, that your server answers with 2xx quickly, and that you verify the signature against the raw body. Failed deliveries can be retried in the portal.
