                         ┌───────────────────────────┐
                         │         Customers         │
                         │  (No account required)    │
                         └──────────────┬────────────┘
                                        │
                                        ▼
                     ┌──────────────────────────────────────┐
                     │        Customer Web Interface        │
                     │  (Blade Templates + Tailwind CSS)    │
                     └──────────────┬───────────────────────┘
                                    │
                                    ▼
                     ┌──────────────────────────────────────┐
                     │        Purchase Controller           │
                     │  - Show services                     │
                     │  - Handle checkout inputs            │
                     │  - Trigger payment initialization     │
                     └──────────────┬───────────────────────┘
                                    │
                                    ▼
                    ┌──────────────────────────────────────────┐
                    │            Payment API Layer             │
                    │    (MTN MOMO / Paystack / Flutterwave)  │
                    │                                          │
                    │ - Initialize payment                     │
                    │ - Redirect/confirm transaction           │
                    │ - Callback verification                  │
                    └──────────────┬───────────────────────────┘
                                   │ CALLBACK
                                   ▼
                     ┌─────────────────────────────────────────┐
                     │        Order Processing Service         │
                     │ - Create Order                          │
                     │ - Log Transaction                       │
                     │ - Deduct 1% commission                  │
                     │ - Allocate vendor earnings              │
                     │ - Update order status                   │
                     └──────────────┬──────────────────────────┘
                                    │
                                    ▼
                         ┌────────────────────────────┐
                         │       MySQL Database       │
                         │────────────────────────────│
                         │ vendors                    │
                         │ vendor_requests            │
                         │ products                   │
                         │ orders                     │
                         │ transactions               │
                         │ commissions                │
                         │ users (admin/vendor)       │
                         │ admin settings             │
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
        │ (Blade + Alpine.js)                  │      │  xtra4u.com/store/<name>  │
        │                                      │      │  Public storefront         │
        │ - Sales metrics                       │      │  Product listing          │
        │ - Commission summary                  │      │  Checkout                 │
        │ - Product management                  │      └───────────────────────────┘
        │ - Order management                    │
        │ - Earnings & withdrawals              │
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
                    │ (Laravel Blade / Filament optional)      │
                    │                                          │
                    │ - Approve/Reject vendors                 │
                    │ - Manage products                        │
                    │ - View platform sales                    │
                    │ - Commission/Revenue analytics           │
                    │ - Order monitoring                       │
                    │ - Vendor suspension                      │
                    └──────────────────────────────────────────┘


EXPLANATION (High-Level)
1. Presentation Layer (Frontend)

Blade templates (web UI)

Tailwind CSS (design)

Alpine.js (dynamic components)

UX for:

Customers

Vendor dashboard

Admin dashboard

2. Application Layer (Backend – Laravel)

Controllers (purchase, vendor, admin, order, transaction)

Services:

Payment service

Order processing

Commission calculation

Middleware:

Vendor approval check

Admin authentication

3. Integration Layer

Payment Gateway APIs:

MTN MOMO

Paystack

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