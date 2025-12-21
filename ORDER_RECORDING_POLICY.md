# Order Recording Policy - Unsuccessful Payments Not Recorded

## Policy Change

**Previous Behavior**: Failed and abandoned orders remained in database with status 'failed' or 'abandoned'

**New Behavior**: Orders with unsuccessful payments are automatically DELETED from the database

## What Gets Deleted

### Immediate Deletion (via webhook/callback)
- Orders where payment verification fails
- Orders where gateway returns failed status
- Associated pending transactions

### Scheduled Deletion (daily cleanup at 2 AM)
- Orders that remain in 'pending' status for more than 24 hours
- These are considered abandoned (user never completed payment)
- Associated transactions

## What Gets Kept

✅ **ONLY successfully paid orders remain in the database**
- Payment status: 'completed'
- Transaction status: 'successful'
- Vendor balance has been credited
- All notifications sent

## Technical Implementation

### Modified Files

1. **[PaymentService.php](app/Services/PaymentService.php)**
   - `markTransactionFailed()`: Deletes order and transactions instead of marking as failed
   - `markTransactionAbandoned()`: Deletes order and transactions instead of marking as abandoned

2. **[PaymentCallbackController.php](app/Http/Controllers/PaymentCallbackController.php)**
   - Calls delete when verification fails

3. **[MoolreWebhookController.php](app/Http/Controllers/MoolreWebhookController.php)**
   - Calls delete when webhook reports payment failure

4. **[CleanupPendingOrders.php](app/Console/Commands/CleanupPendingOrders.php)** (NEW)
   - Console command to cleanup old pending orders
   - Runs daily at 2 AM via scheduler

5. **[console.php](routes/console.php)**
   - Scheduled task configuration

## Database Impact

### Before This Change
```sql
SELECT COUNT(*), payment_status 
FROM orders 
GROUP BY payment_status;

Results:
- pending: 30
- completed: 100
- failed: 45
- abandoned: 20
```

### After This Change
```sql
SELECT COUNT(*), payment_status 
FROM orders 
GROUP BY payment_status;

Results:
- pending: 0-5 (only very recent, < 24 hours)
- completed: 100
- failed: 0 (deleted)
- abandoned: 0 (deleted)
```

## Order Lifecycle

```
┌─────────────────────────────────────┐
│ User submits checkout form          │
└───────────────┬─────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│ Order created (status: pending)     │
│ Transaction created (status: pending│
└───────────────┬─────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│ User redirected to payment gateway  │
└───────────────┬─────────────────────┘
                │
        ┌───────┴────────┐
        │                │
        ▼                ▼
  ┌─────────┐      ┌──────────┐
  │ Success │      │  Failure │
  └────┬────┘      └─────┬────┘
       │                 │
       ▼                 ▼
┌──────────────┐   ┌────────────────┐
│ Webhook: OK  │   │ Webhook: Fail  │
│ Order kept   │   │ Order DELETED  │
│ Status:      │   │ ❌ Not recorded│
│ 'completed'  │   └────────────────┘
└──────────────┘
       │
       ▼
┌──────────────────┐
│ Credit balance   │
│ Send emails/SMS  │
│ ✅ RECORDED     │
└──────────────────┘

If no webhook after 24 hours:
       │
       ▼
┌──────────────────┐
│ Daily cleanup    │
│ Order DELETED    │
│ ❌ Not recorded │
└──────────────────┘
```

## Manual Cleanup Command

Run manually to clean up pending orders:

```bash
php artisan orders:cleanup-pending
```

Output:
```
Starting cleanup of pending orders...
Found 30 pending orders older than 24 hours.
Deleting order #1 - XTRA4U-693b98e24ddb1-49
Deleting order #2 - XTRA4U-693b991a333eb-50
...
✓ Successfully deleted 30 pending orders and their transactions.
```

## Monitoring

### Check for Pending Orders
```sql
SELECT COUNT(*), 
       MIN(created_at) as oldest,
       MAX(created_at) as newest
FROM orders 
WHERE payment_status = 'pending';
```

**Expected**: 
- Count: 0-10 (only very recent)
- Oldest: < 24 hours ago

**Alert if**:
- Count > 50 (possible webhook issues)
- Oldest > 24 hours (scheduler not running)

### Check Deletion Logs
```bash
# Laravel logs
tail -f storage/logs/laravel.log | grep "Deleting"

# Expected entries:
"Deleting failed order and transactions"
"Failed order deleted successfully"
"Cleanup: Deleted abandoned order"
```

## Impact on Reporting

### Before
Vendors saw failed orders in their dashboard (causing confusion)

### After
✅ Dashboard shows ONLY successful orders
✅ Revenue calculations accurate (no failed payments)
✅ Transaction lists clean (verified payments only)

## Rollback Procedure

If you need to keep failed orders for auditing:

1. Comment out deletion in `PaymentService.php`:
```php
// $order->delete();  // Comment this line
$order->update(['payment_status' => 'failed']); // Uncomment this
```

2. Disable scheduled cleanup:
```php
// In routes/console.php
// Schedule::command('orders:cleanup-pending')->dailyAt('02:00');
```

3. Run: `php artisan config:clear`

## Testing Checklist

- [x] Cleanup command deletes old pending orders
- [x] Failed webhook deletes order
- [x] Failed callback deletes order
- [ ] Test in production with real failed payment
- [ ] Verify scheduler runs daily (check logs after 2 AM)
- [ ] Confirm no pending orders accumulate

## Summary

**Result**: Database only contains successfully paid orders. Unsuccessful payment attempts leave no trace after 24 hours.

**Benefits**:
- ✅ Clean database (no clutter)
- ✅ Accurate reporting (no failed orders)
- ✅ Clear vendor dashboards
- ✅ Simplified queries (no status filtering needed for failed/abandoned)

**Trade-off**: 
- ❌ No historical record of failed payment attempts
- ❌ Can't analyze why payments fail (no data retained)

**Recommendation**: If you need failed payment analytics, consider keeping the data but filtering it from vendor views instead of deleting it.
