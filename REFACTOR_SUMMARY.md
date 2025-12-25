# AFA & Order Payment Architecture Refactor Summary

## Overview
Successfully refactored the Option 2 architecture to cleanly separate AFA registrations and product orders while sharing unified payment and gateway infrastructure. The refactor introduces polymorphic relationships, dedicated services, and improved code organization.

## Architecture Changes

### Before
- Mixed AFA and Order logic in `PaymentService`
- Transactions always linked to `Order` via `order_id`
- Payment completion logic scattered across controllers
- No clear separation between domain models

### After
- **Polymorphic Transactions**: Transactions can belong to either `Order` or `AfaRegistration`
- **Dedicated Services**: `AfaPaymentService` for AFA-specific logic, `PaymentService` for shared infrastructure
- **Unified Payment Flow**: Both domains use `PaymentRequest` DTO and `GatewayManager`
- **Clean Separation**: Order and AFA remain independent at the model level

## Files Created

### 1. `app/Services/PaymentRequest.php`
**Purpose**: Data Transfer Object for unified payment handling

**Key Features**:
- Factory methods: `forOrder()` and `forAfaRegistration()`
- Standardized interface for payment gateway selection
- Separates payment intent from domain models

**Usage**:
```php
$paymentRequest = PaymentRequest::forAfaRegistration($registration, $email, $amount, $callbackUrl);
$result = $this->paymentService->initiateGenericPayment($paymentRequest);
```

### 2. `app/Services/AfaPaymentService.php`
**Purpose**: Handle AFA-specific payment completion logic

**Key Responsibilities**:
- Complete AFA registration after payment verification
- Create polymorphic transaction records
- Update vendor wallet balances
- Send notifications (email, SMS, in-app)
- Handle reseller earnings distribution

**Key Methods**:
```php
// Main entry point
completeRegistration(AfaRegistration $registration): void

// Create polymorphic transaction
createAfaTransaction(AfaRegistration $registration, int $vendorId, string $paymentStatus, float $earning): Transaction

// Update wallet balances
updateVendorWallets(AfaRegistration $registration): void

// Send notifications
sendNotifications(AfaRegistration $registration): void
```

### 3. Database Migrations

#### `2025_12_25_030000_add_polymorphic_support_to_transactions.php`
- Adds `transactionable_type`, `transactionable_id`, `payment_type` columns
- Creates polymorphic index for performance
- Backfills existing records as `Order` type

#### `2025_12_25_033212_make_order_id_nullable_in_transactions_table.php`
- Makes `order_id` nullable
- Enables transactions without orders (for AFA registrations)

## Files Modified

### 1. `app/Models/Transaction.php`
**Changes**:
- Added polymorphic `transactionable()` relationship
- Preserved legacy `order()` relationship for backward compatibility
- Added scopes: `ofType()`, `orders()`, `afaRegistrations()`

**New Relationships**:
```php
public function transactionable(): MorphTo
public function scopeOrders($query)
public function scopeAfaRegistrations($query)
```

### 2. `app/Models/Order.php`
**Changes**:
- Added `transactionRecords()` morphMany relationship
- Kept legacy `transactions()` hasMany for existing code

**New Relationship**:
```php
public function transactionRecords(): MorphMany
{
    return $this->morphMany(Transaction::class, 'transactionable');
}
```

### 3. `app/Models/AfaRegistration.php`
**Changes**:
- Added `transactions()` morphMany relationship
- Fixed duplicate `resellerVendor()` method

**New Relationship**:
```php
public function transactions(): MorphMany
{
    return $this->morphMany(Transaction::class, 'transactionable');
}
```

### 4. `app/Models/VendorNotification.php`
**Changes**:
- Added `TYPE_AFA_REGISTRATION` constant

### 5. `app/Services/PaymentService.php`
**Changes**:
- Added `upsertTransaction()` method for polymorphic records
- Preserved `upsertOrderTransaction()` for backward compatibility

**New Method**:
```php
public function upsertTransaction(
    string $transactionableType,
    int $transactionableId,
    string $paymentType,
    int $vendorId,
    string $recipientPhone,
    float $amount,
    float $commissionAmount,
    float $vendorEarning,
    string $paymentStatus
): Transaction
```

### 6. `app/Http/Controllers/AfaRegistrationController.php`
**Changes**:
- Refactored `paymentCallback()` to use `AfaPaymentService`
- Removed complex inline payment completion logic
- Cleaner, more maintainable code

**Before**:
```php
// 50+ lines of payment completion, wallet updates, notifications
```

**After**:
```php
$this->afaPaymentService->completeRegistration($registration);
```

### 7. `app/Http/Middleware/ContentSecurityPolicy.php`
**Changes**:
- Fixed invalid port wildcard syntax
- Changed `http://localhost:*` to explicit ports `http://localhost:8000 http://127.0.0.1:8000`

### 8. `resources/views/afa/register.blade.php`
**Changes**:
- Changed form action from absolute to relative URL
- Prevents CSP cross-origin violations

**Before**: `{{ route('afa.store', ...) }}`  
**After**: `/afa/store/{{ $vendor->vendor_code }}`

### 9. `app/Services/MoolrePaymentService.php`
**Changes**:
- Added `connectTimeout(30)` to HTTP client
- Added IPv4 preference via `CURLOPT_IPRESOLVE`
- Enhanced error messages in development

## Database Schema

### Transactions Table
```
id                     BIGINT      PRIMARY KEY
transactionable_type   VARCHAR     NULLABLE (App\Models\Order or App\Models\AfaRegistration)
transactionable_id     BIGINT      NULLABLE
order_id               BIGINT      NULLABLE (legacy, preserved for backward compatibility)
vendor_id              BIGINT      NOT NULL
recipient_phone        VARCHAR     NOT NULL
amount                 DECIMAL     NOT NULL
commission_amount      DECIMAL     NOT NULL
vendor_earning         DECIMAL     NOT NULL
payment_status         VARCHAR     NOT NULL
payment_type           VARCHAR     NULLABLE (order, afa_registration)
timestamp              DATETIME    NULLABLE
created_at             TIMESTAMP   NOT NULL
updated_at             TIMESTAMP   NOT NULL

INDEX: transactions_transactionable_index (transactionable_type, transactionable_id)
```

## Payment Flow

### AFA Registration Payment Flow
1. User submits AFA form → `AfaRegistrationController::store()`
2. Creates `PaymentRequest::forAfaRegistration()`
3. `PaymentService::initiateGenericPayment()` → `GatewayManager::genericPayment()`
4. Active gateway (e.g., Moolre) processes payment
5. User redirected to payment gateway
6. Gateway redirects back → `AfaRegistrationController::paymentCallback()`
7. `PaymentService::checkPaymentStatus()` verifies payment
8. `AfaPaymentService::completeRegistration()`:
   - Updates registration status
   - Creates polymorphic transaction
   - Updates vendor wallets
   - Sends notifications
9. Redirects to success page

### Product Order Payment Flow (Unchanged)
1. User submits order → `PurchaseController::purchase()`
2. Creates `PaymentRequest::forOrder()`
3. `PaymentService::initiateGenericPayment()` → same unified flow
4. Callback → `PurchaseController::paymentCallback()`
5. `PaymentService::completeOrder()` (existing logic)

## Benefits

### 1. **Clean Domain Separation**
- AFA and Order models remain independent
- No cross-domain dependencies
- Easier to reason about each domain

### 2. **Unified Infrastructure**
- Both use the same payment gateways
- Single `GatewayManager` for gateway selection
- Consistent payment verification logic

### 3. **Polymorphic Flexibility**
- Transactions can reference any payable model
- Future services (e.g., Bill Payment) can easily integrate
- No need for separate transaction tables

### 4. **Backward Compatibility**
- Existing product orders continue to work
- Legacy `order_id` column preserved
- Migration backfills existing records

### 5. **Improved Maintainability**
- Dedicated services reduce controller bloat
- Clear separation of concerns
- Easier to test and debug

### 6. **Better Vendor Reporting**
- Single source of truth for all earnings
- Easy to query all vendor transactions: `$vendor->transactions`
- Filterable by payment type: `Transaction::afaRegistrations()->get()`

## Testing

All 23 tests passing:
```
Tests:    23 passed (91 assertions)
Duration: 8.98s
```

### Test Coverage
- ✅ Order creation and payment
- ✅ AFA registration and payment
- ✅ Vendor dashboard earnings
- ✅ Vendor withdrawals
- ✅ Affiliate order handling
- ✅ Reseller order handling
- ✅ Email notifications
- ✅ Wallet balance updates

## Security Improvements

### 1. **CSP Fixed**
- Valid syntax (explicit ports instead of wildcards)
- Relative form URLs prevent cross-origin issues
- Better protection against XSS attacks

### 2. **Backend-Initiated Payments**
- No direct payment gateway calls from frontend
- All payment flows go through backend services
- Prevents tampering with payment amounts

### 3. **Idempotency**
- Payment callbacks check for duplicate processing
- Wallet updates only happen once
- Notifications only sent once

## Future Enhancements

### 1. **Add More Payment Types**
```php
// Example: Bill Payment service
$paymentRequest = PaymentRequest::forBillPayment($bill, $email, $amount, $callbackUrl);
$result = $this->paymentService->initiateGenericPayment($paymentRequest);

// Transaction automatically linked to Bill model
$bill->transactions()->create([...]);
```

### 2. **Unified Vendor Dashboard**
```php
// Get all vendor earnings across all services
$allTransactions = $vendor->transactions()->with('transactionable')->get();

// Filter by type
$afaEarnings = $vendor->transactions()->afaRegistrations()->sum('vendor_earning');
$orderEarnings = $vendor->transactions()->orders()->sum('vendor_earning');
```

### 3. **Advanced Reporting**
```php
// Daily breakdown by payment type
Transaction::selectRaw('payment_type, DATE(created_at) as date, SUM(amount) as total')
    ->groupBy('payment_type', 'date')
    ->get();
```

## Migration Instructions

### For Existing Deployments
1. Backup the database
2. Run migrations: `php artisan migrate`
3. Verify existing orders still work
4. Test AFA registration flow
5. Monitor logs for any errors

### Rollback (if needed)
```bash
php artisan migrate:rollback --step=2
```

This will revert:
- The nullable `order_id` change
- The polymorphic columns

**Note**: Transactions created with polymorphic relationships will lose their linkage during rollback.

## Maintenance Notes

### When Adding New Payment Types
1. Create model with `transactions()` morphMany relationship
2. Create dedicated service (e.g., `BillPaymentService`)
3. Add factory method to `PaymentRequest`
4. Add constant to `Transaction::PAYMENT_TYPE_*`
5. Add notification type to `VendorNotification::TYPE_*`

### When Adding New Payment Gateways
1. Implement gateway service (extends `PaymentGatewayInterface`)
2. Add to `GatewayManager::$gateways` array
3. Add capabilities to `payment_gateway_configs` table
4. Test with both Order and AFA flows

## Conclusion

The refactor successfully achieves:
- ✅ Clean separation of AFA and Order domains
- ✅ Unified payment infrastructure
- ✅ Polymorphic transaction support
- ✅ Backward compatibility
- ✅ All tests passing
- ✅ Improved maintainability
- ✅ Better vendor reporting
- ✅ Foundation for future payment types

The architecture is now robust, extensible, and ready for production deployment.
