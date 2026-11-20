## Sending your key

Send the API key with every request except `/health`. Use either of these:

- the `Authorization` request header set to `Bearer` followed by a space and your key (preferred), or
- the `X-API-Key` request header set to your key.

A request with no key, a malformed key or an unknown key is answered with HTTP 401 and the error code `INVALID_API_KEY`. The API deliberately gives the same answer for "no such key" and "wrong secret part".

## Key format and environments

A key looks like `xt_test_` or `xt_live_`, then an 8 character identifier, an underscore and a 40 character secret part. `xt_test_` keys use the sandbox. `xt_live_` keys use production. The key itself decides the environment; there is no header or parameter that changes it.

Only a one-way hash of each key is stored. XTRA4U cannot show you a key again after creation. If you lose a key, create a new one (or rotate it) and revoke the old one.

## Creating, rotating and revoking keys

Keys are managed in the Developer Portal, on the page of an application, not through the API.

- Each key has a name, an environment, a list of scopes and an expiry date. The default expiry is 365 days.
- An application can hold at most 5 active keys.
- Rotating a key issues a new key with the same name, scopes and environment and revokes the old key at the same moment. Rotation does not carry over a scope that cannot currently be granted.
- Revoking a key is permanent.

## Why a key is refused

| HTTP | Error code | Meaning |
|---|---|---|
| 401 | `INVALID_API_KEY` | Missing, malformed or unknown key |
| 401 | `API_KEY_REVOKED` | The key was revoked |
| 401 | `API_KEY_EXPIRED` | The key passed its expiry date |
| 403 | `APPLICATION_INACTIVE` | The application is suspended or revoked |
| 403 | `VENDOR_NOT_APPROVED` | The vendor that owns the application is not approved right now |
| 403 | `ENVIRONMENT_MISMATCH` | The key's environment is not valid for this endpoint |

## When a vendor loses approval

Approval is checked on every request. If the vendor account is suspended or approval is withdrawn, API calls fail with `VENDOR_NOT_APPROVED` immediately, and all of that vendor's keys are revoked and its webhooks disabled. Approving the vendor again restores portal access but does not bring back revoked keys: create new keys.

## Failed attempts are limited

Repeated failed authentication from one IP address is limited (20 failures a minute by default). Beyond that the API answers 429 with `RATE_LIMITED` and a `Retry-After` header, even if a later attempt would have used a good key.

## Keep keys secret

Keep keys on your server. Never put a key in a mobile app, in browser code, in a public repository or in a support request. If a key may have leaked, revoke it in the portal.
