# Milestone 3 — Developer portal, API keys, webhooks

Scope note: this is design/operations documentation for what Milestone 3 built. The public
developer documentation and OpenAPI spec are Milestone 7.

## Auth realm

The portal uses its own `developer` guard (`developers` table, `App\Models\Developer\Developer`),
following the repo's existing guard-per-realm pattern (`web` / `vendor` / `admin`). Developers are a
fourth realm; no Vendor, Admin or User record is reused or duplicated. Sessions, CSRF and CSP are the
normal `web` group. Suspended developers cannot log in and lose an open session on the next request.

## Access model

| Concept | Where | Rule |
|---|---|---|
| Application type | `vendor_id` null / set | Fixed at creation. Never editable. |
| Vendor consent | `vendor_link_status` none/pending/approved/rejected | Only the bound vendor can decide. |
| Production access | `production_status` none/requested/approved/rejected | Only an admin can approve. |
| Key environment | `developer_api_keys.environment` | sandbox keys always authenticate; production keys work only while `hasProductionAccess()`. |

`hasProductionAccess()` = `environment == production && production_status == approved && status == active`.
Withdrawing access kills production keys instantly (checked per request, not at issue time).
A vendor-bound application cannot request production, and an admin cannot approve it, until its vendor approved.

Until Milestone 4 (sandbox engine) every authenticated commerce route answers `501 SANDBOX_UNAVAILABLE`
for sandbox keys. That is deliberate: a sandbox key never reaches real commerce.

## One-time secrets

API keys and webhook secrets are returned **directly in the POST response** (never a redirect, never
flashed to the session) with `Cache-Control: no-store`. Keys are stored as `sha256(key)`; webhook
secrets are stored with Laravel's `encrypted` cast (they must be recoverable to sign requests) and are
hidden from serialisation.

## Webhook pipeline

`developer-webhooks:run` runs every minute (`routes/console.php`, `withoutOverlapping`):

1. **Detect** — `OrderEventDetector` polls API-originated orders (`developer_application_id` set,
   inside `detect_window_days`) and derives events from the order row.
2. **Persist** — `DeveloperEventRecorder` writes `developer_events` rows. Unique `(order_id, type)`:
   each event type exists at most once per order, however often it is observed.
3. **Fan out** — `WebhookFanout` creates one `developer_webhook_deliveries` row per subscribed, active
   endpoint of the **same application**. Unique `(webhook_id, event_id)`.
4. **Dispatch** — `WebhookDispatcher::dispatchDue()` queues `DeliverDeveloperWebhook` for due work and
   for deliveries stuck `delivering` past `claim_timeout_seconds` (crash recovery).

Every stage is idempotent; a crash between stages delays but cannot lose or duplicate an event. The
checkpoint table (`developer_order_states`) only records what was last seen — losing it re-observes
state but the unique indexes still prevent duplicates.

`order.created` and `payment.pending` are additionally recorded synchronously when the API itself
causes them (best effort); the sweeper backstops both.

**Limitation (inherent to the platform):** XTRA4U keeps no order status history. Only the *latest*
observed state per sweep is detectable. If an order passes through several states between two sweeps,
the intermediate events are not fabricated.

### Retries

Attempt 1 immediately, then after 60 s, 5 min, 30 min, 2 h, 6 h (6 attempts per round). After the last
failure the delivery is `failed`. A developer can retry a failed delivery of an active endpoint; this
starts a fresh round and is counted in `manual_retries`. Attempt history is never reset.

### Request format

```
POST <endpoint>
Content-Type: application/json
User-Agent: XTRA4U-Webhooks/1.0
X-XTRA4U-Event-Id: evt_01j...
X-XTRA4U-Event-Type: order.completed
X-XTRA4U-Delivery-Id: dlv_01j...
X-XTRA4U-Delivery-Attempt: 1
X-XTRA4U-Signature: t=1700000000,v1=<hex hmac-sha256>

{"id":"evt_01j...","type":"order.completed","api_version":"v1","created":1700000000,"data":{"order":{...}}}
```

Events: `order.created`, `order.processing`, `order.completed`, `order.failed`,
`payment.pending`, `payment.succeeded`, `payment.failed`. The body is byte-identical on every retry of
the same event; only `X-XTRA4U-Delivery-Attempt` and the signature timestamp change.

A 2xx response is success. Anything else (including every 3xx — redirects are never followed) is a
failed attempt. The response body is never read or stored.

### Verifying a signature

`v1 = HMAC_SHA256(secret, "<t>.<raw request body>")`, hex-encoded. Always verify against the **raw**
bytes, compare in constant time, reject if `|now - t|` exceeds 5 minutes, and de-duplicate on the event
`id` (delivery is at-least-once).

PHP:

```php
function verifyXtra4uSignature(string $header, string $rawBody, string $secret, int $tolerance = 300): bool
{
    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't' && ctype_digit($v)) $t = (int) $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if ($t === null || !$sigs || abs(time() - $t) > $tolerance) return false;
    $expected = hash_hmac('sha256', $t.'.'.$rawBody, $secret);
    foreach ($sigs as $s) if (hash_equals($expected, $s)) return true;
    return false;
}
```

Node.js:

```js
const crypto = require('crypto');
function verify(header, rawBody, secret, tolerance = 300) {
  const parts = Object.fromEntries(header.split(',').map(p => p.trim().split('=')));
  const t = Number(parts.t);
  if (!Number.isInteger(t) || !parts.v1 || Math.abs(Date.now() / 1000 - t) > tolerance) return false;
  const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');
  const a = Buffer.from(expected), b = Buffer.from(parts.v1);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

Python:

```python
import hmac, hashlib, time
def verify(header, raw_body: bytes, secret: str, tolerance=300):
    parts = dict(p.strip().split('=', 1) for p in header.split(',') if '=' in p)
    try: t = int(parts['t'])
    except (KeyError, ValueError): return False
    if abs(time.time() - t) > tolerance or 'v1' not in parts: return False
    expected = hmac.new(secret.encode(), f'{t}.'.encode() + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, parts['v1'])
```

## SSRF protection

Validation (`WebhookUrlValidator`) runs at registration, on URL edit, on re-enable, **and again
immediately before every delivery attempt**:

- `https` only; port 443 only (`developer_api.webhooks.allowed_ports`); no credentials; ≤ 2048 chars;
  no control characters or whitespace inside the URL.
- Host must be an ASCII DNS name. **All IP literals are refused**, including decimal / hex / octal /
  short forms and bracketed IPv6 (a name whose last label has no letter is treated as an IP).
- `localhost`, `*.localhost`, `*.local`, `*.internal`, `*.lan`, `*.home`, `*.corp`, `*.svc`,
  `*.cluster`, `*.test`, `*.example`, `*.invalid`, `*.onion`, `*.arpa`, `metadata.*` style names refused.
- DNS is resolved (A and AAAA) and **every** answer must be public (`IpGuard`). IPv4 is a deny-list of
  special-use ranges (incl. 169.254.0.0/16 metadata, RFC1918, CGNAT, loopback, multicast, reserved).
  IPv6 is an allow-list: only global unicast `2000::/3` minus 2001::/23, 2001:db8::/32, 2002::/16,
  3fff::/20. So `::1`, ULA (`fd00:ec2::254`), link-local, IPv4-mapped and NAT64 are all rejected.
- Rejection messages never reveal the internal address a name resolved to.

Transport (`PinnedWebhookTransport`) — defeats DNS rebinding:

- The connection is pinned to the validated IP with `CURLOPT_RESOLVE` (`host:443:ip`): curl performs no
  second DNS lookup, yet the Host header, SNI and TLS certificate verification still use the real host name.
- Redirects are never followed (`allow_redirects=false`, `CURLOPT_FOLLOWLOCATION=false`); a 3xx is a failed
  delivery. `CURLOPT_PROTOCOLS` / `REDIR_PROTOCOLS` are https-only. Proxies are disabled.
- Connect and total timeouts; response capped by `CURLOPT_MAXFILESIZE` plus a progress callback that aborts
  streams over `max_response_bytes`; the body is never read.
- Fails closed: without libcurl `CURLOPT_RESOLVE` support the transport refuses to send.

## Operations

- A queue worker **must** be running (`DeliverDeveloperWebhook`), as for the rest of the platform.
- The scheduler cron (`* * * * * php artisan schedule:run`) must be active.
- Request logs older than `developer_api.log_retention_days` are pruned daily.
- New Tailwind classes in the portal views need `npm run build` to be styled in production.
