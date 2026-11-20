## What the API is

The XTRA4U Developer API lets an approved XTRA4U vendor sell its own mobile data bundles from its own software. It is a JSON API served under the `/api/v1` path of the XTRA4U host. Every call is made with an API key that belongs to one application, and every application belongs to exactly one vendor.

An application can only see and sell the products of the vendor that owns it: that vendor's own active data products and that vendor's active reseller listings. It cannot see other vendors' products.

## Who can use it

Only registered, admin-approved XTRA4U vendors. There is no separate developer account, password or login. You sign in to the Developer Portal with your normal vendor account. If a vendor account stops being approved, the portal and the API both refuse it on the very next request, and the keys of that vendor are revoked.

## Getting started

1. Sign in with your vendor account and open the Developer Portal.
2. Create an application (a vendor can have up to 10).
3. Create an API key for it. Pick `sandbox` (test) while you build, and `production` (live) when you are ready. The full key is shown once only.
4. Call the API with the key. See the authentication and scopes guides.

## Environments

The environment is decided by the API key, never by the request. Test keys start with `xt_test_` and reach the sandbox, which simulates everything and never moves real money or delivers real products. Live keys start with `xt_live_` and reach the real marketplace. The same URLs serve both.

## Endpoint summary

All paths are under `/api/v1`.

| Method | Path | Scope needed |
|---|---|---|
| GET | `/health` | none, no key needed |
| GET | `/networks` | `services:read` |
| GET | `/services` | `services:read` |
| GET | `/products` | `products:read` |
| GET | `/products/{offer}` | `products:read` |
| POST | `/quotes` | `quotes:create` |
| POST | `/orders` | `orders:create` |
| GET | `/orders/{order}` | `orders:read` |
| GET | `/orders/{order}/status` | `orders:read` |
| POST | `/orders/{order}/payments` | `payments:initiate` |
| GET | `/transactions/{reference}` | `orders:read` |
| GET | `/sandbox/simulations` | `sandbox:simulate`, test keys only |
| POST | `/sandbox/orders/{order}/simulate` | `sandbox:simulate`, test keys only |

## Typical flow

Find a product with `GET /products`, create a quote with `POST /quotes`, create an order from the quote with `POST /orders`, start payment with `POST /orders/{order}/payments`, then follow the order with `GET /orders/{order}/status` or with webhooks.
