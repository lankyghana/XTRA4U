# XTRA4U System Architecture
**Last Updated:** December 14, 2025

## High-Level Architecture Overview

                         ┌───────────────────────────┐
                         │         Customers         │
                         │  (No account required)    │
                         └──────────────┬────────────┘
                                        │
                                        ▼
                     ┌──────────────────────────────────────┐
                     │        Customer Web Interface        │
                     │  (Blade + Tailwind + Alpine.js)      │
                     │                                      │
                     │  - Vendor Storefronts                │
                     │  - Service Marketplace               │
                     │  - AFA Registration                  │
                     │  - Static Pages (About/Privacy/TOS)  │
                     └──────────────┬───────────────────────┘
                                    │
                                    ▼
                     ┌──────────────────────────────────────┐
                     │         Route Controllers            │
                     │  - StorefrontController              │
                     │  - CheckoutController                │
                     │  - AfaRegistrationController         │
                     │  - PaymentCallbackController         │
                     └──────────────┬───────────────────────┘
                                    │
                                    ▼
                    ┌──────────────────────────────────────────┐
                    │          GatewayManager                  │
                    │     (Dynamic Gateway Selection)          │
                    │                                          │
                    │  - Reads active gateway from DB          │
                    │  - Routes to appropriate service         │
                    │  - Handles fallback logic                │
                    └──────────────┬───────────────────────────┘
                                   │
                        ┌──────────┼──────────┐
                        ↓          ↓          ↓
              ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
              │  Paystack    │ │ Flutterwave  │ │   Hubtel     │
              │   Service    │ │   Service    │ │   Service    │
              └──────┬───────┘ └──────┬───────┘ └──────┬───────┘
                     │                │                │
                     └────────────────┼────────────────┘
                                      │
                            Payment Redirect/Callback
                                      │
                                      ▼
                     ┌─────────────────────────────────────────┐
                     │      TransactionService                 │
                     │                                         │
                     │ - Verify payment signature              │
                     │ - Create Order record                   │
                     │ - Log Transaction                       │
                     │ - Calculate commissions (2%)            │
                     │ - Handle AFA reseller splits            │
                     │ - Update vendor balances                │
                     │ - Trigger notifications                 │
                     └──────────────┬──────────────────────────┘
                                    │
                                    ▼
                         ┌────────────────────────────┐
                         │       MySQL Database       │
                         │────────────────────────────│
                         │ users (admins)             │
                         │ vendors                    │
                         │ products                   │
                         │ reseller_products          │
                         │ orders                     │
                         │ transactions               │
                         │ afa_registrations          │
                         │ network_services           │
                         │ vendor_withdrawals         │
                         │ vendor_notifications       │
                         │ admin_notifications        │
                         │ payment_gateway_configs    │
                         │ settings                   │
                         │ jobs (queue)               │
                         └──────────────┬─────────────┘
                                        │
                                        ▼
            ┌───────────────────────────────────────────────────────────────────┐
            │                           Vendor System                           │
            └───────────────────────────────────────────────────────────────────┘
                           │                                     │
                           ▼                                     ▼

        ┌──────────────────────────────────────┐      ┌───────────────────────────┐
        │          Vendor Dashboard             │      │      Vendor Store Link    │
        │ (Blade + Alpine.js + Tailwind)       │      │  /store/{vendor_code}     │
        │                                      │      │                           │
        │ - Sales metrics & analytics           │      │  - Public storefront       │
        │ - Product management (CRUD)           │      │  - Service categories     │
        │ - Order management & tracking         │      │  - Product listings       │
        │ - AFA settings (Direct/Reseller)      │      │  - Live checkout          │
        │ - Settings (Profile/Payout/Password)  │      │  - Vendor branding        │
        │ - Withdrawal requests                 │      └───────────────────────────┘
        │ - Notification center                 │
        │ - Store link management               │
        └──────────────────┬────────────────────┘
                           │
                           ▼
             ┌─────────────────────────────────────┐
             │      Vendor Authentication Layer    │
             │  (Laravel Auth + Middleware)        │
             │ - Vendor approval check              │
             │ - Access control                     │
             └─────────────────────────────────────┘

                                        │
                                        ▼
                    ┌──────────────────────────────────────────┐
                    │                 Admin Panel               │
                    │          (Laravel Blade + Tailwind)      │
                    │                                          │
                    │ - Vendor approval workflow               │
                    │ - Platform analytics & reports           │
                    │ - Order monitoring & management          │
                    │ - Commission tracking (2%)               │
                    │ - Payment gateway configuration          │
                    │ - System settings management             │
                    │ - User/Vendor management                 │
                    │ - Notification center                    │
                    │ - Audit logs & activity tracking         │
                    └──────────────────────────────────────────┘


---

## Detailed Layer Breakdown

### 1. Presentation Layer (Frontend)

**Technologies:**
- Blade templates for server-side rendering
- Tailwind CSS for responsive design
- Alpine.js (with Collapse plugin) for interactivity
- Vite for asset bundling

**User Interfaces:**
- **Customer Interface:**
  - Vendor storefronts
  - Service marketplace
  - Checkout flows
  - AFA registration
  - Static pages (About, Privacy, Terms)

- **Vendor Dashboard:**
  - Sales analytics
  - Product management
  - Order tracking
  - Settings & configuration
  - Withdrawal requests
  - Notification center

- **Admin Panel:**
  - Vendor approvals
  - Platform analytics
  - System configuration
  - Order monitoring

### 2. Application Layer (Backend – Laravel)

**Controllers:**
- `StorefrontController` - Public marketplace
- `CheckoutController` - Purchase flows
- `VendorDashboardController` - Vendor operations
- `VendorAfaController` - AFA management
- `AfaRegistrationController` - AFA orders
- `PaymentCallbackController` - Payment webhooks
- `AdminController` - Admin operations

**Services:**
- `GatewayManager` - Payment gateway selection
- `PaymentService` - Gateway abstraction
- `PaystackPaymentService` - Paystack integration
- `FlutterwavePaymentService` - Flutterwave integration
- `HubtelPaymentService` - Hubtel integration
- `TransactionService` - Transaction processing
- `MomoPayoutService` - MTN MoMo payouts
- `SmsService` - SMS notifications

**Middleware:**
- `VendorAuthentication` - Vendor auth check
- `VendorApprovalCheck` - Approval verification
- `AdminAuthentication` - Admin auth check

**Events & Listeners:**
- `OrderCompleted` → `SendOrderCompletionNotification`
- Queue-based async processing

**Console Commands:**
- `CleanupPendingPayments` - Runs every 6 hours

### 3. Integration Layer

**Payment Gateway APIs:**
- **Paystack** - Card payments, mobile money
- **Flutterwave** - Alternative payment gateway
- **Hubtel** - Mobile money payments
- **MTN MoMo API** - Direct payouts to vendors

**Communication Services:**
- **BulkClix SMS API** - Order notifications
- **SMTP Email** - Transactional emails

**Queue System:**
- Database-driven job queue
- Async processing for emails, SMS, webhooks
- Failed job tracking and retry logic

---

## Data Flow Examples

### Example 1: Customer Purchase Flow

```
1. Customer visits /store/VENDOR123
2. Selects MTN 5GB Data Bundle (GHS 20)
3. Enters recipient phone + payment details
4. CheckoutController creates pending order
5. GatewayManager selects active gateway (Paystack)
6. PaystackPaymentService initializes payment
7. Customer redirected to Paystack payment page
8. Customer completes payment
9. Paystack calls webhook with payment confirmation
10. PaymentCallbackController verifies signature
11. TransactionService:
    - Updates order status to 'completed'
    - Creates transaction record
    - Calculates commission (GHS 20 * 2% = GHS 0.40)
    - Updates vendor balance (GHS 19.60)
12. OrderCompleted event fired
13. SendOrderCompletionNotification listener queued
14. Email & SMS sent to customer
15. Vendor sees order in dashboard
```

### Example 2: AFA Reseller Purchase Flow

```
Scenario: Reseller sells AFA at GHS 60 (Base: GHS 50, Markup: GHS 10)

1. Customer visits reseller's AFA page
2. Fills registration form (name, phone, index number)
3. Clicks "Pay GHS 60"
4. Payment processed via gateway
5. TransactionService calculates split:
   - Source Vendor: GHS 50 - 2% = GHS 49.00
   - Reseller: GHS 10 - 2% = GHS 9.80
   - Platform: GHS 0.40 + GHS 0.20 = GHS 1.20
6. Two separate transactions created
7. Both vendors see their portion in dashboard
8. Customer receives AFA registration confirmation
```

### Example 3: Vendor Withdrawal Flow

```
1. Vendor requests withdrawal of GHS 500
2. Admin approves request
3. MomoPayoutService initiates MTN MoMo transfer
4. API returns transaction ID
5. Vendor balance updated
6. Withdrawal marked as 'completed'
7. Vendor receives notification
8. MoMo payment arrives in vendor's account
```

---

## Key Business Rules

### Commission Structure
- **Platform Fee:** 2% on all transactions
- **Applied to:** Both source vendors and resellers
- **Minimum:** No minimum transaction amount
- **Calculation:** Deducted before crediting vendor balance

### AFA Reseller Rules
- Reseller must select an approved source vendor
- Markup must be >= GHS 0 (can be zero)
- Total price = Base price + Markup
- Both parties see commission deduction
- Split payment processed atomically

### Order Status Lifecycle
```
pending → processing → completed
                     ↓
                  failed
                     ↓
                 cancelled (auto after 24hrs)
```

### Vendor Approval States
```
pending → approved → active
          ↓
       rejected
```

---

## Security Architecture

### Authentication
- **Admin:** Custom guard with session-based auth
- **Vendor:** Custom guard with remember token
- **Customer:** No authentication required (guest checkout)

### Authorization
- **Policies:** VendorPolicy for resource access control
- **Middleware:** Route protection for vendor/admin areas
- **CSRF:** All POST forms include CSRF token

### Payment Security
- **Webhook Verification:** Signature validation on callbacks
- **HTTPS:** Enforced in production
- **Environment Isolation:** Sensitive keys in .env
- **Rate Limiting:** Applied to auth and payment routes

### Data Protection
- **Password Hashing:** Bcrypt with salt
- **SQL Injection:** Prevented via Eloquent ORM
- **XSS Prevention:** Blade auto-escaping
- **Soft Deletes:** Preserve data for audit trails

---

## Performance Considerations

### Caching Strategy
- **Config Cache:** `php artisan config:cache`
- **Route Cache:** `php artisan route:cache`
- **View Cache:** Compiled Blade templates
- **Query Optimization:** Eager loading relationships

### Queue Processing
- **Background Jobs:** Email, SMS, webhooks
- **Worker:** Supervisor/systemd for reliability
- **Failed Jobs:** Retry with exponential backoff
- **Monitoring:** Queue depth and failed job alerts

### Database Optimization
- **Indexes:** Foreign keys, status columns, vendor_code
- **Pagination:** Large result sets paginated
- **Soft Deletes:** Indexed for query performance
- **Connection Pooling:** For high-traffic scenarios

---

## Scalability Path

### Horizontal Scaling
- Load balancer in front of multiple app servers
- Shared storage for uploaded files (S3/Cloud)
- Redis for session management across servers
- Database read replicas for reporting

### Queue Scaling
- Switch from database to Redis queue
- Multiple queue workers for parallelization
- Separate queues for different job types
- Job prioritization (high/medium/low)

### Monitoring & Observability
- Application logs to centralized service
- Performance monitoring (New Relic/DataDog)
- Error tracking (Sentry/Bugsnag)
- Uptime monitoring (Pingdom/UptimeRobot)

Flutterwave

Incoming callbacks update order status

4. Data Layer

MySQL database for:

Vendors

Vendor requests

Orders

Transactions

Commissions

Users

Admin settings

5. Administration Layer

Admin dashboard for full platform control

Vendor approval

Revenue and commission tracking

Order monitoring