# USSD / Results Checker production verification

Read-only checks and a staged plan. Nothing here is run automatically; no
migration in this repository changes a table engine.

## 1. Database engines

Production is MariaDB 11.4 on cPanel. `orders` was found on MyISAM on
2026-10-09, where `DB::transaction()` and `lockForUpdate()` are silent no-ops.
Local and test databases are InnoDB/SQLite, so this never reproduces in tests.

### Read-only check (run in phpMyAdmin or `mysql`)

```sql
SELECT TABLE_NAME, ENGINE, TABLE_ROWS,
       ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 1) AS size_mb
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'orders', 'result_checker_orders', 'result_checker_pins',
    'ussd_sessions', 'ussd_subscriptions',
    'transactions', 'vendors', 'wallet_ledgers',
    'jobs', 'failed_jobs', 'cache', 'cache_locks'
  )
ORDER BY ENGINE, TABLE_NAME;

-- Everything that is not InnoDB, any table:
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB';
```

### Which tables must be InnoDB

| Table | Why |
|---|---|
| `orders` | Row locks and transactions in payment completion, reconciliation, duplicate-credit protection; FKs from `utility_bill_orders`. |
| `result_checker_orders` | `lockForUpdate()` in `handlePaymentCallback` / `doAllocatePins` / the new failed-status guard. |
| `result_checker_pins` | `lockForUpdate()` on available PINs is what prevents two orders receiving the same PIN. |
| `ussd_sessions` | Session insert and the paid-session reservation share one transaction; a failed insert must roll the reservation back. |
| `ussd_subscriptions` | Conditional `used_sessions` increment, one-live-subscription unique index. |
| `transactions`, `wallet_ledgers`, `vendors` | Wallet credits and ledger writes are transactional. |
| `jobs`, `cache`, `cache_locks` | Database queue reservation, `ShouldBeUnique` jobs and `Cache::add` claims. |

If every row above reports InnoDB there is nothing to do. The USSD tables and
`result_checker_*` tables were created by recent migrations and are expected to
be InnoDB; `orders` is the known risk.

### Safe conversion strategy (separate approval step)

1. Only for tables the query shows as non-InnoDB. Do one table at a time,
   smallest first, and never during peak USSD traffic.
2. Take the deploy backup first (`scripts/deploy.sh` already refuses to migrate
   without one) and confirm it is non-empty. For MyISAM tables a consistent dump
   needs `--lock-tables`, not `--single-transaction`.
3. Check free disk is at least twice the table size (the conversion copies it).
4. Put the site in maintenance mode, or at least pause the USSD gateways, so no
   payment completes mid-copy. Record `SELECT COUNT(*)` and `MAX(id)` first.
5. `ALTER TABLE orders ENGINE=InnoDB, ALGORITHM=COPY, LOCK=SHARED;` (readers
   continue, writers wait). Repeat per table.
6. Verify the engine, the row count and `MAX(id)` match, then run the app smoke
   check below and leave maintenance mode.
7. Rollback is restoring the backup; do not convert back in place.

Converting does not change data semantics. It only starts enforcing the locks
and transactions the code already assumes.

## 2. Queue worker and scheduler

Per the README, production uses two cron entries (no daemon):

```cron
* * * * * PHP_BINARY /path/to/core/artisan schedule:run >> /dev/null 2>&1
* * * * * PHP_BINARY /path/to/core/artisan queue:work --queue=default,audit --stop-when-empty --tries=3 >> /dev/null 2>&1
```

Required `.env`: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`. `sync`
would run listeners inline; `array`/`file` caches would break the unique-job
lock and the one-time pending-stock SMS claim.

What this change relies on:

| Need | Where | Queue |
|---|---|---|
| Delivery SMS after PIN allocation | `SendResultCheckerDeliveryNotification` | `default` |
| Pending-stock notice after verified payment | same listener | `default` |
| Recovery of paid pending-stock orders | `FulfillPendingOrdersJob`, from PIN upload, PIN release and the `result-checkers:fulfil-pending-stock` schedule (every 5 min) | `default` |

Both queues are covered by the cron worker above.

Read-only verification on the server:

```bash
php artisan schedule:list | grep -E "fulfil-pending-stock|reconcile|queue:health"
php artisan config:show queue.default cache.default
```

```sql
SELECT COUNT(*) AS waiting, MIN(FROM_UNIXTIME(available_at)) AS oldest FROM jobs;
SELECT COUNT(*) AS failed, MAX(failed_at) AS latest FROM failed_jobs;
SELECT status, COUNT(*) FROM result_checker_orders
WHERE paid_at IS NOT NULL AND status IN ('pending_stock','processing') GROUP BY status;
```

`waiting` should be near zero and `oldest` recent. A growing `oldest` means the
worker cron is not running; `queue:health-check` alerts after
`QUEUE_HEALTH_STALE_MINUTES` (15). Confirm the admin Payment Health scheduler
heartbeat is current.

I could not inspect the production server from this environment; the checks
above need to be run there.

## 3. Post-deploy smoke checks (no real payments)

- `php artisan schedule:list` shows `result-checkers:fulfil-pending-stock`.
- With a paid `pending_stock` order and zero available PINs, the sweep logs no
  job. Upload one PIN: the order completes and one PIN SMS is sent.
- Close and reopen "Data" and "Results" under Admin > Service Availability and
  dial both USSD gateways (test numbers, no confirmation step).

## 4. Deployment checklist

This change set has **no migrations** and changes no schema. Confirm with
`php artisan migrate:status` on the server: nothing pending beyond what is
already deployed.

Before deploying
1. Run the engine SQL (section 1) and record the result. Decide separately
   whether any conversion is needed; do not bundle it with this deploy.
2. Record the currently deployed commit (`git -C <repo> rev-parse HEAD`) as the
   rollback target.
3. Check the queue is healthy: `jobs` backlog near zero, `failed_jobs` reviewed.
4. Pick a low-traffic window; USSD sessions in flight are short (minutes).

Deploy (`scripts/deploy.sh`, which already does these in order)
1. Maintenance mode on.
2. Database backup; the script refuses to continue if it is empty.
3. Code sync, `composer install --no-dev`.
4. `artisan migrate --force` (expected: "Nothing to migrate").
5. Config/route/view caches rebuilt.
6. `artisan queue:restart`, maintenance mode off.

After deploying
1. `php artisan schedule:list` includes `result-checkers:fulfil-pending-stock`.
2. Both cron entries are still present (section 2); `jobs` drains.
3. Smoke test (no real payments; stop before the final "1. Confirm & Pay"):
   - GWO SEVO: start a call, navigate to the bundle list and to Results
     Checker; confirm the vendor's own bundles and prices are shown.
   - Moolre: dial the vendor code and navigate the same two menus.
   - Admin > Service Availability: close "Data", dial both gateways and see the
     closed message, then reopen it. Repeat for "Results".
   - Admin PIN upload for a service with no waiting orders: no job is queued.
4. Watch `storage/logs` for "Scheduled Results Checker pending-stock recovery
   failed", "ResultCheckerService: Error sending pending-stock SMS" and GWO SEVO
   processing errors for the first hour.

## 5. Rollback plan

There is no schema change, so rollback is code-only and the database is not
restored.

1. Maintenance mode on.
2. Redeploy the commit recorded before deploying (`scripts/deploy.sh` on that
   commit, or `git revert` of the release commit and deploy).
3. `artisan queue:restart`; maintenance mode off.
4. Confirm `schedule:list` no longer shows the pending-stock entry.

Effects to expect after a rollback
- The last session of each allowance is again unusable after the main menu, a
  late "failed" webhook can again overwrite a paid Results Checker order, and
  USSD ignores Service Availability and zero prices.
- Orders already fulfilled by the recovery job stay fulfilled (correct); nothing
  needs reversing. The pending-stock SMS claims in the cache are harmless.
- Restore the database backup only if data was damaged, never as a routine
  rollback, since it would discard orders and payments taken since the deploy.

Roll back if: USSD error rate rises on either gateway, paid orders stop
completing, duplicate PIN SMS or duplicate allocations appear, or the queue
stops draining after the deploy.
