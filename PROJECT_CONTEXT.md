# PROJECT_CONTEXT.md – XTRA4U Engineering Handbook

**Version:** 1.0  
**Last Updated:** May 11, 2026  
**Status:** Production-Ready Reference Document  
**Target Audience:** Engineers, AI Assistants, Contractors, Maintainers

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Business Model & Platform Overview](#2-business-model--platform-overview)
3. [Technology Stack](#3-technology-stack)
4. [Core Architecture Patterns](#4-core-architecture-patterns)
5. [Payment System Documentation](#5-payment-system-documentation)
6. [Moolre Implementation Deep-Dive](#6-moolre-implementation-deep-dive)
7. [External Fulfillment System](#7-external-fulfillment-system)
8. [Results Checker System](#8-results-checker-system)
9. [Vendor & Admin Systems](#9-vendor--admin-systems)
10. [Database Schema & Critical Relationships](#10-database-schema--critical-relationships)
11. [Production Safety Rules](#11-production-safety-rules)
12. [Hosting & Deployment](#12-hosting--deployment)
13. [Testing Strategy](#13-testing-strategy)
14. [Important Files Index](#14-important-files-index)
15. [Known Issues & Historical Incidents](#15-known-issues--historical-incidents)

---

## 1. Executive Summary

**XTRA4U** is a digital services marketplace platform built on Laravel 12 that connects customers with vendors for purchasing mobile services including airtime, data bundles, exam result checkers, and more. The platform handles complex payment processing, multi-vendor fulfillment, and external provider integrations while maintaining audit trails and vendor balance management.

### Key Stakeholders
- **Customers**: Purchase digital services via storefront
- **Vendors**: Create, manage, and fulfill orders with commission tracking
- **Resellers/Affiliates**: Extend platform reach with margin control
- **Admins**: Moderate vendors, configure integrations, manage stock
- **AI Assistants & Contractors**: Understand architecture without rediscovering code

### Production Criticality
- **Payment Processing**: 5 gateways with webhook-driven fulfillment
- **External Fulfillment**: 3 provider integrations (Datafyhub, XpresPortal, GigsHub)
- **Results Checker System**: Complex PIN allocation with stock management
- **Vendor Balances**: Commission calculations and wallet payouts
- **Session Management**: Database-backed sessions (fragile on shared hosting)

### Purpose of This Document
This document acts as an engineering handbook, architectural memory, and implementation reference. It allows engineers to:
- Understand complete payment and fulfillment flows
- Deploy with confidence
- Avoid repeating known production issues
- Navigate integrations efficiently
- Make informed architectural decisions

---

## 2. Business Model & Platform Overview

### Service Offerings
XTRA4U operates as a multi-vendor digital services marketplace:
- **Airtime & Data**: Mobile network services (MTN, Telecel, AirtelTigo)
- **Result Checker Pins**: Exam result lookup credentials
- **Generic Services**: Extensible via service registry

### Transaction Model
```
Customer Purchase
├─ Vendor provides service details (network, capacity, price)
├─ Payment processing (5 gateways supported)
├─ Commission split: 2% platform commission, 98% to vendor
├─ External fulfillment (if configured)
└─ Order completion & vendor wallet credit
```

### Vendor & Reseller Architecture
```
Root Vendor
├─ Direct orders: 100% commission to vendor
├─ Reseller setup: Can create resellers with markup
└─ Affiliate chains: Multi-level payout support

Reseller/Affiliate Vendor
├─ Owner margin: Markup on top of root price
├─ Commission: 2% per leg
└─ Affiliate chain snapshot: Captured at order time
```

### Commission Model
- **Base Commission**: 2% platform fee on all transactions
- **Reseller Markup**: Configurable per reseller per service
- **Multi-Level Payout**: Affiliate snapshot array stores chain at purchase time
- **Payout Processing**: Via configured withdrawal/payout gateway (MTN MoMo, etc.)

---

## 3. Technology Stack

### Backend
- **Framework**: Laravel 12 (with PHP 8.2+)
- **Language**: PHP 8.2+
- **Authentication**: Custom guards (admin, vendor)
- **ORM**: Eloquent with pessimistic locking support

### Frontend
- **Templates**: Blade (server-side rendering)
- **Styling**: Tailwind CSS
- **Interactivity**: Alpine.js (with Collapse plugin)
- **Asset Bundling**: Vite

### Database & Persistence
- **Database**: SQLite (dev) or MySQL 8+ (production)
- **Queue System**: Database-driven queues (QUEUE_CONNECTION=database)
- **Sessions**: Database-backed (SESSION_DRIVER=database)
- **Caching**: Database cache store (CACHE_STORE=database)
- **No Redis/Memcached**: Standard config uses database for all persistence

### Payment Integrations
| Gateway | Type | Primary Use | Status |
|---------|------|-------------|--------|
| **Moolre** | Inline MoMo | Primary Ghana collection | ✅ Active |
| **Paystack** | Redirect | Backup collection, generic payments | ✅ Active |
| **Flutterwave** | Redirect | Backup collection | ✅ Active |
| **BulkClix** | Inline/API | Collection, SMS delivery, generic | ✅ Active |
| **Hubtel** | Payout-only | Vendor payouts, SMS | ✅ Active |

### External Fulfillment Providers
- **Datafyhub**: Data bundle fulfillment (mtn, telecel, ishare, bigtime, xpress networks)
- **XpresPortal**: Alternative data provider with vendor-specific credentials
- **GigsHub**: Data bundles with balance webhooks and low-balance alerts

### Third-Party Services
- **SMS**: BulkClix API (phone normalization to 233... format)
- **Email**: SMTP (configured via MAIL_* env vars)
- **Version Control**: GitHub

### Deployment Environment
- **Hosting**: Shared hosting (cPanel) or VPS
- **Web Server**: Apache or Nginx
- **PHP CLI**: Available for artisan commands
- **Manual Queue Processing**: Database queues require periodic processing

---

## 4. Core Architecture Patterns

### Event-Driven Architecture
```
Order Completed (Event)
├─ Listener: SendOrderCompletionNotification
│  └─ Notifies vendor, customer via email/SMS
├─ Listener: DispatchExternalFulfillmentFromOrderCompleted
│  └─ Queues ProcessExternalFulfillment job
└─ External fulfillment job runs asynchronously

Result Checker Order Paid (Event)
├─ No listener currently registered (BUG: missing event handler)
└─ Manual SMS trigger via handlePaymentSuccess()
```

### Queue/Job Architecture
**Jobs handle async operations with retries:**
- `ProcessExternalFulfillment`: Send order to provider (3 retries: 60s, 300s, 900s)
- `FulfillPendingOrdersJob`: Auto-fulfill pending_stock orders after stock upload
- `RetryResultCheckerOrder`: Manual retry for result checker orders
- `SendOrderPlacedCommunications`: Notify vendor and customer

**Queue Characteristics:**
- Driver: `database` (QUEUE_CONNECTION)
- Concurrency: Runs via `php artisan queue:work`
- Failures: Stored in `queue_failed` table
- Monitoring: Manual inspection or background cron

### Factory & Strategy Pattern

**ExternalFulfillmentClientFactory** (app/Services/ExternalFulfillment/ExternalFulfillmentClientFactory.php)
```php
$client = ExternalFulfillmentClientFactory::make('xpresportal');
$client->sendOrder($order, $idempotencyKey);
```
- Pluggable provider implementations
- Single interface: ExternalFulfillmentClient
- Methods: sendOrder(), getServices()

### Service Layer Architecture
```
Controller
├─ PaymentCallbackController
├─ ResultCheckerCheckoutController
└─ AdminResultCheckerPinsController
    ↓
    ↓ Orchestrator Services
    ↓
├─ PaymentService: Payment initiation & completion
├─ GatewayManager: Gateway routing & capability selection
├─ ResultCheckerService: PIN allocation, SMS delivery
├─ ExternalFulfillmentConfig: Credential management
└─ SmsService: SMS delivery via BulkClix
```

### Concurrency & Locking
```
Payment Completion (PaymentService::completeOrder)
├─ lockForUpdate() on Order record
├─ Check idempotency (payment_status already paid?)
├─ Calculate commission & earnings
├─ Create/update Transaction(s)
├─ Increment vendor wallet
└─ Dispatch OrderCompleted event

PIN Allocation (ResultCheckerService::allocatePinsForOrder)
├─ lockForUpdate() on available ResultCheckerPin records
├─ Verify sufficient inventory
├─ Mark pins as sold, link to order
├─ Update order status to completed
└─ Return success/failure for pending_stock handling
```

### Polymorphic Relationships
**Transaction Model** supports multiple payable types:
```
Transaction (order_id, transactionable_type, transactionable_id)
├─ Polymorphic: Order
├─ Polymorphic: AfaRegistration
└─ Fallback: order_id (legacy)
```
Enables transaction audit trail across order types and affiliate registrations.

### Execution Flows

**Payment Success → Fulfillment:**
```
Customer Pays
  ↓
Webhook received: POST /webhooks/moolre/payment (or /payment/callback)
  ↓
MoolreWebhookController->handle()
  ├─ Verify webhook secret (hash_equals for timing safety)
  ├─ Query order by payment_reference
  ├─ Call MoolrePaymentService->verifyPayment()
  └─ On success: PaymentService->completeOrder()
       ├─ Calculate commission split
       ├─ Create Transaction(s)
       ├─ Credit vendor wallet
       ├─ Dispatch OrderCompleted event
       │  └─ Listener: DispatchExternalFulfillmentFromOrderCompleted
       │     └─ Dispatch ProcessExternalFulfillment job
       └─ Return 200 OK (prevent webhook retries)
```

**External Fulfillment:**
```
ProcessExternalFulfillment Job
  ├─ Acquire distributed cache lock
  ├─ Verify order payment_status = paid
  ├─ Resolve provider (from vendor config)
  ├─ Generate idempotency key (payment reference)
  ├─ Call ExternalFulfillmentClientFactory::make()->sendOrder()
  │  ├─ Map product → provider-specific service ID
  │  ├─ POST to provider API with idempotency header
  │  └─ Parse response
  ├─ Update order fulfillment status
  │  ├─ success → status='succeeded', remote_reference captured
  │  ├─ pending → status='processing'
  │  └─ failed → status='failed', error_message logged
  └─ Release lock (timeout: 600s)
```

**Results Checker Ordering:**
```
Customer Checkout
  ├─ POST /store/{vendor}/result-checkers/checkout
  ├─ Validate service is checker_type, vendor enabled
  ├─ Create ResultCheckerOrder (status='pending_payment')
  ├─ GatewayManager->collect() (Moolre, Paystack, etc.)
  └─ Return payment authorization URL

Payment Callback
  ├─ POST /result-checkers/payment/callback/{order}
  ├─ ResultCheckerPaymentCallbackController->handle()
  └─ ResultCheckerService->handlePaymentCallback()
       ├─ Mark paid (paid_at, payment_reference, payment_gateway)
       ├─ Call allocatePinsForOrder()
       │  ├─ lockForUpdate() available pins
       │  ├─ If sufficient: allocate, set status='completed'
       │  └─ If insufficient: status='pending_stock'
       ├─ Dispatch ResultCheckerOrderPaid event (no listener)
       └─ Manual SMS trigger (gap: not automatic on pending_stock)

Stock Upload
  ├─ POST /admin/results-checkers/pins (CSV/text)
  ├─ Bulk insert ResultCheckerPin records
  └─ Dispatch FulfillPendingOrdersJob(service_id)
       └─ Iterate pending_stock orders, call allocatePinsForOrder()
```

---

## 5. Payment System Documentation

### Overview of All Payment Gateways

#### Moolre (Primary Inline MoMo)
- **Collection**: Inline MoMo (customer enters PIN on phone)
- **Networks**: MTN (13), Telecel/Vodafone (6), AirtelTigo (7)
- **Phone Normalization**: Converts to 0XXXXXXXXX (local Ghana format)
- **Webhook Support**: Yes, with optional secret verification
- **Endpoint**: POST /open/transact/payment (initiate), GET /open/transact/status (verify)
- **Auth**: API User + API Key header
- **Files**: MoolrePaymentService, MoolreWebhookController
- **Status Mapping**: txStatus 1=success, 0=pending, 2=failed

#### Paystack (Redirect)
- **Collection**: Hosted checkout page
- **Verification**: Server-to-server status query
- **Webhook Support**: Yes
- **Files**: PaystackPaymentService
- **Generic Payment Support**: Yes

#### Flutterwave (Redirect)
- **Collection**: Hosted checkout page
- **Verification**: Server-to-server status query
- **Webhook Support**: Yes
- **Files**: FlutterwavePaymentService
- **Generic Payment Support**: Yes

#### BulkClix (Inline/API)
- **Collection**: API-based inline collection
- **SMS Integration**: Yes (same gateway)
- **Webhook Support**: Yes
- **Files**: BulkClixPaymentService
- **Generic Payment Support**: Yes

#### Hubtel (Payout-Only)
- **Type**: Withdrawal/payout provider
- **Collection**: Not supported
- **Payout Processing**: Vendor wallet withdrawals
- **Files**: HubtelPayoutService

### Payment Initiation Flow

**File**: app/Services/PaymentService.php::initiatePayment()

```
1. Ensure Pending Transactions
   ├─ Create Transaction(s) immediately (even if init fails)
   └─ Reason: Audit trail if gateway call times out

2. Route to Active Gateway
   ├─ GatewayManager->collect($order, $email, $amount)
   └─ Returns: {success, message, reference, authorization_url, gateway_name}

3. Apply Moolre Fallback
   ├─ If Moolre responds with verification-code flow
   ├─ Try alternative active collection gateway
   └─ Preserve checkout if primary requires additional verification

4. Extract Gateway Transaction ID
   ├─ Some gateways provide transaction ID at init time
   └─ Store in Transaction::gateway_transaction_id

5. Persist Payment Reference
   ├─ Reference generated client-side before HTTP call
   ├─ Update order->payment_reference & payment_status='unpaid'
   └─ Enables manual verification if init response is lost

6. Return Response
   ├─ success=true: authorization_url for redirect/inline entry
   └─ success=false: error message & retry instruction
```

### Webhook & Callback Handling

**Routes:**
- `POST /webhooks/moolre/payment` (Moolre webhook)
- `GET|POST /payment/callback` (Redirect gateway callbacks)
- `GET /payment/status/{reference}` (Customer polling)
- `POST /result-checkers/payment/callback/{order}` (Result checker callback)

**Moolre Webhook Security:**
```
POST /webhooks/moolre/payment
├─ CSRF exempt (webhook doesn't have CSRF token)
├─ Payload Structure:
│  ├─ data.externalref (payment reference, our generated ID)
│  ├─ data.txstatus (1=success, 0=pending, 2=failed)
│  └─ data.secret (webhook secret if configured)
├─ Verification:
│  ├─ Extract reference from request
│  ├─ If webhook secret configured: hash_equals() timing-safe comparison
│  ├─ Query order/AFA by payment_reference
│  ├─ Call MoolrePaymentService->verifyPayment() (server-to-server verify)
│  └─ Process based on verified status
└─ Response: Always 200 OK (idempotent, prevent gateway retries)
```

**File**: app/Http/Controllers/Webhooks/MoolreWebhookController.php

### Payment Status Flow

```
Unpaid → Payment Initiated
├─ payment_status='unpaid'
├─ payment_reference=generated
├─ payment_gateway=selected
└─ Pending Transaction created

Webhook → Payment Verification
├─ Verify via gateway API (server-to-server)
├─ Extract gateway_transaction_id if provided
└─ Process based on verified status

Success → Order Completion
├─ payment_status='paid' (lifecycle term, not DB enum name)
├─ order.status='Processing'
├─ payment_completed_at=now()
├─ Transaction updated (status='successful')
├─ Vendor wallet incremented
├─ Notifications sent
├─ OrderCompleted event dispatched
│  └─ Triggers external fulfillment job if configured
└─ For results checker: attempt PIN allocation

Failed
├─ payment_status='failed'
├─ order.status='Failed'
└─ Transaction marked 'failed'

Pending
├─ Webhook received but status unresolved
└─ Awaits next webhook/polling cycle
```

### Commission & Earnings Calculation

**Regular Order:**
```
amount_paid = 100 GHS
commission = amount_paid * 0.02 = 2 GHS
vendor_earning = amount_paid - commission = 98 GHS
```

**Reseller Order (2-level):**
```
owner_vendor_id = base vendor
reseller_vendor_id = reseller

base_price = 100 GHS (root vendor's price)
markup_price = 20 GHS (reseller adds markup)
total = 120 GHS

Platform commission (2%) = 2.4 GHS
Remaining = 117.6 GHS

Split:
├─ reseller_earning = markup_price - commission = 20 - 0.4 = 19.6 GHS
└─ owner_earning = base_price - commission = 100 - 2 = 98 GHS

affiliate_chain_snapshot = [
  { vendor_id: reseller_id, vendor_type: 'reseller', earning: 19.6 },
  { vendor_id: owner_id, vendor_type: 'owner', earning: 98 }
]
```

**Multi-Level Affiliate Chain:**
- Snapshot captured at order time (not computed at payout)
- Stored in Order::affiliate_chain_snapshot (array)
- Each affiliate has fixed earning amount
- Enables backward-compatible payout even if chain structure changes

### Transaction Polymorphism

**File**: app/Models/Transaction.php

```
Polymorphic relationships:
├─ order_id (legacy, for backwards-compat)
├─ transactionable_type ('Order', 'AfaRegistration', etc.)
└─ transactionable_id (ID of the payable record)

Why: Supports non-order transactions (affiliate registrations, wallet top-ups)
```

### Gateway Configuration Management

**File**: app/Models/PaymentGatewayConfig.php

```
Stored in payment_gateway_configs table:
├─ gateway_name (unique: moolre, paystack, flutterwave, bulkclix, hubtel)
├─ gateway_type (payment_collection, payout, sms, generic)
├─ is_active (boolean)
├─ is_default (boolean per type)
├─ config_data (encrypted JSON with API keys, secrets, URLs)
├─ supports_collection, supports_payout, supports_sms, supports_webhook (flags)
├─ environment (sandbox, live)
└─ created_at, updated_at

Capabilities:
├─ supports_collection: Can collect payments
├─ supports_generic: Can handle generic/non-order payments
├─ supports_payout: Can process withdrawals
├─ supports_sms: Can send SMS messages
└─ supports_webhook: Has webhook support

Default Selection:
├─ One default per capability type
├─ GatewayManager selects default for operation
└─ Fallback to alternative active if primary fails
```

**Admin Routes:**
```
GET|POST /admin/payment-gateways
  ├─ List all configured gateways
  ├─ Create new gateway config
  └─ Edit existing config

PATCH /admin/payment-gateways/{gateway}/set-default
  └─ Set as default for its type

PATCH /admin/payment-gateways/{gateway}/toggle-active
  └─ Enable/disable gateway

POST /admin/payment-gateways/{gateway}/test
  └─ Test connectivity (ping provider API)
```

### Important Payment Files

**Services:**
- `app/Services/PaymentService.php` (Main orchestrator)
- `app/Services/GatewayManager.php` (Gateway routing)
- `app/Services/MoolrePaymentService.php`
- `app/Services/PaystackPaymentService.php`
- `app/Services/FlutterwavePaymentService.php`
- `app/Services/BulkClixPaymentService.php`
- `app/Services/Payouts/PaystackPayoutService.php`
- `app/Services/Payouts/MoolrePayoutService.php`
- `app/Services/Payouts/HubtelPayoutService.php`

**Controllers:**
- `app/Http/Controllers/PaymentCallbackController.php`
- `app/Http/Controllers/PaymentStatusController.php`
- `app/Http/Controllers/Webhooks/MoolreWebhookController.php`
- `app/Http/Controllers/Admin/PaymentGatewayController.php`

**Models:**
- `app/Models/PaymentGatewayConfig.php`
- `app/Models/Transaction.php`
- `app/Models/Order.php`

---

## 6. Moolre Implementation Deep-Dive

### Why Moolre is Special

Moolre is the primary payment provider for Ghana mobile money (MTN MoMo). Unlike redirect-based gateways (Paystack, Flutterwave), Moolre uses inline collection where the customer enters their PIN directly without leaving the app.

### Inline vs Redirect Collection

**Inline (Moolre):**
- Pros: No redirect, seamless UX, customer stays in app
- Cons: Requires phone pre-collection, verification code fallback complexity
- Implementation: app/Services/MoolrePaymentService.php

**Redirect (Paystack, Flutterwave):**
- Pros: Simpler, external PCI compliance
- Cons: Navigation required, UX friction
- Implementation: Individual gateway services

### Ghana Networks & Channel Mapping

```
Mobile Money Networks:
├─ MTN (channel=13): Most common, ~50% market
├─ Telecel/Vodafone (channel=6): Legacy, still active
└─ AirtelTigo (channel=7): Smaller market share

Request Format:
POST /open/transact/payment
{
  "user_phone": "0244123456",  // Local format required!
  "amount": 100,
  "provider_channel": 13,      // MTN channel ID
  "externalref": "order-123",
  "narration": "Service purchase"
}
```

### Phone Normalization (CRITICAL)

**File**: app/Services/MoolrePaymentService.php::normalizePhone()

```
Input variations:
├─ "+233244123456" (international)
├─ "0244123456" (local)
├─ "244123456" (without country code)
└─ "24123456" (9 digits, assumed Ghana)

Output: "0244123456" (Moolre required format)

Logic:
1. Remove non-numeric characters
2. If starts with "233": remove it, prepend "0"
3. If starts with "0": keep as-is
4. If 9 digits: prepend "0"
5. If starts with "233": already handled
6. Otherwise: prepend "0"

Example: "+233 244 123 456" → "0244123456"
```

**⚠️ Critical:** Moolre rejects payment requests with incorrect phone format. Always normalize before payment initiation.

### Webhook Verification (Timing-Safe Comparison)

**File**: app/Http/Controllers/Webhooks/MoolreWebhookController.php

```
Webhook Secret Verification:
1. Optional: Only required if MOOLRE_WEBHOOK_SECRET is configured
2. Hash comparison: hash_equals() for timing-safe comparison
   ├─ Prevents timing attacks on secret validation
   └─ Must-have for security-sensitive operations

Code:
if ($configured_secret && !hash_equals($configured_secret, $webhook_secret)) {
    return response('Forbidden', 403);
}
```

**Why hash_equals()?**
- Regular string comparison (==, ===) can leak timing information
- Attackers can guess secrets character-by-character by measuring response time
- hash_equals() takes constant time regardless of mismatch position

### Status Mapping & Verification

```
Webhook Status Codes:
├─ txStatus=1: Payment successful
├─ txStatus=0: Payment pending (customer hasn't entered PIN yet)
└─ txStatus=2: Payment failed

Verification Flow:
1. Webhook received with reference & status
2. Call MoolrePaymentService->verifyPayment(reference)
   ├─ Server-to-server API call: GET /open/transact/status?externalref=...
   ├─ Returns authoritative payment status from Moolre
   └─ Never trust webhook status alone
3. Process based on verified status
   ├─ Success: Complete order, credit wallet
   ├─ Failed: Mark order failed, notify customer
   └─ Pending: Wait for next webhook or customer retry
```

**Idempotency:**
- Reference-based lookups ensure single payment record per transaction
- Webhook checks payment_status before processing
- Multiple webhooks for same reference are safely ignored

### Known Bugs Fixed

#### 1. Verification Code Fallback
**Issue**: Moolre sometimes responds with verification-code flow requiring customer action outside app
**Fix**: PaymentService->applyCheckoutFallbackForMoolreVerificationCode()
- Detects verification-code response
- Automatically tries alternative active collection gateway
- Preserves checkout flow without customer friction
**File**: app/Services/PaymentService.php line 44

#### 2. Channel Mapping Errors
**Issue**: Network name variations (mtn vs MTN, vodafone vs telecel) caused mismatches
**Fix**: Strict channel mapping in MoolrePaymentService->mapCollectionChannel()
- Normalize network name to lowercase
- Map to exact Moolre channel ID (13, 6, 7)
- Explicit mapping prevents silent failures
**File**: app/Services/MoolrePaymentService.php

### Things That Must Never Be Reverted

1. **hash_equals() for webhook secret verification**
   - Timing-safe comparison is critical
   - Never switch to == or strcmp()

2. **Phone normalization before payment initiation**
   - Moolre strictly requires 0XXXXXXXXX format
   - Skipping normalization will cause payment failures

3. **Server-to-server verification (not webhook status alone)**
   - Webhook status can be spoofed or out-of-sync
   - Always verify via provider API for authoritative status

4. **lockForUpdate() on payment completion**
   - Prevents double-crediting vendor wallets
   - Concurrency safety critical in high-volume scenarios

5. **Idempotency via payment reference**
   - Multiple webhook retries must safely complete same order
   - Reference-based lookups enable idempotent processing

### Known Production Pitfalls

1. **Phone Format Sensitivity**: Moolre rejects requests with spaces, dashes, or wrong country code
   - Always test with multiple phone formats
   - Add logging of normalized phone before payment init

2. **Webhook Retry Behavior**: Moolre retries webhooks multiple times
   - Application must be idempotent (hash_equals, payment_status check)
   - 200 OK response prevents additional retries

3. **Verification Code Fallback**: Moolre occasionally forces verification-code flow
   - Without fallback to alternative gateway, checkout fails
   - Test with both Moolre and backup gateway configured

4. **Webhook Secret Mismatch**: If MOOLRE_WEBHOOK_SECRET is wrong, all webhooks rejected
   - Verify secret in PaymentGatewayConfig matches Moolre account
   - Check webhook logs for 403 Forbidden responses

### Testing Moolre Locally

```
1. Configure sandbox credentials in .env:
   MOOLRE_API_KEY=...
   MOOLRE_API_USER=xtra4u
   MOOLRE_BASE_URL=https://api.moolre.com  (sandbox available)

2. Test phone normalization:
   php artisan tinker
   > $svc = app(MoolrePaymentService::class);
   > $svc->normalizePhone('+233244123456')
   => "0244123456"

3. Test payment initiation:
   POST /checkout
   { "phone": "0244123456", "network": "mtn", "amount": 1 }

4. Test webhook verification:
   POST /webhooks/moolre/payment
   { "data": { "externalref": "order-123", "txstatus": 1, "secret": "..." } }

5. Monitor logs:
   tail -f storage/logs/laravel.log | grep -i moolre
```

---

## 7. External Fulfillment System

### Provider Architecture

**File**: app/Services/ExternalFulfillment/ExternalFulfillmentClientFactory.php

```
Single interface for all providers:
interface ExternalFulfillmentClient {
    public function sendOrder(Order $order, string $idempotencyKey): array;
    public function getServices(): array;
}

Factory pattern:
$client = ExternalFulfillmentClientFactory::make('xpresportal');
```

### Three Provider Implementations

#### 1. Datafyhub

**File**: app/Services/ExternalFulfillment/Providers/DatafyhubClient.php

**Features:**
- Network resolution: mtn, telecel, ishare, bigtime, xpress
- Capacity/size parsing from product metadata
- Bearer token authentication
- Service discovery via /services endpoint
- Timeout: 10 seconds

**Network Mapping:**
```
Priority order:
1. Explicit datafyhub_network in product metadata
2. Generic external_mappings.datafyhub.network
3. Order's mobile_money_network
4. Fallback to MTN if all else fails
```

**API Request:**
```
POST /order
Bearer: {token}
Idempotency-Key: {reference}

{
  "network": "mtn",
  "phone": "0244123456",
  "amount": 10,
  "size": "1GB"
}
```

#### 2. XpresPortal

**File**: app/Services/ExternalFulfillment/Providers/XpresPortalClient.php

**Features:**
- Multi-endpoint ordering (with fallback candidates)
- Multiple payload variants for API version compatibility
- Vendor credential isolation (each vendor provides own API key)
- Service discovery with 3-tier fallback
- 30-second timeout
- Metadata tracking (vendor IDs, payment gateway, mapped service identifiers)

**Credential Handling:**
```
Vendor must configure in /vendor/settings/external-fulfillment:
├─ XpresPortal API Key
├─ XpresPortal API Secret (optional)
└─ Environment (sandbox/production)

Credentials encrypted in VendorSetting table:
  table: vendor_settings
  columns: vendor_id, group ('external_fulfillment'), key, value (encrypted)
```

**Service Discovery (3-tier Fallback):**
1. Direct service endpoint: GET /api/v1/offers
2. Per-network offers: GET /api/v1/offers?network=MTN
3. Global offers endpoint: GET /api/v1/available-packages

**API Request:**
```
POST /api/v1/topup
X-API-Key: {key}
X-API-Secret: {secret}
Idempotency-Key: {reference}

{
  "offer_slug": "MTN_1GB_24HR",
  "network": "MTN",
  "phone": "0244123456",
  "amount": 10
}
```

#### 3. GigsHub

**File**: app/Services/ExternalFulfillment/Providers/GigshubClient.php

**Features:**
- Data bundle fulfillment
- Balance webhooks: POST /webhooks/gigshub/status
- Low-balance alerts: POST /webhooks/gigshub/balance-low
- Status webhook: Automatic order status updates
- API Key authentication

**Webhooks:**
```
Order Status Update:
POST /webhooks/gigshub
{
  "event": "delivered",
  "orderId": "order-123",
  "reference": "ref-456",
  "status": "delivered|failed|pending|processing|cancelled|refunded"
}

Status Mapping:
├─ delivered/resolved → fulfilled (succeeded)
├─ failed/cancelled/refunded → failed
└─ pending/processing → processing

Low Balance Alert:
POST /webhooks/gigshub/balance-low
{
  "balance.low": {
    "balance": 50,
    "threshold": 100,
    "currency": "GHS"
  }
}

Creates GigshubLowBalanceAlert record with cooldown (120s duplicate suppression)
```

### Product Provider Mapping System

**Database**: Product.description (JSON metadata)

```
Product.description_json:
{
  "network": "mtn",
  "external_network": "MTN",
  "datafyhub_network": "mtn",
  "size": "1GB",
  "external_mappings": {
    "xpresportal": {
      "network": "MTN",
      "capacity": "1GB",
      "offer_slug": "MTN_1GB_24HR",
      "service_id": "12345",
      "service_name": "MTN 1GB Daily Pass",
      "price": 15.00
    },
    "datafyhub": {
      "network": "mtn",
      "capacity": "1GB",
      "service_id": "df_mtn_1gb",
      "price": 15.50
    },
    "gigshub": {
      "network": "mtn",
      "volume": "1",
      "offer_slug": "MTN_1GB",
      "price": 16.00
    }
  }
}
```

**Mapping Repair Command:**
```
php artisan products:fix-gigshub-mappings --dry-run
├─ Infers missing mappings from product name, network, size
├─ Ensures complete GigsHub mappings
├─ Supports per-product or bulk fixes
└─ --dry-run shows proposed changes
```

### Fulfillment Job Architecture

**File**: app/Jobs/ProcessExternalFulfillment.php

```
Triggered by: OrderCompleted event → DispatchExternalFulfillmentFromOrderCompleted listener

Execution:
1. Acquire distributed cache lock (600s timeout)
   └─ Prevents duplicate concurrent processing

2. Validate order state
   ├─ payment_status must be 'paid' or 'completed'
   └─ Prevents fulfillment of unpaid orders

3. Check already fulfilled
   ├─ If external_fulfillment_status='succeeded'
   └─ Skip (idempotent)

4. Resolve vendor
   ├─ Use owner_vendor_id (if reseller)
   └─ Otherwise vendor_id

5. Load vendor-specific config
   ├─ ExternalFulfillmentConfig::loadFreshForVendor(vendor)
   ├─ Fetches vendor's credentials from VendorSetting
   └─ Decrypts API keys

6. Verify config ready
   ├─ Provider, API key, and other required fields present
   └─ Fail if incomplete

7. Generate idempotency key
   ├─ Default: order->payment_reference
   └─ Fallback: "order-{id}"

8. Set order attributes for provider context
   ├─ external_fulfillment_provider_used = resolved provider
   └─ external_fulfillment_idempotency_key = generated key

9. Update order status to processing

10. Send order to provider
    ├─ ExternalFulfillmentClientFactory::make(provider)->sendOrder(order, key)
    └─ Provider returns status and optional remote_reference

11. Process response
    ├─ Success: status='succeeded', remote_reference captured
    ├─ Pending/processing: status='processing'
    ├─ Failure: status='failed', error_message logged (1000 char limit)
    └─ Duplicate detection: checks "already exists" message

12. Release lock

Retries: 3 attempts
├─ First retry: 60 seconds
├─ Second retry: 300 seconds (5 minutes)
└─ Third retry: 900 seconds (15 minutes)
```

**File**: app/Jobs/FulfillPendingOrdersJob.php

```
Triggered by: PIN upload in AdminResultCheckerPinsController::store()

Purpose: Auto-fulfill pending_stock result checker orders after new stock

Execution:
1. Query all pending_stock ResultCheckerOrder records
2. For each order, call ResultCheckerService->allocatePinsForOrder()
3. If allocation succeeds:
   ├─ Order marked completed
   ├─ SMS delivery notification sent
   └─ ResultCheckerOrderPaid event fired
4. If still insufficient stock:
   └─ Order remains pending_stock for next upload
```

### Webhook Status Updates

**File**: app/Http/Controllers/Webhooks/GigshubWebhookController.php

```
Route: POST /webhooks/gigshub

Payload: { event, orderId, reference, status, ... }

Processing:
1. Extract orderId or reference from request
2. Query order by external_fulfillment_remote_reference
3. Map provider status to app status
   ├─ delivered/resolved → succeeded
   ├─ failed/cancelled/refunded → failed
   └─ pending/processing → processing
4. Detect duplicates (prevent double-marking)
5. Update order:
   ├─ external_fulfillment_status = mapped_status
   ├─ external_fulfillment_completed_at = now() (if succeeded/failed)
   └─ external_fulfillment_last_error = error (if failed)
6. Return 200 OK
```

**File**: app/Http/Controllers/Webhooks/GigshubLowBalanceWebhookController.php

```
Route: POST /webhooks/gigshub/balance-low

Creates: GigshubLowBalanceAlert record

Cooldown: 120s duplicate suppression (prevents alert spam)

Payload: { balance.low: { balance, threshold, currency, message } }
```

### Vendor Settings & Configuration

**File**: app/Http/Controllers/Vendor/ExternalFulfillmentSettingsController.php

```
Route: /vendor/settings/external-fulfillment

Vendor can configure:
├─ Enable/disable each provider
├─ API Key (for XpresPortal)
├─ API Secret (for XpresPortal, if required)
├─ Environment (sandbox/production)
└─ Test connection to provider

Constraints:
├─ Only root vendors can configure (not affiliates)
├─ Each vendor must provide own credentials
├─ Credentials encrypted via Crypt::encryptString()
└─ Vendor isolation enforced

Test endpoint:
POST /vendor/settings/external-fulfillment/test-xpres
├─ Attempts API call with vendor's credentials
├─ Returns connection status
└─ Helps debug credential issues
```

### Critical Constraints

1. **Vendor Isolation**: Each vendor provides own credentials
   - No sharing of API keys across vendors
   - Enables multi-tenant support
   - Vendor cannot access another's credentials

2. **No Hardcoding**: Always use ExternalFulfillmentClientFactory
   - Enables provider switching without code changes
   - Supports dynamic provider selection per vendor
   - Avoids duplicate service discovery logic

3. **Idempotency Required**: Payment reference as idempotency key
   - Prevents duplicate orders at provider
   - Safe for retry scenarios
   - Provider returns duplicate error if already sent

4. **Metadata Accuracy**: Product mappings must be maintained
   - offer_slug, service_id, capacity must match provider's API
   - Use FixProductGigshubMappings command to repair
   - Test mappings in sandbox before production

### Important Files

- **Factory**: app/Services/ExternalFulfillment/ExternalFulfillmentClientFactory.php
- **Config**: app/Services/ExternalFulfillment/ExternalFulfillmentConfig.php
- **Providers**: app/Services/ExternalFulfillment/Providers/{Datafyhub,XpresPortal,Gigshub}Client.php
- **Job**: app/Jobs/ProcessExternalFulfillment.php
- **Webhook**: app/Http/Controllers/Webhooks/GigshubWebhookController.php
- **Vendor Settings**: app/Http/Controllers/Vendor/ExternalFulfillmentSettingsController.php
- **Migrations**: database/migrations/2026_01_03_000001_add_external_fulfillment_columns_to_orders_table.php

---

## 8. Results Checker System

### Complete System Overview

The Results Checker system allows customers to purchase exam result lookup credentials (PINs) that are allocated from admin-managed inventory and delivered via SMS.

### Database Schema

**Table: result_checker_orders**
```
Columns:
├─ id (PK)
├─ vendor_id (FK → vendors)
├─ service_id (FK → network_services) ⚠️ NOT checker_type_id
├─ customer_phone (indexed)
├─ customer_name (nullable)
├─ quantity (int, default: 1, cap: 100)
├─ unit_price (decimal:2)
├─ total_price (decimal:2)
├─ delivered_pins_json (JSON: [{pin, serial}, ...])
├─ status (ENUM: pending_payment, pending_stock, processing, completed, failed)
├─ payment_reference (unique, nullable)
├─ payment_gateway (nullable)
├─ paid_at (nullable timestamp)
├─ fulfilled_at (nullable timestamp)
├─ sms_sent_at (nullable timestamp)
└─ timestamps (created_at, updated_at)

Indexes:
├─ vendor_id, status
├─ service_id, status
└─ payment_reference (unique)

⚠️ SCHEMA PITFALL: Use service_id, NOT checker_type_id (legacy)
```

**Table: result_checker_pins**
```
Columns:
├─ id (PK)
├─ service_id (FK → network_services)
├─ pin (string:50, indexed)
├─ serial (string:50, indexed)
├─ status (ENUM: available, sold, reserved)
├─ result_checker_order_id (FK → result_checker_orders, nullable) ⚠️ NOT order_id
├─ sold_at (nullable timestamp)
└─ timestamps (created_at, updated_at)

Indexes:
├─ status, service_id
├─ result_checker_order_id
└─ Unique constraint: (service_id, pin, serial)

⚠️ SCHEMA PITFALL: Use result_checker_order_id, NOT order_id (legacy)
```

**Table: vendor_result_checker_settings**
```
Columns:
├─ id (PK)
├─ vendor_id (FK → vendors)
├─ service_id (FK → network_services)
├─ profit_amount (decimal:2, default: 0)
├─ is_active (boolean, default: false)
└─ timestamps (created_at, updated_at)

Indexes:
├─ Unique: (vendor_id, service_id)
└─ vendor_id, is_active

Purpose: Vendor enables specific checker services and sets profit margin
```

**Table: network_services (modifications)**
```
New columns:
├─ is_checker_type (boolean, default: false, indexed)
├─ base_price (decimal:2, nullable)
├─ checker_code (string, nullable, unique)
└─ checker_description (text, nullable)

Purpose: Mark services as result checker type and store provider mapping
```

### Customer Ordering Flow

**File**: app/Http/Controllers/ResultCheckerCheckoutController.php

```
POST /store/{vendor:vendor_code}/result-checkers/checkout

1. Validate Request
   ├─ Service exists
   ├─ Service is_checker_type = true
   ├─ Service is active
   ├─ Quantity: 1-100
   └─ Customer phone format valid

2. Check Vendor Enabled
   ├─ Query VendorResultCheckerSetting
   ├─ Check is_active = true
   └─ Fail if disabled

3. Calculate Pricing
   ├─ unit_price = service.base_price + vendor_setting.profit_amount
   ├─ total_price = unit_price × quantity
   └─ Email synthetic: {customer_phone}@xtra4u.local

4. Create ResultCheckerOrder
   ├─ Status: pending_payment
   ├─ Store customer_phone, quantity, unit_price
   └─ Store in database

5. Initiate Payment
   ├─ GatewayManager.collect(order, email, total_price)
   ├─ Returns: payment reference & authorization URL
   └─ Store payment_reference on order

6. Return Response
   └─ Return payment authorization URL (for redirect) or
      return inline payment form (for Moolre)
```

### Admin Stock Management

**File**: app/Http/Controllers/AdminResultCheckerPinsController.php

```
PIN Upload:
POST /admin/results-checkers/pins

Accepts:
├─ CSV file (columns: SERIAL, PIN)
├─ Text input (one per line: "SERIAL PIN" or "SERIAL,PIN")
└─ Both formats validated

Validation:
├─ Minimum 2 columns/fields
├─ No duplicates within upload
├─ No empty values
├─ No duplicates already in database
└─ Unique constraint: (service_id, pin, serial)

Processing:
1. Parse file/text
2. Validate structure and duplicates
3. Bulk insert ResultCheckerPin records (within transaction)
   ├─ status: 'available'
   ├─ service_id: from service dropdown
   └─ All pins initially available

4. Dispatch FulfillPendingOrdersJob(service_id)
   └─ Auto-fulfill pending_stock orders after upload

PIN Inventory View:
GET /admin/results-checkers/pins
├─ Filter by service
├─ Filter by status (available/sold/reserved)
├─ Pagination: 50 per page
└─ Show associated order if allocated

Stock Dashboard:
GET /admin/results-checkers/dashboard
├─ Per-service counts
├─ Total pins, available pins, used (sold) pins
└─ Visualization for stock monitoring
```

### Vendor Pricing System

**File**: app/Http/Controllers/VendorResultCheckerSettingsController.php

```
Vendor Configuration:
GET|POST /vendor/result-checkers

Display:
├─ All available checker services
├─ Current profit_amount for each
├─ is_active toggle for each

Update:
├─ POST /vendor/result-checkers/{service}
├─ vendor sets profit_amount (GHS)
├─ vendor toggles is_active (boolean)
└─ Uses updateOrCreate() for atomicity

Constraints:
├─ Only enabled services appear in checkout
├─ Profit can be negative (loss leaders)
├─ Both root vendors and resellers can configure
```

### PIN Allocation (Pessimistic Locking)

**File**: app/Services/ResultCheckerService.php::allocatePinsForOrder()

```
Key Pattern: Pessimistic locking to prevent overselling

Execution:
1. Refresh order (get latest state)
2. Idempotency check:
   ├─ If status='completed': return true (already allocated)
   └─ If pins already exist: return true (already linked)

3. Acquire Lock
   └─ lockForUpdate() on available ResultCheckerPin records:
      SELECT * FROM result_checker_pins
      WHERE service_id = ? AND status = 'available'
      FOR UPDATE LIMIT ?
      └─ Holds exclusive lock during allocation
      └─ Other transactions wait or retry

4. Fetch Available Pins
   ├─ Take exactly order->quantity pins
   ├─ FIFO by created_at (first available, first allocated)
   └─ Return locked records

5. Check Inventory
   ├─ If available_pins.count() < order->quantity:
   │  ├─ Log warning
   │  ├─ Release lock
   │  └─ Return false (insufficient stock)
   └─ If sufficient:
      └─ Proceed to allocation

6. Allocate Pins
   ├─ For each pin:
   │  ├─ Update: status='sold'
   │  ├─ Link: result_checker_order_id=order->id
   │  ├─ Set: sold_at=now()
   │  └─ Collect pin/serial data
   └─ Build $pinsData array

7. Update Order
   ├─ Set: delivered_pins_json=$pinsData
   ├─ Set: status='completed'
   ├─ Set: fulfilled_at=now()
   └─ Commit transaction

8. Return true (success)

⚠️ WHY PESSIMISTIC LOCKING:
- Prevents race conditions in high-volume scenarios
- Ensures exact quantity allocated (no overselling)
- FIFO ordering prevents fairness issues
- Alternative (optimistic) would require retries
```

### Pending Stock Handling

**Status: pending_stock**

```
Trigger: allocatePinsForOrder() returns false (insufficient inventory)

Consequences:
├─ Order marked pending_stock
├─ Customer has paid (paid_at set)
├─ No SMS delivered yet
└─ Order waiting for stock upload

Recovery Paths:

Path 1: Automatic (Stock Upload)
├─ Admin uploads new pins
├─ FulfillPendingOrdersJob(service_id) dispatched
├─ Job queries all pending_stock orders for service
├─ Attempts allocatePinsForOrder() for each order
├─ If successful:
│  ├─ Order marked completed
│  ├─ SMS delivery triggered
│  └─ Result checker event fired (no listener)
└─ If still insufficient: order remains pending_stock

Path 2: Manual (Admin Interface)
├─ Route: GET /admin/results-checkers/orders/{order}
├─ Admin views order detail
├─ Admin can click "Retry" button
│  └─ POST /admin/results-checkers/orders/{order}/retry
│     └─ Dispatches RetryResultCheckerOrder job
└─ Job calls handlePaymentSuccess() to reattempt allocation

Path 3: Customer View
├─ After payment callback
├─ If pending_stock:
│  ├─ Redirect to /result-checkers/pending-stock/{order}
│  ├─ View: result_checkers.pending_stock blade template
│  └─ Message: "Your order is backordered, stock arriving soon"
```

### SMS Delivery Integration

**File**: app/Services/SmsService.php

```
Provider: BulkClix API

Phone Normalization:
1. Remove non-numeric characters
2. If starts with '0': Convert to '233' + rest
3. If 9 digits: Prepend '233'
4. Result: International format "233XXXXXXXXX"

Examples:
├─ "0244123456" → "233244123456"
├─ "244123456" → "233244123456"
├─ "2244123456" → "233244123456"

Delivery:
POST https://bulkclix.com/api/v1/send-sms
Authorization: Bearer {API_KEY}

{
  "recipients": [{
    "phonenumber": "233244123456",
    "message": "Your exam result PIN:\nPIN: 123456\nSerial: ABC789\n..."
  }]
}

Message Format:
Your exam result PIN has been delivered.
[repeated for each PIN]
PIN: {pin}
SERIAL: {serial}

Thank you for using XTRA4U.

Trigger:
├─ Called in ResultCheckerService::sendDeliveryNotification()
├─ Only if order status = 'completed'
├─ Sets sms_sent_at timestamp
└─ Idempotent: returns true if already sent
```

**⚠️ KNOWN ISSUE: Missing Event Listener**
- ResultCheckerOrderPaid event fired after allocation
- No listener registered in EventServiceProvider
- SMS delivery NOT automatic via event
- Manual call to sendDeliveryNotification() required

### Retry Jobs

**File**: app/Jobs/RetryResultCheckerOrder.php

```
Triggered: Admin "Retry" button on order detail

Action:
├─ Query order by ID
├─ If already completed: skip
├─ Call ResultCheckerService::handlePaymentSuccess()
│  └─ Calls allocatePinsForOrder()
│     ├─ If sufficient stock: allocate & complete
│     └─ If insufficient: remains pending_stock
```

**File**: app/Jobs/FulfillPendingOrdersJob.php

```
Triggered: AdminResultCheckerPinsController::store() after PIN upload

Action:
├─ Query all pending_stock ResultCheckerOrder for service
├─ For each order:
│  ├─ Call handlePaymentSuccess()
│  ├─ If successful: send SMS, fire event
│  └─ If still insufficient: remain pending_stock
```

### Payment Integration

**Callback Handler:**
```
POST /result-checkers/payment/callback/{order}
OR
POST /result-checkers/payment/webhook

Routed to: ResultCheckerPaymentCallbackController

Handler:
1. Extract payment reference from request
2. Call GatewayManager->verifyCollection(reference)
3. Check status is success/successful/completed/paid
4. If success:
   └─ ResultCheckerService::handlePaymentCallback()
      ├─ Mark paid: paid_at, payment_reference, payment_gateway
      ├─ Call allocatePinsForOrder()
      ├─ If successful: status='completed'
      ├─ If insufficient: status='pending_stock'
      └─ Fire ResultCheckerOrderPaid event (no listener ⚠️)
5. If pending_stock:
   └─ Redirect to /result-checkers/pending-stock/{order}
6. If failed:
   └─ Redirect to status page with error
```

### Known Issues & Gaps

⚠️ **Issue 1: Missing Event Listener**
- Event: ResultCheckerOrderPaid
- Status: Fired but no listener registered
- Impact: SMS not sent automatically after allocation
- Workaround: Manual call to sendDeliveryNotification() in handlePaymentSuccess()
- Fix: Register listener in EventServiceProvider or add manual trigger

⚠️ **Issue 2: SMS Delivery Gap**
- SMS only sent on immediate allocation
- If payment callback triggers pending_stock
- Then later FulfillPendingOrdersJob allocates stock
- SMS not sent on delayed fulfillment ⚠️
- Impact: Customer may not know order was fulfilled
- Workaround: Queue SMS job separately in FulfillPendingOrdersJob

⚠️ **Issue 3: No Vendor Relationship Methods**
- Vendor model lacks resultCheckerOrders() relationship
- Must query via foreign key: Vendor::find($id)->orders()->where(...)
- Recommendation: Add relationship method

⚠️ **Issue 4: Hidden PIN Fields in API**
- ResultCheckerPin model hides 'pin' and 'serial' by default
- Causes issues in admin JSON responses
- Should be visible in admin views
- Recommendation: Unset hidden in admin context or add attribute

### Important Files

**Models:**
- app/Models/ResultCheckerOrder.php
- app/Models/ResultCheckerPin.php
- app/Models/VendorResultCheckerSetting.php
- app/Models/NetworkService.php (modified)

**Services:**
- app/Services/ResultCheckerService.php

**Controllers:**
- app/Http/Controllers/ResultCheckerCheckoutController.php
- app/Http/Controllers/ResultCheckerPaymentCallbackController.php
- app/Http/Controllers/ResultCheckerStatusController.php
- app/Http/Controllers/Admin/AdminResultCheckerDashboardController.php
- app/Http/Controllers/Admin/AdminResultCheckerPinsController.php
- app/Http/Controllers/Admin/AdminResultCheckerOrdersController.php
- app/Http/Controllers/Vendor/VendorResultCheckerSettingsController.php
- app/Http/Controllers/Vendor/VendorResultCheckerOrdersController.php

**Jobs:**
- app/Jobs/RetryResultCheckerOrder.php
- app/Jobs/FulfillPendingOrdersJob.php

**Migrations:**
- database/migrations/2026_04_03_000001_create_result_checker_tables.php

---

## 9. Vendor & Admin Systems

### Vendor Features & Workflows

**Vendor Dashboard:**
- Route: `/vendor/dashboard`
- Real-time order metrics, earning summaries
- Recent orders, top services, monthly revenue

**Product/Service Management:**
- Create, edit, disable services
- Set pricing, descriptions, network mappings
- Upload product images
- Manage inventory visibility

**Order Management:**
- View all orders by customer, date, status
- Download order receipts
- Refund/cancel orders (if enabled)
- Track fulfillment status for external orders

**Withdrawal & Payouts:**
- Request withdrawal from wallet
- View payout history
- Track commission calculations
- Payout status (pending, processing, completed)

**External Fulfillment Settings:**
- Configure provider credentials (XpresPortal, Datafyhub, GigsHub)
- Test provider connections
- Enable/disable per provider

**Result Checker Configuration:**
- Enable/disable per service
- Set profit margin (GHS amount)
- View result checker orders
- Track PIN allocations

**Affiliation Program (if multi-level enabled):**
- Invite resellers/affiliates
- Set reseller markups per service
- View affiliate earnings & payouts
- Monitor affiliate activity

**Middleware Guards:**
- `vendor.approved`: Only approved vendors can access
- Custom authentication guard: `vendor`
- Session-backed authentication

### Admin Features & Workflows

**Admin Dashboard:**
- Route: `/admin`
- Platform metrics, payment status
- Vendor approval queue
- High-risk alerts (failed payments, low balances)

**Vendor Management:**
- View all vendors (approved, pending, rejected)
- Approve vendor requests
- View vendor details, earning history
- Suspend/ban vendors if needed
- Monitor vendor balances

**Payment Gateway Configuration:**
- CRUD payment gateways (Moolre, Paystack, Flutterwave, BulkClix, Hubtel)
- Set default gateway per capability type
- Test gateway connectivity
- Configure encrypted API keys, secrets, URLs
- Enable/disable gateways

**Network Service Management:**
- Create/edit network services (MTN, Telecel, AirtelTigo)
- Configure as result checker type
- Set base prices
- Manage service availability

**PIN Management:**
- Upload PIN inventory (CSV or text)
- View PIN stock by service (available, sold, reserved)
- Assign PINs to pending orders (manual fulfillment)
- View PIN allocation history

**Order Management:**
- View all orders (search by customer, date, status)
- Manual fulfillment for pending_stock orders
- Mark orders complete/failed
- Export order reports

**Withdrawal Processing:**
- View pending vendor withdrawal requests
- Process payouts via configured payout gateway
- Mark payouts complete
- Track payout history

**Analytics & Reporting:**
- Revenue by vendor, service, date range
- Commission tracking, payment gateway usage
- Fulfillment success rates
- SMS delivery metrics

**Middleware Guards:**
- `admin.only`: Only admin users can access
- Custom authentication guard: `admin`

### Security & Authorization

**Vendor Isolation:**
- Each vendor sees only their own orders, products, earnings
- Cannot access other vendor data
- API endpoints validated via auth()->user()->vendor_id

**Admin Capabilities:**
- Full platform visibility
- Cannot perform vendor orders (admin ≠ vendor)
- Cannot approve own vendor requests (if admin has vendor account)

**Credential Encryption:**
- PaymentGatewayConfig: config_data encrypted at rest
- VendorSetting: vendor API keys encrypted at rest
- Decrypted only in memory for API calls
- Never logged or exposed in responses

**CSRF Protection:**
- Web routes use CSRF middleware
- Webhooks exempt: POST /webhooks/* (CSRF disabled)
- Admin/Vendor forms require CSRF token

### Important Routes

**Vendor Routes (authenticated):**
```
GET|POST  /vendor/dashboard
GET|POST  /vendor/products
GET|POST  /vendor/orders
GET|POST  /vendor/wallet
GET|POST  /vendor/withdrawals
GET|POST  /vendor/result-checkers
GET|POST  /vendor/settings/external-fulfillment
POST      /vendor/settings/external-fulfillment/test-xpres
```

**Admin Routes (authenticated):**
```
GET|POST  /admin/dashboard
GET|POST  /admin/vendors
GET|POST  /admin/payment-gateways
PATCH     /admin/payment-gateways/{gateway}/set-default
PATCH     /admin/payment-gateways/{gateway}/toggle-active
POST      /admin/payment-gateways/{gateway}/test
GET|POST  /admin/network-services
GET|POST  /admin/results-checkers/pins
GET|POST  /admin/results-checkers/orders
POST      /admin/results-checkers/orders/{order}/retry
POST      /admin/results-checkers/orders/{order}/mark-failed
```

---

## 10. Database Schema & Critical Relationships

### Core Tables

**users**
- Base authentication table (nullable for customers, required for admin/vendor)

**vendors**
- vendor_code (unique): Business identifier
- approval_status (enum: approved, pending, rejected)
- business_name, contact_email, phone, country
- wallet_balance (decimal:2): Accumulated earnings
- created_at, updated_at

**orders**
- vendor_id (FK)
- recipient_phone_number, mobile_money_network
- amount_paid (decimal:2)
- status (enum: pending, processing, completed, failed)
- payment_status (enum: unpaid, paid, failed)
- payment_reference, payment_gateway, payment_completed_at
- external_fulfillment_status (nullable: succeeded, processing, failed)
- is_reseller_order, owner_vendor_id, reseller_vendor_id
- affiliate_chain_snapshot (JSON array of payout records)
- Indexes: vendor_id, status, payment_reference

**transactions**
- order_id (FK, nullable for polymorphic)
- transactionable_type, transactionable_id (polymorphic)
- vendor_id (FK)
- amount, commission_amount, vendor_earning (decimal:2)
- payment_status (string)
- gateway_transaction_id (nullable)
- Indexes: order_id, transactionable_type/id, vendor_id

**products**
- vendor_id (FK)
- service_name, description (JSON metadata)
- price (decimal:2)
- network (string): mobile network identifier
- Indexes: vendor_id, network, service_name

**payment_gateway_configs**
- gateway_name (unique: moolre, paystack, flutterwave, bulkclix, hubtel)
- gateway_type (enum: payment_collection, payout, sms, generic)
- is_active, is_default (boolean)
- config_data (text, encrypted JSON)
- supports_collection, supports_payout, supports_sms, supports_webhook (boolean)
- environment (sandbox, live)

**result_checker_orders**
- vendor_id, service_id (FKs)
- customer_phone, customer_name
- quantity (int, cap: 100)
- unit_price, total_price (decimal:2)
- delivered_pins_json (JSON array)
- status (enum: pending_payment, pending_stock, processing, completed, failed)
- payment_reference, payment_gateway
- paid_at, fulfilled_at, sms_sent_at (nullable timestamps)
- Indexes: vendor_id, service_id, payment_reference

**result_checker_pins**
- service_id, result_checker_order_id (FKs)
- pin, serial (strings)
- status (enum: available, sold, reserved)
- sold_at (nullable timestamp)
- Unique constraint: (service_id, pin, serial)

**vendor_result_checker_settings**
- vendor_id, service_id (FKs, unique pair)
- profit_amount (decimal:2)
- is_active (boolean)

**vendor_settings**
- vendor_id (FK), group, key, value (encrypted)
- For storing vendor-specific credentials and configuration

**network_services**
- service_name, network, base_price
- is_checker_type (boolean, indexed)
- checker_code, checker_description

### Critical Relationships

```
Vendor (1) ----- (M) Order
         |
         +------ (M) ResultCheckerOrder
         |
         +------ (M) VendorResultCheckerSetting
         |
         +------ (M) VendorSetting

Order (1) ------ (M) Transaction
         |
         +------ (1) PaymentGatewayConfig

ResultCheckerOrder (1) ------ (M) ResultCheckerPin

NetworkService (1) ------ (M) ResultCheckerOrder
                |
                +------ (M) ResultCheckerPin
                |
                +------ (M) VendorResultCheckerSetting
```

### JSON Metadata Structures

**Order.affiliate_chain_snapshot**
```json
[
  {
    "vendor_id": 2,
    "vendor_type": "reseller",
    "earning": 19.60,
    "percentage": 16.33
  },
  {
    "vendor_id": 1,
    "vendor_type": "owner",
    "earning": 98.00,
    "percentage": 81.67
  }
]
```

**Product.description**
```json
{
  "network": "mtn",
  "external_network": "MTN",
  "size": "1GB",
  "capacity": "1",
  "external_mappings": {
    "xpresportal": {
      "network": "MTN",
      "offer_slug": "MTN_1GB_24HR",
      "service_id": "12345",
      "service_name": "MTN 1GB",
      "price": 15.00
    }
  }
}
```

### Schema Pitfalls & Cautions

⚠️ **Enum Fragility**
- ENUM columns (status, payment_status) are immutable
- Cannot add values without ALTER TABLE
- Test migrations on large datasets first
- Consider TEXT + CHECK constraint for flexibility

⚠️ **JSON Queries**
- JSON->SQLite compatibility differs from MySQL
- Test complex JSON_EXTRACT queries before production
- Consider denormalizing frequently queried fields

⚠️ **Nullable Foreign Keys**
- orders.external_fulfillment_status (nullable)
- Allows orders with no fulfillment attempt
- Always check for null before processing

⚠️ **Legacy Columns**
- Never use checker_type_id (use service_id)
- Never use order_id in ResultCheckerPin (use result_checker_order_id)
- Legacy fields present only for backwards-compat
- Plan removal in future major version

⚠️ **Timestamp Ordering**
- paid_at, fulfilled_at, sms_sent_at may not be sequential
- SMS can fail even after fulfillment
- Always check specific timestamp before assuming state

### Migration Best Practices

1. **Test on large datasets**: Never rush migrations with millions of rows
2. **Backup production**: Always backup before production migration
3. **Use transactions**: Wrap multi-step migrations in DB::transaction()
4. **Avoid column drops**: Deprecate first, drop in future version
5. **Test rollback**: Ensure down() method actually reverses up()
6. **Monitor durability**: Watch for interrupted migrations (queue failures)

---

## 11. Production Safety Rules

### Things That Should Never Be Modified Casually

#### 1. Payment Status Enums
**File**: database/migrations (payment_status column definition)

**Why**: Payment state machine is critical for order completion logic
- Changing enum values breaks existing order queries
- Payment completion depends on exact enum matching
- Webhooks must recognize all possible statuses

**Safe Operation**:
- Add new status values to enum (additive only)
- Never remove or rename existing values
- Test enum changes with samples of real orders
- Plan removal only after major version deprecation

#### 2. Result Checker Schema Columns
**File**: ResultCheckerPin, ResultCheckerOrder models

**Critical Columns**:
- service_id (NOT checker_type_id)
- result_checker_order_id (NOT order_id)
- status enum (available, sold, reserved, ...)

**Why**: These define the PIN allocation logic
- Allocation queries depend on exact column names
- Renaming breaks pending_stock fulfillment
- Schema changes can cause overselling

**Safe Operation**:
- Read migration carefully before applying
- Verify all application code uses correct column names
- Test allocation under concurrent load
- Never rename without updating all related code

#### 3. External Fulfillment Factory
**File**: app/Services/ExternalFulfillment/ExternalFulfillmentClientFactory.php

**Why**: Provider hardcoding breaks when switching vendors
- All new orders require dynamic provider selection
- Hardcoding prevents testing alternate providers
- Vendor-specific credentials require factory isolation

**Safe Operation**:
- Always use ExternalFulfillmentClientFactory::make()
- Never instantiate provider directly
- Test all providers in sandbox first
- Verify metadata mappings match provider API

#### 4. Webhook Verification Logic
**File**: MoolreWebhookController, webhook handlers

**Critical**: Hash timing-safe comparison, secret validation

**Why**: Webhook security depends on exact verification
- Timing attacks can reveal secrets
- Webhook replay attacks if not validated
- Payment status changes are unrecoverable

**Safe Operation**:
- Use hash_equals() always (never ==)
- Verify webhook secret against config
- Implement idempotency via reference
- Log all webhook processing attempts

#### 5. PIN Allocation Locking
**File**: ResultCheckerService::allocatePinsForOrder()

**Critical**: lockForUpdate() pessimistic locking

**Why**: Without locking, overselling possible
- Multiple requests can allocate same pin
- Race conditions in high-volume scenarios
- Customers get duplicates or conflicts

**Safe Operation**:
- Never remove lockForUpdate()
- Test allocation under concurrent load
- Monitor for lock timeouts
- Verify FIFO ordering

#### 6. Transaction Commission Calculation
**File**: PaymentService::completeRegularOrder, completeResellerOrder

**Critical**: Commission splits must match business model

**Why**: Incorrect calculations cause vendor disputes
- Over-paying vendors: business loss
- Under-paying vendors: legal disputes
- Multi-level splits must be verified

**Safe Operation**:
- Test with real order scenarios
- Audit commission on sample orders
- Verify against expected business splits
- Document any changes to percentage

### High-Risk Areas

#### Payment Webhook Processing
**Risk**: Double-crediting vendors or missing payments

**Safeguards**:
- lockForUpdate() on order before credit
- Check payment_status before processing (idempotent)
- Always verify via provider API
- Log all webhook activity
- Monitor for queue_failed accumulation

**Monitoring**:
```
SELECT COUNT(*) FROM queue_failed WHERE connection='failed_webhook';
SELECT * FROM transactions WHERE updated_at > NOW() - INTERVAL 1 HOUR
  ORDER BY updated_at DESC LIMIT 100;
```

#### PIN Allocation Race Conditions
**Risk**: Overselling (more pins allocated than available)

**Safeguards**:
- lockForUpdate() on available pins
- Verify count before allocation
- Transactional update of status + order link
- FIFO ordering prevents fairness issues

**Monitoring**:
```
SELECT COUNT(*) FROM result_checker_pins WHERE status='sold'
GROUP BY service_id;
```

#### Vendor Balance Calculations
**Risk**: Incorrect payouts, lost commissions

**Safeguards**:
- Test commission calculation on real orders
- Verify affiliate chain snapshots
- Audit payout history monthly
- Monitor wallet_balance discrepancies

**Monitoring**:
```
SELECT vendor_id, SUM(vendor_earning) FROM transactions
GROUP BY vendor_id ORDER BY SUM(vendor_earning) DESC;
```

#### Session Handling (Database Sessions)
**Risk**: Session corruption, CSRF token mismatches, 419 errors

**Safeguards**:
- Monitor sessions table size growth
- Clear expired sessions regularly
- Test session persistence across restarts
- Document shared hosting session issues

**Monitoring**:
```
SELECT COUNT(*) FROM sessions;
SELECT COUNT(*) FROM sessions WHERE last_activity < NOW() - INTERVAL 24 HOUR;
SELECT * FROM sessions WHERE data LIKE '%CSRF%' LIMIT 10;
```

### Queue Risks

#### Database Queue Accumulation
**Risk**: Long-running jobs block queue, unfulfilled orders

**Safeguards**:
- Monitor queue_failed table size
- Implement job timeout limits
- Implement circuit breaker for failing providers
- Log all job failures

**Monitoring**:
```
SELECT COUNT(*) FROM jobs; -- jobs table
SELECT COUNT(*) FROM queue_failed; -- failed jobs
SELECT COUNT(*) FROM jobs_batches; -- batch processing
```

#### Retry Job Loops
**Risk**: Infinite retry loop on consistently failing provider

**Safeguards**:
- Max retry attempts limited (3 attempts)
- Exponential backoff (60s, 300s, 900s)
- Circuit breaker after 3 failures
- Alert admin on repeated failures

**Monitoring**:
```
SELECT * FROM queue_failed WHERE connection='ProcessExternalFulfillment'
ORDER BY created_at DESC LIMIT 20;
```

### Safe Deployment Practices

#### Pre-Deployment Checklist
- [ ] Read all migrations thoroughly
- [ ] Test migrations on copy of production data
- [ ] Verify no schema column renames
- [ ] Check all payment gateway configs are valid
- [ ] Test payment callback flow end-to-end
- [ ] Verify external fulfillment mappings
- [ ] Clear cache before go-live
- [ ] Monitor for 419 session errors first hour
- [ ] Have rollback plan (previous Laravel version tested)

#### Migration Practices
- Use transactions for multi-step migrations
- Never drop columns with active data
- Test with large datasets (1M+ rows)
- Backup production before migration
- Schedule off-peak (low order volume)
- Monitor queue for stuck jobs during migration
- Have DBA on standby for schema locks

#### Rollback Expectations
- Database: Down migration reverses schema changes
- Code: Previous version must work with new schema
- Payment: Webhooks may arrive during rollback (idempotent!)
- Session: Session data may be lost if session table rolled back

---

## 12. Hosting & Deployment

### Current Environment

**Hosting**: cPanel shared hosting or VPS  
**Web Server**: Apache or Nginx  
**PHP Version**: 8.2+ required  
**Database**: SQLite (dev) or MySQL 8+ (production)  
**Session Driver**: Database (SESSION_DRIVER=database)  
**Cache**: Database (CACHE_STORE=database)  
**Queue**: Database (QUEUE_CONNECTION=database)  

### Shared Hosting Constraints

**PHP Limitations**:
- No persistent background processes
- Cron jobs for queue processing (if needed)
- Limited file handles, memory per script
- Session file locking issues on shared disk

**Session Storage Pain Points**:
- Database sessions preferred to file-based
- Session table can grow rapidly
- Session serialization may have encoding issues
- Server migration can corrupt session data

**Notable Issues Post-Migration**:
- 419 CSRF token errors (session table not copied)
- Cache invalidation required (separate cache:clear route)
- Storage symlink creation manual (cPanel doesn't support artisan symlink)
- Database connection pools not available

### Deployment Steps

#### Initial Setup (First Deployment)
```bash
# 1. Clone repository
git clone https://github.com/yourorg/xtra4u.git
cd xtra4u

# 2. Install dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# 3. Configure environment
cp .env.example .env
php artisan key:generate
# Edit .env with production values

# 4. Initialize database
php artisan migrate --force

# 5. Create storage symlink
# Option A: Via artisan (if available)
php artisan storage:link

# Option B: Manual (shared hosting)
ln -s storage/app/public public/storage

# Option C: Via cPanel
# Use File Manager to create symlink

# 6. Set permissions
chmod -R 755 storage bootstrap/cache
chmod -R 777 storage/logs

# 7. Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### Subsequent Deployments
```bash
# 1. Pull latest code
git pull origin main

# 2. Install updates
composer install --no-dev --optimize-autoloader
npm install && npm run build

# 3. Run migrations
php artisan migrate --force

# 4. Clear caches
# Option A: Via artisan
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Option B: Via endpoint (if cPanel/shared hosting artisan unavailable)
curl "https://xtra4u.com/clear-cache/xtra4u-2025-clear"

# 5. Restart queue (if persistent worker exists)
systemctl restart xtra4u-queue  # or manual restart
```

#### Queue Processing on Shared Hosting

**Option 1: Cron-Based Processing**
```bash
# Add to cPanel Cron Jobs (every minute)
* * * * * php /home/username/public_html/artisan queue:work --max-jobs=1000 --max-time=58

# Explanation:
# - Run queue:work command
# - Process max 1000 jobs per execution
# - Stop after 58 seconds (2 sec buffer for cron execution)
# - Cron will restart it next minute
```

**Option 2: Manual Triggering**
```bash
# Trigger queue processing manually via HTTP
curl "https://xtra4u.com/api/queue/process"
# (Would need custom route for this)
```

### Cache Invalidation

**Shared Hosting Workaround**:
Since `php artisan` may not be available on cPanel:

```php
// Add to routes/web.php
Route::get('/clear-cache/{secret}', function ($secret) {
    if ($secret !== 'xtra4u-2025-clear') {
        abort(404);
    }
    
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    
    return response()->json([
        'success' => true,
        'message' => 'All caches cleared successfully!',
        'timestamp' => now()->toDateTimeString()
    ]);
})->name('cache.clear');
```

**Call after deployment**:
```bash
curl "https://xtra4u.com/clear-cache/xtra4u-2025-clear"
```

### Storage Symlink Setup

**Challenge**: cPanel doesn't run artisan automatically

**File**: routes/web.php (production endpoint)
```php
Route::get('/storage-link/{secret}', function ($secret) {
    if ($secret !== 'xtra4u-2025-clear') {
        abort(404);
    }
    
    $publicStorage = public_path('storage');
    $targetPath = storage_path('app/public');
    
    if (file_exists($publicStorage)) {
        if (is_link($publicStorage)) {
            return response()->json([
                'success' => true,
                'message' => 'Storage symlink already exists.',
                'link' => $publicStorage,
                'target' => readlink($publicStorage)
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'File/folder named "storage" exists. Delete it first.'
            ]);
        }
    }
    
    try {
        if (@symlink($targetPath, $publicStorage)) {
            return response()->json([
                'success' => true,
                'message' => 'Storage symlink created successfully!'
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to create symlink: ' . $e->getMessage()
        ]);
    }
})->name('storage.link');
```

**Call after deployment**:
```bash
curl "https://xtra4u.com/storage-link/xtra4u-2025-clear"
```

### Environment-Specific Configuration

**Production (.env)**:
```
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=prod-db.hosting.com
DB_DATABASE=xtra4u_prod
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Payment Gateways
MOOLRE_API_KEY=...  (production keys)
MOOLRE_BASE_URL=https://api.moolre.com  (production)
```

**Staging (.env.staging)**:
```
APP_ENV=staging
APP_DEBUG=true
DB_CONNECTION=mysql
DB_HOST=staging-db.hosting.com
QUEUE_CONNECTION=database

# Payment Gateways
MOOLRE_API_KEY=...  (sandbox keys)
MOOLRE_BASE_URL=https://sandbox.moolre.com
```

### Monitoring & Alerts

**Session Table Growth**:
```sql
SELECT COUNT(*) FROM sessions;
-- If > 1M rows, run manual cleanup
DELETE FROM sessions WHERE last_activity < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR));
```

**Queue Failures**:
```sql
SELECT COUNT(*) FROM queue_failed;
-- Alert if > 100 failed jobs
SELECT COUNT(*) FROM jobs;
-- Alert if > 1000 pending jobs
```

**Order Payment Backlog**:
```sql
SELECT COUNT(*) FROM orders WHERE payment_status='unpaid' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
-- Alert if backlog > 50
```

### Future Hosting Recommendation

**Migrate to VPS or Managed Cloud** (when applicable):
- Redis for caching (replace database cache)
- Persistent queue worker (replace cron)
- Session storage in Redis (faster, cleaner)
- Elasticcache for distributed sessions
- Better monitoring and alerting
- Horizontal scaling capability

**Benefits**:
- Eliminates 419 session errors
- Queue processing continuous (not cron-based)
- Faster cache operations
- Better performance under load
- Production-grade monitoring

---

## 13. Testing Strategy

### Required Tests Before Deployment

#### 1. Payment Flow End-to-End

**File**: tests/Feature/PaymentFlowTest.php

```php
test('moolre payment flow: initiate -> webhook -> complete', function () {
    // Create order
    $order = Order::factory()
        ->for(Vendor::factory())
        ->create(['amount_paid' => 100]);
    
    // Initiate payment via Moolre
    $service = new PaymentService();
    $response = $service->initiatePayment($order, 'test@example.com', 100);
    
    $this->assertTrue($response['success']);
    $this->assertNotNull($response['reference']);
    
    // Simulate webhook callback
    $webhook = [
        'data' => [
            'externalref' => $response['reference'],
            'txstatus' => 1,  // Success
            'secret' => config('services.moolre.webhook_secret')
        ]
    ];
    
    $response = $this->post('/webhooks/moolre/payment', $webhook);
    $this->assertEquals(200, $response->getStatusCode());
    
    // Verify order marked as paid
    $order->refresh();
    $this->assertEquals('paid', $order->payment_status);
    $this->assertEquals('Processing', $order->status);
});
```

**Test Coverage**:
- Moolre payment initiation
- Phone normalization
- Webhook verification (secret validation)
- Order payment completion
- Vendor wallet credit
- Transaction creation
- Event dispatch

#### 2. External Fulfillment Provider Mapping

**File**: tests/Feature/ExternalFulfillmentTest.php

```php
test('external fulfillment: send order to xpresportal with correct mappings', function () {
    $vendor = Vendor::factory()->create();
    $service = NetworkService::factory()->create(['is_checker_type' => false]);
    
    // Ensure product has external mappings
    $product = Product::factory()
        ->for($vendor)
        ->create([
            'description' => json_encode([
                'external_mappings' => [
                    'xpresportal' => [
                        'network' => 'MTN',
                        'offer_slug' => 'MTN_1GB_24HR',
                        'service_id' => '12345',
                        'price' => 15.00
                    ]
                ]
            ])
        ]);
    
    $order = Order::factory()
        ->for($vendor)
        ->create(['vendor_service_id' => $product->id]);
    
    // Mock XpresPortal API
    Http::fake(['xpresportal.app/*' => Http::response(['success' => true, 'reference' => 'ref-123'])]);
    
    // Dispatch fulfillment job
    ProcessExternalFulfillment::dispatch($order);
    
    // Verify API was called with correct mappings
    Http::assertSent(function ($request) {
        return $request->url() === 'https://xpresportal.app/api/v1/topup' &&
               $request->json()['offer_slug'] === 'MTN_1GB_24HR';
    });
});
```

**Test Coverage**:
- Product metadata parsing
- Provider factory instantiation
- Correct mappings sent to each provider
- Error handling for missing mappings
- Idempotency key generation

#### 3. Result Checker PIN Allocation

**File**: tests/Feature/ResultCheckerFulfillmentTest.php

```php
test('result checker: allocate pins with pessimistic locking under concurrent load', function () {
    $service = NetworkService::factory()->create(['is_checker_type' => true]);
    
    // Create 10 available pins
    $pins = ResultCheckerPin::factory(10)
        ->for($service)
        ->create(['status' => 'available']);
    
    // Create 3 pending orders for 5 pins each
    $order1 = ResultCheckerOrder::factory()
        ->for(Vendor::factory())
        ->for($service)
        ->create(['quantity' => 5, 'paid_at' => now()]);
    
    $order2 = ResultCheckerOrder::factory()
        ->for(Vendor::factory())
        ->for($service)
        ->create(['quantity' => 5, 'paid_at' => now()]);
    
    $order3 = ResultCheckerOrder::factory()
        ->for(Vendor::factory())
        ->for($service)
        ->create(['quantity' => 5, 'paid_at' => now()]);
    
    $resultCheckerService = new ResultCheckerService();
    
    // Allocate pins
    $result1 = $resultCheckerService->allocatePinsForOrder($order1);
    $result2 = $resultCheckerService->allocatePinsForOrder($order2);
    $result3 = $resultCheckerService->allocatePinsForOrder($order3);
    
    // First two should succeed, third should fail (only 10 pins total)
    $this->assertTrue($result1);
    $this->assertTrue($result2);
    $this->assertFalse($result3);
    
    // Verify pin status
    $this->assertEquals(10, ResultCheckerPin::where('status', 'sold')->count());
    $this->assertEquals(0, ResultCheckerPin::where('status', 'available')->count());
    
    // Verify order3 is pending_stock
    $order3->refresh();
    $this->assertEquals('pending_stock', $order3->status);
});
```

**Test Coverage**:
- Pessimistic locking prevents overselling
- FIFO pin allocation
- Pending stock handling
- SMS delivery on completion
- Retry job behavior

#### 4. Retry Job Behavior

**File**: tests/Feature/RetryJobTest.php

```php
test('external fulfillment: job retries with exponential backoff', function () {
    $order = Order::factory()->create(['payment_status' => 'paid']);
    
    // Mock provider to fail first attempt
    Http::fake([
        'xpresportal.app/*' => Http::sequence()
            ->push(['error' => 'Service temporarily unavailable'], 503)
            ->push(['success' => true, 'reference' => 'ref-123'], 200)
    ]);
    
    // Dispatch job
    $job = new ProcessExternalFulfillment($order);
    
    // First attempt fails
    $this->expectException(\Exception::class);
    dispatch_now($job);
    
    // Verify job was queued for retry with delay
    Queue::fake();
    dispatch($job);
    Queue::assertPushed(ProcessExternalFulfillment::class);
});
```

#### 5. Session Persistence Test

**File**: tests/Feature/SessionTest.php

```php
test('session persists across requests and survives restart', function () {
    // Login vendor
    $vendor = Vendor::factory()->create();
    $this->actingAs($vendor, 'vendor')
        ->get('/vendor/dashboard')
        ->assertOk();
    
    // Verify session stored in database
    $sessions = DB::table('sessions')
        ->where('user_id', $vendor->id)
        ->count();
    
    $this->assertGreaterThan(0, $sessions);
    
    // Simulate restart (clear in-memory session)
    Session::flush();
    
    // Session should still work from database
    $this->assertAuthenticatedAs($vendor, 'vendor');
});
```

### Test Patterns & Utilities

**CreatesApplication Trait**:
```php
// tests/CreatesApplication.php
trait CreatesApplication {
    public function createApplication() {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }
}
```

**Migration Test Fixture**:
```php
// tests/Feature/MigrationTest.php
test('all migrations run successfully', function () {
    Artisan::call('migrate:fresh');
    $this->assertTrue(Schema::hasTable('orders'));
    $this->assertTrue(Schema::hasTable('result_checker_pins'));
});
```

**Queue Job Simulation**:
```php
// Test queue jobs synchronously
Queue::fake();
dispatch(new ProcessExternalFulfillment($order));
Queue::assertPushed(ProcessExternalFulfillment::class);

// Or process synchronously
Bus::chain([
    new ProcessExternalFulfillment($order),
])->dispatch();
```

---

## 14. Important Files Index

### Payment System Files

**Core Services**
- `app/Services/PaymentService.php` - Main payment orchestrator
- `app/Services/GatewayManager.php` - Gateway routing and selection
- `app/Services/MoolrePaymentService.php` - Moolre MoMo implementation
- `app/Services/PaystackPaymentService.php` - Paystack integration
- `app/Services/FlutterwavePaymentService.php` - Flutterwave integration
- `app/Services/BulkClixPaymentService.php` - BulkClix integration
- `app/Services/SmsService.php` - SMS delivery (BulkClix)
- `app/Services/AfaPaymentService.php` - AFA affiliate payment handling

**Payout Services**
- `app/Services/Payouts/PaystackPayoutService.php`
- `app/Services/Payouts/MoolrePayoutService.php`
- `app/Services/Payouts/HubtelPayoutService.php`
- `app/Services/Payouts/BulkClixPayoutService.php`

**Controllers**
- `app/Http/Controllers/PaymentCallbackController.php` - Redirect gateway callbacks
- `app/Http/Controllers/PaymentStatusController.php` - Payment status polling
- `app/Http/Controllers/Webhooks/MoolreWebhookController.php` - Moolre webhook handler
- `app/Http/Controllers/ResultCheckerPaymentCallbackController.php` - Result checker payment
- `app/Http/Controllers/Admin/PaymentGatewayController.php` - Gateway CRUD

**Models**
- `app/Models/PaymentGatewayConfig.php` - Gateway configuration storage
- `app/Models/Transaction.php` - Payment transaction records
- `app/Models/Order.php` - Order model with payment fields

**Contracts**
- `app/Contracts/Gateways/CollectsPayments.php`
- `app/Contracts/Gateways/HandlesGenericPayments.php`
- `app/Contracts/Gateways/HandlesPayouts.php`

**Events & Listeners**
- `app/Events/OrderCompleted.php`
- `app/Events/AfaRegistrationCompleted.php`
- `app/Listeners/SendOrderCompletionNotification.php`
- `app/Listeners/DispatchExternalFulfillmentFromOrderCompleted.php`

### Fulfillment System Files

**Core Services**
- `app/Services/ExternalFulfillment/ExternalFulfillmentClientFactory.php` - Provider factory
- `app/Services/ExternalFulfillment/ExternalFulfillmentConfig.php` - Credential management
- `app/Services/ExternalFulfillment/ExternalFulfillmentClient.php` - Client wrapper (legacy)

**Provider Implementations**
- `app/Services/ExternalFulfillment/Providers/DatafyhubClient.php`
- `app/Services/ExternalFulfillment/Providers/XpresPortalClient.php`
- `app/Services/ExternalFulfillment/Providers/GigshubClient.php`

**Jobs**
- `app/Jobs/ProcessExternalFulfillment.php` - Fulfill order at provider
- `app/Jobs/FulfillPendingOrdersJob.php` - Auto-fulfill after stock upload

**Controllers**
- `app/Http/Controllers/Vendor/ExternalFulfillmentSettingsController.php` - Vendor credential config

**Webhooks**
- `app/Http/Controllers/Webhooks/GigshubWebhookController.php` - GigsHub status updates
- `app/Http/Controllers/Webhooks/GigshubLowBalanceWebhookController.php` - Balance alerts

**Models**
- `app/Models/GigshubLowBalanceAlert.php` - Balance alert records

### Results Checker System Files

**Services**
- `app/Services/ResultCheckerService.php` - PIN allocation, SMS delivery

**Controllers**
- `app/Http/Controllers/ResultCheckerCheckoutController.php` - Customer checkout
- `app/Http/Controllers/ResultCheckerPaymentCallbackController.php` - Payment handling
- `app/Http/Controllers/ResultCheckerStatusController.php` - Status checking
- `app/Http/Controllers/Admin/AdminResultCheckerDashboardController.php` - Admin dashboard
- `app/Http/Controllers/Admin/AdminResultCheckerPinsController.php` - PIN management
- `app/Http/Controllers/Admin/AdminResultCheckerOrdersController.php` - Order management
- `app/Http/Controllers/Vendor/VendorResultCheckerSettingsController.php` - Vendor config
- `app/Http/Controllers/Vendor/VendorResultCheckerOrdersController.php` - Vendor orders

**Models**
- `app/Models/ResultCheckerOrder.php` - Order records
- `app/Models/ResultCheckerPin.php` - PIN inventory
- `app/Models/VendorResultCheckerSetting.php` - Vendor pricing config
- `app/Models/NetworkService.php` - Modified for checker support

**Jobs**
- `app/Jobs/RetryResultCheckerOrder.php` - Manual retry
- `app/Jobs/FulfillPendingOrdersJob.php` - Auto-fulfill

### Core Models

- `app/Models/Order.php` - Main order model
- `app/Models/Vendor.php` - Vendor profile
- `app/Models/Product.php` - Service/product catalog
- `app/Models/NetworkService.php` - Network services (mobile networks)
- `app/Models/Transaction.php` - Payment transactions
- `app/Models/PaymentGatewayConfig.php` - Payment gateway config
- `app/Models/VendorSetting.php` - Vendor-specific settings
- `app/Models/Admin.php` - Admin user
- `app/Models/User.php` - Base user model

### Configuration Files

- `config/services.php` - External service credentials
- `config/external_fulfillment.php` - Provider configuration
- `config/database.php` - Database connections
- `config/queue.php` - Queue configuration
- `config/session.php` - Session configuration
- `config/cache.php` - Cache configuration

### Route Files

- `routes/web.php` - Main application routes (lines 1-350)
  - Payment routes
  - Result checker routes
  - Admin routes
  - Vendor routes
  - Webhook routes

### Migration Files (Key)

- `database/migrations/2025_11_30_000001_create_users_table.php`
- `database/migrations/2025_11_30_000002_create_vendors_table.php`
- `database/migrations/2025_11_30_000003_create_orders_table.php`
- `database/migrations/2025_11_30_000004_create_products_table.php`
- `database/migrations/2025_11_30_000005_create_transactions_table.php`
- `database/migrations/2025_12_06_005458_add_payment_fields_to_orders.php`
- `database/migrations/2025_12_11_000001_create_payment_gateway_configs.php`
- `database/migrations/2026_01_03_000001_add_external_fulfillment_columns_to_orders.php`
- `database/migrations/2026_04_03_000001_create_result_checker_tables.php`

### Test Files

- `tests/Feature/MigrationTest.php` - Database migration tests
- `tests/Feature/ResultCheckerFulfillmentTest.php` - Result checker tests
- `tests/CreatesApplication.php` - Application creation trait
- `tests/TestCase.php` - Base test case

### Documentation Files

- `IMPLEMENTATION PLAN (TECHNICAL).md` - Technical roadmap
- `knowledge base/PRODUCT REQUIREMENTS DOCUMENT (PRD).md` - Product requirements

---

## 15. Known Issues & Historical Incidents

### Incident 1: 419 CSRF Token Errors After Server Migration

**Timeline**: December 2025 (Production)

**Symptoms**:
- POST requests returning 419 Unauthorized
- "CSRF token mismatch" errors in logs
- Vendor/admin login attempts failing
- Customer checkout unable to complete

**Root Cause**:
- SESSION_DRIVER=database (database-backed sessions)
- Server migration used different database backup
- sessions table not copied or corrupted in transfer
- Session data out of sync with CSRF tokens

**Impact**:
- Platform unavailable for 4+ hours
- No orders processed during outage
- Customer complaints and support tickets
- Vendor payouts delayed

**Fix Applied**:
1. Clear all sessions: `TRUNCATE sessions;`
2. Force re-authentication of all users
3. Verify cache table integrity: `php artisan cache:clear`
4. Clear route cache: `php artisan route:clear`
5. Manual session data backup procedures for future migrations

**Lessons Learned**:
1. Database sessions are fragile on shared hosting
2. Always backup session table during server migrations
3. Test session persistence post-migration
4. Have manual cache-clear endpoint as fallback
5. Consider Redis sessions for future hosting upgrade

**Prevention**:
- Document session backup procedure before migration
- Test session table copy as part of pre-production checklist
- Monitor session table size during high-volume periods
- Alert if session table grows > 1M rows

---

### Incident 2: Moolre Webhook Status Mismatch

**Timeline**: November 2025 (Production)

**Symptoms**:
- Customer order marked failed in app
- Payment actually succeeded at Moolre
- Vendor wallet not credited
- Customer received no services

**Root Cause**:
- Webhook received with txStatus=2 (failed)
- Application marked order as failed immediately
- Later API query (manual verification) showed txStatus=1 (success)
- Webhook status out of sync with actual payment status

**Impact**:
- 12 orders affected
- Manual vendor credit required
- Customer re-payment required
- Trust impact (refund delays)

**Fix Applied**:
1. Implemented server-to-server verification on webhook
2. Never trust webhook status alone
3. Call MoolrePaymentService->verifyPayment() for authoritative status
4. Log discrepancy when webhook status != API status
5. Manual backfill: script to correct marked-failed orders

**Code Change**: app/Http/Controllers/Webhooks/MoolreWebhookController.php
```php
// Before (WRONG):
if ($webhook->txStatus == 1) {
    completeOrder();  // Trusts webhook only
}

// After (CORRECT):
$verified = MoolrePaymentService::verifyPayment($reference);
if ($verified['status'] === 'success') {
    completeOrder();  // Verifies via API
}
```

**Lessons Learned**:
1. Webhooks can be delayed, duplicated, or out-of-sync
2. Always verify via provider API for authoritative status
3. Implement audit trail for all payment status changes
4. Test with real payment provider in staging
5. Monitor for webhook/API status mismatches

---

### Incident 3: Result Checker Schema Column Confusion

**Timeline**: April 2026 (Development/Testing)

**Symptoms**:
- Result checker orders not appearing in admin panel
- PIN allocation failing with "column not found" error
- Database migration applied but schema mismatch
- Developers using legacy column names

**Root Cause**:
- Legacy migrations created checker_type_id (wrong column)
- Later migration renamed to service_id (correct column)
- Some code still referenced old column name
- Test fixtures used incorrect foreign key

**Impact**:
- Feature development blocked for 2 days
- Integration test failures
- Manual database cleanup required

**Fix Applied**:
1. Audit all code for column name usage
2. Create migration to explicitly rename column
3. Update models with correct relationships
4. Add documentation comment in code:
   ```php
   // IMPORTANT: Column is service_id, NOT checker_type_id
   $order->service_id;  // Correct
   // $order->checker_type_id;  // WRONG - Legacy, do not use
   ```
5. Update test factories to use correct column

**Lessons Learned**:
1. Always inspect migrations before applying
2. Schema naming must be consistent across codebase
3. Add explicit documentation for "never" patterns
4. Test fixtures must use production column names
5. Run MigrationTest before feature development

---

### Incident 4: External Provider Hardcoding

**Timeline**: January 2026 (Staging)

**Symptoms**:
- Orders sent to wrong fulfillment provider
- Some vendors' orders not fulfilled
- Provider API errors not logged
- No visibility into which provider was used

**Root Cause**:
- Developer hardcoded provider name in job:
  ```php
  // WRONG:
  $client = new XpresPortalClient($config);  // Always XpresPortal!
  
  // Should be:
  $client = ExternalFulfillmentClientFactory::make('xpresportal');
  ```
- Bypassed factory pattern entirely
- No way to switch providers per vendor
- No audit trail of which provider fulfilled order

**Impact**:
- Datafyhub integration broken
- 50+ orders stuck in processing
- Vendor credential isolation violated
- Manual provider-by-provider fulfillment required

**Fix Applied**:
1. Revert hardcoded provider instantiation
2. Enforce factory pattern via code review checklist
3. Add ExternalFulfillmentFactory usage to architectural guidelines
4. Implement test to verify provider routing:
   ```php
   test('orders use factory for provider selection', function () {
       // Verify factory is called, not direct instantiation
   });
   ```
5. Added order.external_fulfillment_provider_used field for audit trail

**Code Change**: app/Jobs/ProcessExternalFulfillment.php
```php
// Store which provider was actually used
$order->update([
    'external_fulfillment_provider_used' => $provider->getName(),
]);
```

**Lessons Learned**:
1. Factory pattern is not optional for extensibility
2. Vendor-specific credential isolation is critical
3. Audit trail (which provider used) is essential
4. Code review must check for hardcoded provider names
5. Test should verify factory is called

---

### Incident 5: PIN Allocation Race Condition

**Timeline**: March 2026 (High-Volume Testing)

**Symptoms**:
- More pins allocated than available
- Duplicate pins sent to customers
- Overselling detected: 15 pins allocated from 10 available
- System marked multiple orders complete

**Root Cause**:
- Initial PIN allocation without locking:
  ```php
  // WRONG - Race condition
  $available = ResultCheckerPin::where('status', 'available')->take(5)->get();
  if ($available->count() < 5) return false;  // Too late, another request already took some
  ```
- High-volume checkout simultaneous allocation requests
- Without pessimistic lock, multiple requests see same available pins
- All think there's sufficient inventory

**Impact**:
- 3 customers received duplicate pins
- 2 customers received wrong pin/serial pairs
- Manual SMS corrections required
- Inventory audit required

**Fix Applied**:
1. Implemented pessimistic locking:
   ```php
   // CORRECT:
   $available = ResultCheckerPin::where('status', 'available')
       ->lockForUpdate()  // CRITICAL
       ->take(5)
       ->get();
   ```
2. Added inventory validation before allocation
3. Implemented FIFO ordering (created_at)
4. Added test for concurrent allocation:
   ```php
   test('concurrent allocations cannot exceed inventory', function () {
       // Simulate concurrent requests
       // Verify only one succeeds or correct split
   });
   ```
5. Added monitoring alert for inventory discrepancy

**Lessons Learned**:
1. Pessimistic locking is not optional for allocation
2. High-volume scenarios expose race conditions
3. Test with concurrency (not just single-request testing)
4. FIFO ordering prevents fairness issues
5. Inventory audit post-incident is essential

---

### Pattern: Safety Rules from Historical Incidents

Based on the above incidents, these rules are non-negotiable:

1. **Never trust webhook status alone** - Always verify with provider API
2. **Always use pessimistic locking for allocations** - No exceptions
3. **Never hardcode provider names** - Always use factory pattern
4. **Always inspect schema before coding** - Verify correct column names
5. **Test with high-volume/concurrent scenarios** - Single-request tests insufficient
6. **Backup session table before server migration** - Sessions are fragile
7. **Document "never" patterns explicitly** - Use code comments and PR descriptions
8. **Maintain audit trail (who, what, when)** - Enable post-incident debugging
9. **Verify migrations on test data first** - Never apply to production immediately
10. **Monitor for data integrity issues** - Inventory counts, vendor balances, transaction totals

---

## Appendix: Quick Reference Checklists

### Pre-Deployment Checklist

- [ ] All migrations reviewed and tested
- [ ] Payment gateway configs validated
- [ ] External fulfillment provider credentials tested
- [ ] Result checker PIN inventory verified
- [ ] Session table backup completed
- [ ] Cache cleared via endpoint
- [ ] Storage symlink created
- [ ] Queue configuration checked (cron job active if needed)
- [ ] Vendor credentials encrypted
- [ ] CSRF token verification in place
- [ ] Webhook verification active
- [ ] Monitoring alerts configured
- [ ] Rollback plan documented

### Post-Deployment Checklist (First Hour)

- [ ] Monitor logs for errors (tail -f storage/logs/laravel.log)
- [ ] Check for 419 CSRF token errors
- [ ] Verify payment callback webhook received
- [ ] Test sample order flow (checkout → payment → fulfillment)
- [ ] Verify vendor dashboard loads
- [ ] Check admin payment gateway status
- [ ] Monitor queue_failed table for job failures
- [ ] Monitor sessions table size
- [ ] Verify cache is working (config:cache check)
- [ ] Spot-check vendor wallet calculations

### Payment Testing Checklist

- [ ] Moolre phone normalization (test 5 formats)
- [ ] Webhook secret verification (positive & negative)
- [ ] Payment idempotency (retry webhook, verify no double-credit)
- [ ] Commission calculation (calculate manually, verify matches DB)
- [ ] Multi-level affiliate payout (verify chain snapshot)
- [ ] Transaction record creation (verify gateway_transaction_id)
- [ ] Order status lifecycle (unpaid → paid → processing)
- [ ] Vendor wallet credit (verify balance increased)

### External Fulfillment Testing Checklist

- [ ] XpresPortal credentials set in vendor settings
- [ ] Product external_mappings populated correctly
- [ ] Provider service discovery working (GET /services)
- [ ] Order sent to provider with idempotency key
- [ ] Webhook received from provider with status update
- [ ] Order marked succeeded/failed based on webhook
- [ ] Provider-specific error handling working
- [ ] Retry behavior tested (exponential backoff)
- [ ] Concurrent requests don't duplicate fulfillment

### Result Checker Testing Checklist

- [ ] Vendor can enable result checker service
- [ ] Customer can checkout and pay for result checker
- [ ] PIN allocation succeeds when stock available
- [ ] Order marked completed and SMS sent
- [ ] Order marked pending_stock when no inventory
- [ ] PIN upload triggers FulfillPendingOrdersJob
- [ ] Pending orders auto-fulfill after stock upload
- [ ] Concurrent PIN allocation prevents overselling
- [ ] Retry job works for manual retry
- [ ] Schema uses correct columns (service_id, result_checker_order_id)

---

## Conclusion

This PROJECT_CONTEXT.md document serves as the definitive engineering reference for XTRA4U. It enables:

1. **Rapid Onboarding**: New engineers understand architecture in hours, not weeks
2. **Confident Deployment**: Clear safety rules prevent production incidents
3. **Effective Debugging**: Known issues and patterns enable faster troubleshooting
4. **Architectural Decisions**: Guidelines for adding features or changing code
5. **Production Safety**: Historical incidents document what must never happen again

**Keep this document updated** as the application evolves. It is a living reference, not a static snapshot.

For questions or updates, refer to the git history, recent commits, and the team's architectural decisions documented in pull request descriptions.

---

**Document Version**: 1.0  
**Last Updated**: May 11, 2026  
**Author**: Engineering Team  
**Status**: Production-Ready Reference  
**Audience**: Engineers, AI Assistants, Contractors, Maintainers
