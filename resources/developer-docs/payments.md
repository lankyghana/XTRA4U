## Starting a payment

`POST /orders/{order}/payments` needs `payments:initiate`. It takes no body. On a live key it starts a real payment through the payment gateway that XTRA4U currently has active, and answers HTTP 202 with:

`order_id`, `status` (`pending`), `amount`, `currency`, `reference`, `authorization_url`, `flow_type` and `checkout_config`.

Depending on the gateway, you either send the customer to `authorization_url`, or use `flow_type` and `checkout_config` to continue in the gateway's own checkout. These two values are decided by the platform; treat them as opaque and follow whichever is present.

A 202 means the payment was started, not that it succeeded. Payment is only confirmed by the platform after the gateway verifies it. Never mark an order as paid on your side because this call succeeded.

## Errors

- 409 `PAYMENT_ALREADY_INITIATED`: payment was already started for this order. Poll the order status instead of starting again.
- 409 `ORDER_NOT_PAYABLE`: the order is already paid or failed, or can no longer be paid.
- 502 `PAYMENT_INITIATION_FAILED`: the gateway could not start the payment. Try again shortly.
- 404 `NOT_FOUND`: no such order for your application.

## Following the payment

Use `GET /orders/{order}/status` (needs `orders:read`) or webhooks. When the payment succeeds the order moves to `processing` and then `completed`; if it fails the order becomes `failed`.

## Looking up a transaction

`GET /transactions/{reference}` needs `orders:read`. The `reference` is the payment reference returned when the payment was started. The response has `reference`, `order_id`, `status` (`unpaid`, `pending`, `paid` or `failed`), `amount`, `currency` and `paid_at`. A reference that does not belong to your application is `NOT_FOUND`.

## Test keys

With a test key no gateway is contacted and no money moves. The response has `environment` set to `sandbox`, `simulated` set to true and `flow_type` set to `simulated`; `authorization_url` is null. The payment stays pending until you simulate its outcome (see the sandbox guide).

## What this API does not do

The API does not refund, reverse or cancel payments, does not move wallet balances and does not expose commissions or earnings.
