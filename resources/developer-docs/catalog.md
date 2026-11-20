## What the catalog contains

The catalog is the set of products your application may sell. It is limited to the vendor that owns the application: that vendor's own active data products ("direct" offers) and that vendor's active reseller listings ("reseller" offers). Offers from other vendors never appear, and asking for one by id gives `NOT_FOUND`. API v1 exposes mobile data bundles only. Airtime, utility bills, result checkers and AFA registration are not part of API v1.

If the vendor has no eligible products, or the data service is closed by the platform, the lists are simply empty.

## GET /services

Needs `services:read`. Returns the service categories exposed by the API, each with `code`, `name`, `description` and `available` (false when the platform has closed that service).

## GET /networks

Needs `services:read`. Returns the mobile networks that have at least one offer for your application: `code`, `name`, `image_url` and `offers` (how many offers).

## GET /products

Needs `products:read`. Returns a list of offers in `data`, and paging information in `meta.pagination` (`current_page`, `per_page`, `total`, `last_page`). All query parameters are optional:

| Parameter | Rule |
|---|---|
| `network` | Network name, for example `MTN` |
| `service` | Service code |
| `vendor` | Seller code |
| `min_price`, `max_price` | Numbers, 0 or more |
| `q` | Search text, up to 80 characters |
| `sort` | `price`, `-price` or `name` |
| `page` | Integer, 1 or more |
| `per_page` | 1 to 100 (default 25) |

## GET /products/{offer}

Needs `products:read`. The `offer` is an offer id such as `own_12` (a direct product) or `rsl_7` (a reseller listing). Any other form is `NOT_FOUND`.

## Offer fields

`id`, `type` (`direct` or `reseller`), `name`, `service`, `network`, `network_image_url`, `size`, `validity`, `description`, `price` (a number), `currency`, `available` and `seller` (an object with `code` and `name`).

Use the offer `id` as the `product_id` when you create a quote. Prices can change at any time, which is why an order is always created from a quote.
