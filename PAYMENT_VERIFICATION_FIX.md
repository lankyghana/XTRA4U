# Payment Verification Fix - Critical Business Logic Update

## Problem Statement

**CRITICAL ISSUE**: Vendor balances were being credited on payment INITIATION instead of payment VERIFICATION, causing:
- Unsuccessful payments showing as "paid" in vendor dashboards
- Vendor balances updating before payment gateway confirms success
- No distinction between pending, successful, failed, and abandoned payments

## Solution Overview

Implemented proper payment state management with verification flow:
- **pending** → Payment initiated, awaiting verification
- **successful** → Payment verified by gateway, vendor balance credited
- **failed** → Payment verification failed
- **abandoned** → User didn't complete payment

## Changes Made

### 1. Database Migrations

#### Migration: `2025_12_21_000001_add_status_to_transactions_table.php`
Added to `transactions` table:
- `status` enum: pending, successful, failed, abandoned
- `payment_reference` string (indexed) for idempotency
- `verified_at` timestamp for audit trail

#### Migration: `2025_12_21_000002_add_pending_balance_to_vendors_table.php`
Added to `vendors` table:
- `pending_balance` decimal for unverified payments (future use)

**Run**: `php artisan migrate` ✅ COMPLETED

### 2. Model Updates

#### `app/Models/Transaction.php`
```php
// Status constants
const STATUS_PENDING = 'pending';
const STATUS_SUCCESSFUL = 'successful';
const STATUS_FAILED = 'failed';
const STATUS_ABANDONED = 'abandoned';

// Helper methods
isPending()
isSuccessful()
isFailed()
isAbandoned()
```

#### `app/Models/Vendor.php`
- Added `pending_balance` to fillable fields
- Added decimal casts for wallet balances

### 3. Core Service Updates

#### `app/Services/PaymentService.php`

**BEFORE**: 
```php
initiatePayment() {
    // Created order
    completeOrder($order); // ❌ WRONG: Updated balance immediately
}
```

**AFTER**:
```php
initiatePayment() {
    // Create order
    createPendingTransaction($order); // ✅ Only creates pending transaction
    // Redirect to payment gateway
}
```

**Key Changes**:

1. **initiatePayment()**: Now only creates transactions with `status='pending'`, does NOT credit balances
2. **createPendingTransaction()**: New method to create pending transactions
3. **completeOrder()**: Added idempotency check to prevent duplicate processing
4. **completeRegularOrder()**: Updates existing pending transactions to successful, THEN credits balance
5. **completeResellerOrder()**: Same pattern for split payments

**New Methods**:
- `verifyPayment($reference)`: Verify with gateway before completing
- `markTransactionFailed($order, $reason)`: Mark as failed
- `markTransactionAbandoned($order)`: Mark as abandoned

### 4. Payment Callback Updates

#### `app/Http/Controllers/PaymentCallbackController.php`

**BEFORE**:
```php
handle() {
    $verification = verifyPayment();
    completeOrder(); // After verification
}
```

**AFTER**:
```php
handle() {
    // 1. Find order FIRST
    // 2. Check if already completed (idempotency)
    // 3. VERIFY with gateway
    // 4. If failed, mark as failed
    // 5. If successful, complete order
}
```

### 5. Webhook Handler Updates

#### `app/Http/Controllers/MoolreWebhookController.php`

**Major Changes**:
- Proper webhook secret verification (Moolre sends secret IN payload)
- Idempotency check (prevent duplicate processing)
- Calls `PaymentService::completeOrder()` for successful payments
- Calls `PaymentService::markTransactionFailed()` for failed payments
- Comprehensive logging

#### `app/Services/MoolrePaymentService.php`

**Updated `verifyWebhook()`**:
- Accepts secret as parameter (from webhook payload)
- Validates secret format
- Checks for required fields
- Returns boolean with logging

## Payment Flow (New)

### Successful Payment
```
1. User submits checkout form
   ↓
2. CheckoutController::checkout()
   ↓
3. PaymentService::initiatePayment()
   - Creates Order with payment_status='pending'
   - Creates Transaction with status='pending'
   - NO balance update
   ↓
4. MoolrePaymentService::initiatePayment()
   - Generates payment link
   - Returns checkout_url
   ↓
5. User redirected to Moolre payment page
   ↓
6. User completes payment on Moolre
   ↓
7. Moolre sends webhook to /webhooks/moolre/payment
   ↓
8. MoolreWebhookController::handlePaymentWebhook()
   - Verifies webhook secret
   - Checks idempotency (already completed?)
   - Calls PaymentService::completeOrder()
   ↓
9. PaymentService::completeOrder()
   - Updates Transaction: status='successful', verified_at=now()
   - Updates Order: payment_status='completed'
   - Credits vendor wallet_balance ✅ ONLY NOW
   - Sends notifications
   ↓
10. User redirected to success page
```

### Failed Payment
```
1-6. Same as successful flow
   ↓
7. Moolre sends webhook with status='failed'
   ↓
8. MoolreWebhookController::handlePaymentWebhook()
   - Calls PaymentService::markTransactionFailed()
   ↓
9. PaymentService::markTransactionFailed()
   - Updates Transaction: status='failed'
   - Updates Order: payment_status='failed'
   - NO balance update ✅ Correct
```

## Testing Checklist

### Before Deploying to Production

- [ ] Test successful payment flow
  - Verify transaction created with status='pending'
  - Verify balance NOT credited until webhook received
  - Verify balance credited after successful webhook

- [ ] Test failed payment flow
  - Verify transaction marked as 'failed'
  - Verify balance NOT credited

- [ ] Test idempotency
  - Send duplicate webhook callbacks
  - Verify balance only credited once

- [ ] Test webhook signature verification
  - Send webhook with invalid secret
  - Verify rejected with 403

- [ ] Check vendor dashboard
  - Verify only 'successful' transactions shown
  - Verify failed payments don't appear as earnings

## Database Queries to Update

### Current Problem
Vendor dashboards likely show ALL transactions, including pending/failed:

```php
// ❌ WRONG - Shows all transactions
$transactions = Transaction::where('vendor_id', $vendorId)->get();
```

### Solution
Filter by successful status:

```php
// ✅ CORRECT - Shows only verified payments
$transactions = Transaction::where('vendor_id', $vendorId)
    ->where('status', Transaction::STATUS_SUCCESSFUL)
    ->get();
```

**Action Required**: Update all dashboard queries to filter by `status='successful'`

## Files Changed

1. ✅ `database/migrations/2025_12_21_000001_add_status_to_transactions_table.php`
2. ✅ `database/migrations/2025_12_21_000002_add_pending_balance_to_vendors_table.php`
3. ✅ `app/Models/Transaction.php`
4. ✅ `app/Models/Vendor.php`
5. ✅ `app/Services/PaymentService.php`
6. ✅ `app/Http/Controllers/PaymentCallbackController.php`
7. ✅ `app/Http/Controllers/MoolreWebhookController.php`
8. ✅ `app/Services/MoolrePaymentService.php`

## Next Steps

### Immediate (Required Before Production)

1. **Update Dashboard Queries**
   - Find all Transaction queries in vendor/admin dashboards
   - Add `where('status', Transaction::STATUS_SUCCESSFUL)` filter
   - Test dashboard displays correct data

2. **Test Payment Flow**
   - Test successful payment end-to-end
   - Test failed payment handling
   - Test webhook idempotency

3. **Add Monitoring**
   - Set up alerts for failed payment verifications
   - Monitor pending transactions that never complete
   - Track abandoned payments

### Future Improvements

1. **Abandoned Payment Cleanup**
   - Create scheduled job to mark pending payments as abandoned after 24 hours
   - Send reminder notifications for incomplete payments

2. **Pending Balance Feature**
   - Show vendors their pending balance (unverified payments)
   - Provide transparency about payment verification delays

3. **Transaction History**
   - Add status column to transaction tables
   - Show payment status in transaction history
   - Add filters for pending/successful/failed

4. **Webhook Replay**
   - Store webhook payloads for debugging
   - Add admin interface to replay webhooks for failed verifications

## Critical Reminders

⚠️ **NEVER credit vendor balance before payment verification**
⚠️ **ALWAYS check order.payment_status before completing twice (idempotency)**
⚠️ **ALWAYS verify webhook signatures**
⚠️ **ALWAYS log payment verification results**

## Rollback Plan

If issues arise after deployment:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check pending transactions: `SELECT * FROM transactions WHERE status='pending'`
3. If needed, manually verify and complete: Call `PaymentService::verifyPayment()` then `completeOrder()`
4. For emergency, can temporarily disable webhook signature verification (NOT RECOMMENDED)

## Summary

This fix ensures vendor balances update ONLY after payment gateway confirms success. Previous behavior credited balances immediately on payment initiation, causing financial inconsistencies. The new flow properly tracks payment states (pending → successful/failed/abandoned) and implements idempotency to prevent duplicate processing.

**Status**: ✅ Code complete, migrations run, ready for testing
**Risk Level**: High (financial transactions)
**Testing Required**: Comprehensive end-to-end testing before production
