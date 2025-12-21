# Payment Verification Testing Guide

## Overview
This guide covers testing the critical payment verification fix that prevents vendor balances from updating before gateway payment confirmation.

## Critical Change Summary
**BEFORE**: Vendor balances credited on payment initiation (WRONG)
**AFTER**: Vendor balances credited only after gateway verification (CORRECT)

---

## Pre-Testing Checklist

- [ ] Database migrations run successfully
- [ ] All files have no syntax errors
- [ ] `.env` file has correct Moolre credentials:
  - `MOOLRE_API_PUBKEY`
  - `MOOLRE_API_USER`
  - `MOOLRE_BASE_URL=https://api.moolre.com`
- [ ] Laravel cache cleared: `php artisan config:clear`
- [ ] Application running: `php artisan serve`

---

## Test 1: Successful Payment Flow

### Setup
1. Select a vendor account for testing
2. Note current vendor `wallet_balance` in database
3. Select a product to purchase

### Steps

```
1. Navigate to checkout page for a product
2. Fill checkout form with test data:
   - Recipient phone: 0244000000
   - Payer phone: 0244000000
   - Amount: (product price)
   - Service ID: (from product)
   - Package ID: (from product)
3. Click "Pay Now"
```

### Expected Results

**After clicking "Pay Now" (BEFORE payment):**
```sql
-- Check order status
SELECT id, payment_reference, payment_status, status 
FROM orders 
WHERE id = [order_id];

Expected:
- payment_status: 'pending'
- status: 'Pending'

-- Check transaction status
SELECT id, order_id, vendor_id, status, payment_status, verified_at
FROM transactions
WHERE order_id = [order_id];

Expected:
- status: 'pending'
- payment_status: 'pending'
- verified_at: NULL

-- Check vendor balance (CRITICAL)
SELECT id, wallet_balance 
FROM vendors 
WHERE id = [vendor_id];

Expected:
- wallet_balance: UNCHANGED (same as before checkout)
```

**After completing payment on Moolre:**
```sql
-- Wait for webhook callback (check Laravel logs)
-- Then check order status
SELECT id, payment_reference, payment_status, status, payment_completed_at
FROM orders 
WHERE id = [order_id];

Expected:
- payment_status: 'completed'
- status: 'Processing'
- payment_completed_at: NOT NULL

-- Check transaction status
SELECT id, status, payment_status, verified_at
FROM transactions
WHERE order_id = [order_id];

Expected:
- status: 'successful'
- payment_status: 'completed'
- verified_at: NOT NULL (timestamp of verification)

-- Check vendor balance (CRITICAL)
SELECT id, wallet_balance 
FROM vendors 
WHERE id = [vendor_id];

Expected:
- wallet_balance: INCREASED by vendor_earning amount
```

### Success Criteria
- ✅ Vendor balance UNCHANGED until webhook received
- ✅ Transaction created with status='pending'
- ✅ After webhook: transaction status='successful'
- ✅ After webhook: vendor balance updated correctly
- ✅ Only ONE balance update (idempotency)

---

## Test 2: Failed Payment Flow

### Setup
Same as Test 1

### Steps
```
1. Navigate to checkout page
2. Fill checkout form
3. Click "Pay Now"
4. On Moolre payment page: Cancel or let it fail
```

### Expected Results

**After payment failure:**
```sql
-- Check order status
SELECT id, payment_status, status 
FROM orders 
WHERE id = [order_id];

Expected:
- payment_status: 'failed'
- status: 'Failed'

-- Check transaction status
SELECT id, status, payment_status
FROM transactions
WHERE order_id = [order_id];

Expected:
- status: 'failed'
- payment_status: 'failed'

-- Check vendor balance (CRITICAL)
SELECT id, wallet_balance 
FROM vendors 
WHERE id = [vendor_id];

Expected:
- wallet_balance: UNCHANGED (no credit for failed payment)
```

### Success Criteria
- ✅ Vendor balance NOT updated
- ✅ Transaction marked as 'failed'
- ✅ Order status reflects failure

---

## Test 3: Webhook Idempotency

### Purpose
Ensure duplicate webhook callbacks don't credit vendor balance multiple times

### Steps
```
1. Complete Test 1 (successful payment)
2. Manually replay the webhook callback using the same payload
3. Check vendor balance
```

### Expected Results
```sql
-- Check transaction count
SELECT COUNT(*) 
FROM transactions 
WHERE order_id = [order_id] AND status = 'successful';

Expected: 1 (not duplicated)

-- Check vendor balance
SELECT wallet_balance 
FROM vendors 
WHERE id = [vendor_id];

Expected: Only ONE increment (not doubled)
```

### Check Laravel Logs
```
storage/logs/laravel.log

Look for:
"Payment already completed" or "Moolre webhook: order already completed"
```

### Success Criteria
- ✅ Vendor balance only incremented ONCE
- ✅ Log shows idempotency check triggered
- ✅ No duplicate transactions created

---

## Test 4: Webhook Signature Verification

### Purpose
Ensure malicious webhook requests are rejected

### Steps
```
1. Use Postman or curl to send fake webhook
2. POST to /webhooks/moolre/payment
3. Send payload WITHOUT 'secret' field or with invalid secret
```

### Example Invalid Request
```bash
curl -X POST http://localhost:8000/webhooks/moolre/payment \
  -H "Content-Type: application/json" \
  -d '{
    "reference": "fake-reference-123",
    "status": "success"
  }'
```

### Expected Results
- HTTP Status: `403 Forbidden`
- Response: `"Missing webhook secret"` or `"Invalid signature"`
- Laravel Log: `"Moolre webhook missing secret field"`

### Success Criteria
- ✅ Invalid webhooks rejected
- ✅ No order completion without valid secret
- ✅ Security warning logged

---

## Test 5: Vendor Dashboard Accuracy

### Purpose
Ensure dashboards only show successfully verified payments

### Setup
Create test scenario with:
- 2 successful payments
- 1 failed payment
- 1 pending payment (initiated but not completed)

### Steps
```
1. Login to vendor dashboard
2. Navigate to transactions page
3. Check revenue summaries
```

### Expected Results

**Transaction List:**
- Should show ONLY the 2 successful payments
- Failed payment should NOT appear in earnings
- Pending payment should NOT appear in earnings

**Revenue Summaries:**
```sql
-- Verify the data shown matches this query:
SELECT SUM(vendor_earning) 
FROM transactions 
WHERE vendor_id = [vendor_id] 
  AND status = 'successful'
  AND payment_status = 'completed';
```

**Dashboard Stats:**
- Total Earnings: Sum of only successful transactions
- Today's Revenue: Only successful transactions from today
- This Week: Only successful transactions this week

### Success Criteria
- ✅ Only successful transactions displayed
- ✅ Failed payments excluded from earnings
- ✅ Pending payments excluded from earnings
- ✅ Revenue totals accurate

---

## Test 6: Reseller Order Split Payment

### Purpose
Ensure both owner and reseller transactions verified before crediting

### Setup
- Create reseller product (owner vendor + reseller vendor)
- Note both vendors' wallet_balance

### Steps
```
1. Purchase reseller product
2. Complete payment
3. Check both owner and reseller balances
```

### Expected Results

**Before webhook:**
```sql
-- Both transactions should be pending
SELECT vendor_id, status, vendor_earning
FROM transactions
WHERE order_id = [order_id];

Expected:
- Row 1: owner_vendor_id, status='pending'
- Row 2: reseller_vendor_id, status='pending'

-- Neither balance updated
SELECT id, wallet_balance FROM vendors WHERE id IN ([owner_id], [reseller_id]);

Expected: Both balances UNCHANGED
```

**After webhook:**
```sql
-- Both transactions successful
SELECT vendor_id, status, verified_at
FROM transactions
WHERE order_id = [order_id];

Expected:
- Both rows: status='successful', verified_at NOT NULL

-- Both balances updated
SELECT id, wallet_balance FROM vendors WHERE id IN ([owner_id], [reseller_id]);

Expected: Both balances INCREASED by their respective earnings
```

### Success Criteria
- ✅ Both transactions created as pending
- ✅ Neither balance updated before webhook
- ✅ Both transactions marked successful after webhook
- ✅ Both balances updated correctly

---

## Test 7: Admin Dashboard Revenue

### Purpose
Ensure admin sees accurate platform revenue (commissions)

### Steps
```
1. Login to admin dashboard
2. Check "Total Revenue" stat
3. Check "Transactions Today" count
```

### Expected Results
```sql
-- Admin dashboard should show:
SELECT SUM(commission_amount) as platform_revenue
FROM transactions
WHERE status = 'successful' 
  AND payment_status = 'completed';

-- Today's transaction count:
SELECT COUNT(*) 
FROM transactions
WHERE DATE(created_at) = CURDATE()
  AND status = 'successful';
```

### Success Criteria
- ✅ Platform revenue excludes pending/failed
- ✅ Transaction counts accurate
- ✅ Commission totals match successful payments only

---

## Common Issues & Troubleshooting

### Issue: Vendor balance not updating after webhook

**Check:**
1. Laravel logs: `tail -f storage/logs/laravel.log`
2. Look for: `"Moolre webhook received"`
3. Check transaction status in database
4. Verify webhook secret validation passed

**Solution:**
- Check `.env` credentials
- Verify webhook URL accessible from Moolre
- Check Laravel queue is running if using queues

### Issue: Balance updated before payment

**This is the BUG we fixed!**

**Check:**
1. Database transaction status immediately after checkout
2. Should be 'pending', not 'successful'

**If still happening:**
- Clear Laravel cache: `php artisan config:clear`
- Check code version matches updated files
- Verify migrations ran: `php artisan migrate:status`

### Issue: Duplicate balance credits

**Check:**
1. Count transactions for order:
   ```sql
   SELECT COUNT(*) FROM transactions WHERE order_id = [order_id];
   ```
2. Should be 1 for regular order, 2 for reseller order
3. Check Laravel logs for idempotency warnings

**Solution:**
- Ensure `completeOrder()` has idempotency check
- Verify `payment_reference` field is indexed

---

## Monitoring in Production

### Key Metrics to Track

1. **Pending Transactions** (should not accumulate)
   ```sql
   SELECT COUNT(*), MAX(created_at) as oldest
   FROM transactions
   WHERE status = 'pending';
   ```
   If count keeps growing or oldest > 24 hours, investigate

2. **Failed Verification Rate**
   ```sql
   SELECT 
       COUNT(*) as total,
       SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END) as successful,
       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
   FROM transactions
   WHERE DATE(created_at) >= CURDATE() - INTERVAL 7 DAY;
   ```

3. **Webhook Processing Time**
   Check Laravel logs for duration between:
   - "Moolre webhook received"
   - "Moolre webhook: order completed successfully"

### Alerts to Set Up

1. **Stale Pending Transactions**
   - Alert if pending transactions > 1 hour old
   - Indicates webhook not received or failed

2. **High Failure Rate**
   - Alert if failed transactions > 10% of total
   - Indicates gateway issues

3. **Duplicate Processing Attempts**
   - Alert on "Payment already completed" log entries
   - Monitor for idempotency issues

---

## Rollback Procedure

If critical issues found:

### Emergency: Manually Verify Pending Payments

```php
// Run in tinker: php artisan tinker

use App\Models\Order;
use App\Models\Transaction;
use App\Services\PaymentService;

$paymentService = app(PaymentService::class);

// Find pending transactions older than 1 hour
$pendingOrders = Order::where('payment_status', 'pending')
    ->where('created_at', '<', now()->subHour())
    ->get();

foreach ($pendingOrders as $order) {
    echo "Checking order {$order->id} - {$order->payment_reference}\n";
    
    // Verify with gateway
    $verification = $paymentService->verifyPayment($order->payment_reference);
    
    if ($verification['success']) {
        echo "  ✓ Payment successful, completing order\n";
        $paymentService->completeOrder($order);
    } else {
        echo "  ✗ Payment failed/pending\n";
    }
}
```

---

## Sign-Off Checklist

Before marking as production-ready:

- [ ] All 7 tests passed
- [ ] No vendor balances updated before verification
- [ ] Webhook idempotency working
- [ ] Failed payments don't credit balances
- [ ] Dashboards show only successful payments
- [ ] Reseller split payments work correctly
- [ ] Admin revenue accurate
- [ ] Monitoring in place
- [ ] Team trained on new flow
- [ ] Documentation updated

---

## Test Results Template

```
Test Date: __________
Tester: __________
Environment: [ ] Development [ ] Staging [ ] Production

Test 1 - Successful Payment:     [ ] Pass [ ] Fail
Test 2 - Failed Payment:          [ ] Pass [ ] Fail
Test 3 - Idempotency:             [ ] Pass [ ] Fail
Test 4 - Signature Verification:  [ ] Pass [ ] Fail
Test 5 - Dashboard Accuracy:      [ ] Pass [ ] Fail
Test 6 - Reseller Split:          [ ] Pass [ ] Fail
Test 7 - Admin Revenue:           [ ] Pass [ ] Fail

Issues Found:
_____________________________________________
_____________________________________________

Overall Status: [ ] APPROVED [ ] NEEDS WORK

Approved By: __________  Date: __________
```
