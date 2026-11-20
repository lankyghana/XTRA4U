## Quotes

`POST /quotes` needs `quotes:create`. Send JSON with:

| Field | Rule |
|---|---|
| `product_id` | Required. An offer id from the catalog, up to 40 characters |
| `recipient_phone` | Required. A valid Ghana mobile number |

The response (HTTP 201) holds the quote in `data`: `reference`, `product_id`, `product_name`, `network`, `size`, `seller`, `recipient_phone`, `price`, `currency`, `availability` and `expires_at`. A quote is valid for 10 minutes by default and the price in it is the price you will be charged. An unknown or unavailable product gives 404 `OFFER_UNAVAILABLE`; an invalid number gives 422 `VALIDATION_FAILED`.

## Creating an order

`POST /orders` needs `orders:create` and a header `Idempotency-Key`. Send JSON with:

| Field | Rule |
|---|---|
| `quote_reference` | Required. The `reference` of a quote |
| `payer_phone` | Optional. A Ghana mobile number; defaults to the recipient |
| `payer_network` | Optional. `MTN`, `TELECEL` or `AIRTELTIGO` |
| `client_reference` | Optional, test keys only. Your own reference, up to 100 characters |

The `Idempotency-Key` is required, up to 100 characters, letters, digits and `. _ : -` only. A missing or invalid one gives 400 `IDEMPOTENCY_KEY_REQUIRED`.

A new order returns HTTP 201 with `meta.idempotent_replay` set to false. Sending the same `Idempotency-Key` again for the same quote returns the existing order with HTTP 200 and `idempotent_replay` set to true, so it is safe to retry after a network failure. Using the same key for a different quote gives 409 `IDEMPOTENCY_CONFLICT`.

Quote problems: 404 `QUOTE_NOT_FOUND`, 410 `QUOTE_EXPIRED`, 409 `QUOTE_ALREADY_USED`, 409 `QUOTE_PRICE_CHANGED` (get a new quote), 409 `OFFER_UNAVAILABLE`.

## Reading an order

`GET /orders/{order}` and `GET /orders/{order}/status` need `orders:read`. Order ids look like `ord_` followed by 26 lowercase letters or digits (test orders start with `sbx_ord_`). You can only read orders created by your own application.

## Order fields

`id`, `status`, `amount`, `currency`, `recipient_phone`, `product` (`name`, `network`, `size`), `seller`, `payment` (`status`, `reference`), `fulfillment` (`status`) and `created_at`.

## Order status

`status` is one of: `awaiting_payment` (created, no payment started), `payment_pending` (payment started), `processing` (paid, being delivered), `completed` (delivered) or `failed`. `payment.status` is `unpaid`, `pending`, `paid` or `failed`. Fulfilment state is only meaningful after payment.

The status endpoint returns just `id`, `status`, `payment` and `fulfillment`. For live keys it may first refresh the payment result from the gateway.
