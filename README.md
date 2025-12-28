# XTRA4U

<div align="center">

**Enterprise Digital Services Marketplace**

*Empowering vendors and customers across Ghana with seamless digital transactions*

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg)](LICENSE)

[Features](#-key-features) • [Architecture](#-platform-architecture) • [Scalability](#-scalability--performance) • [Installation](#-installation--setup) • [Documentation](#-documentation)

</div>

---

## 📋 Table of Contents

- [Project Overview](#-project-overview)
- [Company & Legal Information](#-company--legal-information)
- [Key Features](#-key-features)
- [Platform Architecture](#-platform-architecture)
- [User Roles](#-user-roles)
- [Order & Transaction Flow](#-order--transaction-flow)
- [Technology Stack](#-technology-stack)
- [Scalability & Performance](#-scalability--performance)
- [Installation & Setup](#-installation--setup)
- [Environment Configuration](#-environment-configuration)
- [Payment & Gateway Systems](#-payment--gateway-systems)
- [Security Considerations](#-security-considerations)
- [Folder Structure](#-folder-structure)
- [Development Guidelines](#-development-guidelines)
- [License & Ownership](#-license--ownership)

---

## 🎯 Project Overview

**XTRA4U** is a comprehensive multi-vendor digital services marketplace designed specifically for the Ghanaian market. The platform enables seamless purchasing of digital services including mobile data bundles, airtime top-ups, utility payments, and specialized services like the innovative **AFA Registration System**.

### What Makes XTRA4U Unique

- **Frictionless Customer Experience** - No account required for purchases
- **Vendor-Centric Design** - Powerful tools for independent digital service providers
- **Multi-Level Affiliate System** - Advanced reseller capabilities with automated commission splitting
- **Dynamic Payment Gateway Management** - Database-driven, hot-swappable payment providers
- **Real-Time Processing** - Queue-based architecture for scalable transaction handling
- **Comprehensive Admin Control** - Full platform oversight with analytics and vendor management

---

## 🏢 Company & Legal Information

**Registered Company Name:** Richprime Services  
**Registration Number:** BN582881225  
**Registered Under:** Registrar General's Department of Ghana  
**Nature of Business:** Digital Services Marketplace Platform  
**Platform Name:** XTRA4U  

**Legal Status:** Fully registered and compliant with Ghanaian business regulations.

---

## ✨ Key Features

### For Customers

#### 🛒 Account-Free Shopping
- Purchase digital services without registration
- Instant checkout with mobile money integration
- Real-time order confirmation via SMS and email
- Order tracking with unique reference codes

#### 🏪 Vendor Storefronts
- Browse individual vendor stores at `/store/{vendor_code}`
- Compare prices across multiple vendors
- Filter products by network, category, and price
- Responsive mobile-first design

#### 📱 Comprehensive Service Catalog
- **Mobile Data Bundles** - All major networks (MTN, Vodafone, AirtelTigo)
- **Airtime Top-ups** - Instant crediting
- **Utility Payments** - ECG, Ghana Water, etc.
- **AFA Registration** - Specialized exam results checking service

### For Vendors

#### 📊 Advanced Dashboard
- Real-time sales metrics and analytics
- Earnings overview with commission breakdown
- Sales filtering (Today, Yesterday, Week, Month, All Time)
- Order management with status tracking
- Transaction history with detailed reporting

#### 🛍️ Product Management
- Create and manage unlimited digital products
- Set custom pricing and stock levels
- Network service integration
- Bulk product operations
- Product activation/deactivation controls

#### 🤝 Multi-Tier Affiliate System
- **Direct Provider Mode** - Set your own prices, earn directly
- **Reseller Mode** - Source from approved providers, add markup
- **Automated Commission Splitting** - 
  - Platform: 2% on all transactions
  - Provider: Base price - 2%
  - Reseller: Markup amount - 2%
- **AFA Reseller Capabilities** - Become a provider or resell with custom pricing

#### 💰 Financial Management
- Wallet balance tracking
- Withdrawal requests with mobile money payout
- Commission transparency
- Automated payout processing via queue system
- Support for multiple mobile money networks (MTN, Vodafone, AirtelTigo)

#### 🔔 Notification System
- Real-time order notifications
- Email alerts for new purchases
- In-app notification center
- Withdrawal status updates

### For Administrators

#### ✅ Vendor Management
- Review and approve/reject vendor applications
- Vendor performance monitoring
- Account suspension/activation controls
- Bulk vendor operations

#### 📈 Platform Analytics
- Revenue tracking and reporting
- Commission monitoring
- Vendor performance metrics
- Transaction analytics
- AFA registration statistics

#### 🔧 System Configuration
- **Payment Gateway Management** - Configure multiple providers (Paystack, Flutterwave, Hubtel, Moolre)
- **Gateway Hot-Swapping** - Switch active payment providers without code changes
- **SMS Provider Configuration** - BulkClix integration for notifications
- **Network Services** - Manage available telecom networks
- **Platform Settings** - Global configuration management

#### 🗄️ Data Management
- Automated cleanup of abandoned carts (24-hour retention)
- Transaction archival
- Database optimization tools
- Audit logging

---

## 🏗️ Platform Architecture

### High-Level System Design

```
┌─────────────────────────────────────────────────────────────────┐
│                        CUSTOMER LAYER                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │  Storefront  │  │  Marketplace │  │ AFA Register │         │
│  └──────────────┘  └──────────────┘  └──────────────┘         │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│                     APPLICATION LAYER                           │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              LARAVEL FRAMEWORK (12.x)                     │  │
│  │  ┌─────────────┐  ┌─────────────┐  ┌──────────────────┐ │  │
│  │  │ Controllers │  │  Services   │  │  Jobs (Queued)   │ │  │
│  │  └─────────────┘  └─────────────┘  └──────────────────┘ │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│                      SERVICE LAYER                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │GatewayManager│  │PaymentService│  │ SmsService   │         │
│  └──────┬───────┘  └──────────────┘  └──────────────┘         │
│         │                                                        │
│    ┌────▼─────┬─────────┬──────────┬──────────┐               │
│    │ Paystack │Flutterw.│  Hubtel  │  Moolre  │               │
│    └──────────┴─────────┴──────────┴──────────┘               │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│                       DATA LAYER                                │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                  MySQL / SQLite Database                  │  │
│  │  Vendors│Products│Orders│Transactions│AfaRegistrations   │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### Core Architectural Principles

1. **Gateway Abstraction** - Payment providers are abstracted through a unified `GatewayManager` interface
2. **Queue-Based Processing** - Async job handling for withdrawals, notifications, and heavy operations
3. **Polymorphic Transactions** - Flexible transaction model supporting multiple order types
4. **Dynamic Configuration** - Runtime gateway selection from database without code deployment
5. **Event-Driven Notifications** - Laravel events for order completion, vendor approval, etc.

---

## 👥 User Roles

### 1. **Customer** (Unauthenticated)
**Access Level:** Public  
**Capabilities:**
- Browse vendor storefronts
- View product catalog
- Make purchases without account
- Track orders via reference code
- Register for AFA services

### 2. **Vendor** (Authenticated)
**Access Level:** Vendor Dashboard  
**Capabilities:**
- Manage product inventory
- View sales analytics
- Process orders
- Request withdrawals
- Configure reseller settings
- Manage AFA registrations (provider/reseller)

### 3. **Admin** (Authenticated)
**Access Level:** Full Platform Control  
**Capabilities:**
- Approve/reject vendor applications
- Configure payment gateways
- Monitor platform analytics
- Manage network services
- Process withdrawal requests
- System configuration

---

## 🔄 Order & Transaction Flow

### Standard Product Purchase Flow

```
1. Customer Selection
   ↓
2. Add to Cart (Session-based)
   ↓
3. Checkout (No login required)
   ↓
4. Payment Gateway Selection (Automatic from DB config)
   ↓
5. Payment Processing
   ↓
6. Payment Callback/Webhook
   ↓
7. Order Completion
   ├─→ Create Transaction Record
   ├─→ Update Vendor Wallet (+earning)
   ├─→ Update Reseller Wallet (if applicable)
   ├─→ Deduct Platform Commission (2%)
   ├─→ Send Notifications (Email + SMS)
   └─→ Update Order Status
```

### AFA Registration Flow (With Reseller)

```
1. Customer submits AFA registration form
   ↓
2. System calculates pricing
   ├─→ If Direct Provider: vendor_price = afa_price
   └─→ If Reseller: amount = base_price + markup
   ↓
3. Payment processing via Moolre/configured gateway
   ↓
4. On payment success:
   ├─→ Create AfaRegistration record
   ├─→ Create polymorphic Transaction(s)
   │   ├─→ Provider Transaction (vendor_id)
   │   └─→ Reseller Transaction (reseller_vendor_id) [if applicable]
   ├─→ Update Vendor Wallets
   │   ├─→ Provider: +vendor_earning (base - 2%)
   │   └─→ Reseller: +reseller_earning (markup - 2%)
   ├─→ Send Notifications to both vendors
   └─→ Email confirmation to customer
```

### Withdrawal Processing Flow

```
1. Vendor requests withdrawal
   ↓
2. Validate wallet balance
   ↓
3. Create VendorWithdrawal record (status: processing)
   ↓
4. Deduct from vendor wallet_balance
   ↓
5. Dispatch ProcessVendorWithdrawalPayout job to queue
   ↓
6. Queue worker picks up job
   ↓
7. Call payout gateway API (MomoPayoutService)
   ↓
8. Update withdrawal status based on response
   ├─→ Success: status = approved, send confirmation
   ├─→ Pending: keep as processing, retry later
   └─→ Failed: refund wallet, notify vendor
```

---

## 🛠️ Technology Stack

### Backend Framework
- **Laravel 12.x** - PHP framework
- **PHP 8.2+** - Server-side language
- **MySQL / SQLite** - Relational database
- **Queue System** - Database-driven (supports Redis/SQS)

### Frontend
- **Blade Templates** - Laravel's templating engine
- **Tailwind CSS 4.x** - Utility-first CSS framework
- **Alpine.js 3.x** - Lightweight JavaScript framework
- **Vite 7.x** - Modern build tool and dev server

### Payment Integrations
- **Paystack** - Card payments and mobile money
- **Flutterwave** - Multi-currency payment gateway
- **Hubtel** - Ghana-focused mobile money
- **Moolre** - Generic payment gateway (supports AFA and payouts)

### Notification Services
- **BulkClix SMS** - SMS notifications
- **Laravel Mail** - Email notifications (SMTP/Mailgun)

### Development Tools
- **Composer** - PHP dependency management
- **NPM** - JavaScript package management
- **Laravel Tinker** - Interactive REPL
- **Laravel Pint** - Code styling
- **PHPUnit** - Testing framework

### Additional Libraries
- **dompdf/laravel-dompdf** - PDF generation
- **Axios** - HTTP client
- **@alpinejs/collapse** - UI animations

---

## 🚀 Scalability & Performance

XTRA4U is designed to scale from small deployments to **tens of thousands of users/vendors** with standard Laravel scaling practices (horizontal web scaling + dedicated queue workers + caching). The core architecture is a good fit for scale because it keeps the request path mostly stateless and pushes slow work into background jobs.

### What’s Already Optimized

- **Dashboard/query scaling**: vendor dashboard + analytics paths use SQL-side aggregates and bounded lists (avoids unbounded `->get()` memory spikes).
- **Idempotency & concurrency hardening**: order completion and AFA completion use row-level locking (`lockForUpdate`) + in-transaction re-checks to prevent double-processing under concurrent callbacks/webhooks.
- **External I/O moved out of transactions**: mail/SMS are triggered post-commit so DB locks aren’t held while waiting on external providers.
- **Index coverage for hot queries**: additive composite indexes are provided via the migration `database/migrations/2025_12_27_120000_add_scaling_indexes_to_core_tables.php`.
- **High-volume audit logging**: recipient numbers can be logged asynchronously to a dedicated table with streamed admin exports (see “Recipient Numbers” audit log feature).

### Production Scaling Recommendations

- **Queue workers**: run separate workers for payouts/notifications (`php artisan queue:work`) under Supervisor/Systemd; restart workers on deploy (`php artisan queue:restart`).
- **Cache/session/queue backends**: for higher traffic, move from database-backed drivers to Redis (or managed equivalents) to reduce DB contention.
- **Database**: use MySQL/Postgres in production (SQLite is for local/dev), ensure slow query logging is enabled, and monitor index usage.
- **Web tier**: scale app servers horizontally behind a load balancer; keep shared state out of local disk.
- **Assets**: serve `public/build` via a CDN or object storage; ensure deploys ship matching built assets.

### Recipient Numbers Audit Log (High-Volume)

To support **tens of thousands of orders/day** and **millions of recipient numbers over time**, the platform includes an admin-only recipient numbers logging feature designed as an append-only audit log:

- Table: `recipient_number_logs` (migration: `database/migrations/2025_12_28_012000_create_recipient_number_logs_table.php`)
- Capture: runs **after commit** via events + queued jobs (never inside checkout/payment transactions)
- Admin: paginated listing + date/service/vendor filters + **streamed** TXT/CSV export (no full-table loads)

**Feature flags / ops**

- `RECIPIENT_NUMBER_LOGGING_ENABLED=true|false`
- `RECIPIENT_NUMBER_LOGGING_QUEUE=audit`

Note: logging is automatically skipped when `QUEUE_CONNECTION=sync` to prevent any synchronous overhead in checkout/payment flows.

### Practical Capacity Notes

- The main scaling ceilings are typically **database contention** (sessions/queues on DB), **long-running external calls** in request paths, and **missing indexes**. The current codebase is structured to address these and supports incremental upgrades without changing routes or response shapes.

---

## 📦 Installation & Setup

### Prerequisites

- PHP 8.2 or higher
- Composer 2.x
- Node.js 18.x or higher
- MySQL 8.0+ or SQLite
- Git

### Quick Start (Development)

#### 1. Clone Repository

```bash
git clone https://github.com/yourusername/XTRA4U.git
cd XTRA4U
```

#### 2. Install Dependencies

```bash
# PHP dependencies
composer install

# Node dependencies
npm install
```

#### 3. Environment Setup

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

#### 4. Database Configuration

**Option A: SQLite (Recommended for local development)**

```bash
# Create database file
touch database/database.sqlite

# Update .env
# DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/database/database.sqlite
```

**Option B: MySQL**

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE xtra4u CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Update .env
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=xtra4u
# DB_USERNAME=your_username
# DB_PASSWORD=your_password
```

#### 5. Run Migrations & Seeders

```bash
php artisan migrate --seed
```

This creates:
- Admin account: `admin@xtra4u.com` / `password`
- Sample vendors
- Network services
- Payment gateway configurations

#### 6. Build Frontend Assets

```bash
# Development mode with hot reload
npm run dev

# Production build
npm run build
```

#### 7. Start Development Server

```bash
# Start Laravel development server
php artisan serve

# In a separate terminal, start queue worker
php artisan queue:work

# Access application at http://127.0.0.1:8000
```

### Production Deployment

#### Additional Steps for Production

1. **Optimize Application**

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

2. **Build Production Assets**

```bash
npm run build
```

3. **Set Environment to Production**

```env
APP_ENV=production
APP_DEBUG=false
```

4. **Configure Queue Worker as Service**

Use Supervisor or systemd to keep queue worker running:

```ini
[program:xtra4u-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/xtra4u/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/xtra4u/storage/logs/worker.log
```

5. **Set Up Cron for Scheduled Tasks**

```cron
* * * * * cd /path/to/xtra4u && php artisan schedule:run >> /dev/null 2>&1
```

---

## ⚙️ Environment Configuration

### Core Application Settings

```env
APP_NAME="XTRA4U"
APP_ENV=local                    # local | production
APP_KEY=                         # Generated by php artisan key:generate
APP_DEBUG=true                   # false in production
APP_URL=http://localhost:8000    # Your application URL
```

### Database Configuration

```env
DB_CONNECTION=mysql              # mysql | sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xtra4u
DB_USERNAME=root
DB_PASSWORD=
```

### Mail Configuration

```env
MAIL_MAILER=smtp                 # smtp | log | mailgun
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@xtra4u.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### Queue Configuration

```env
QUEUE_CONNECTION=database         # database | redis | sync
```

### Payment Gateway Configuration

#### Paystack

```env
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxx
PAYSTACK_PAYMENT_URL=https://api.paystack.co
```

**Paystack callback:** Ensure `APP_URL` is set to a public HTTPS URL Paystack can reach. For local development, use an HTTPS tunnel (e.g. ngrok). If you hit cURL error 60 on Windows, point PHP to a CA bundle by setting `curl.cainfo` and `openssl.cafile` in `php.ini`.

#### Flutterwave

```env
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST-xxxxxxxxxxxxx
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST-xxxxxxxxxxxxx
FLUTTERWAVE_ENCRYPTION_KEY=FLWSECK_TESTxxxxxxxxxxxxx
```

#### Hubtel

```env
HUBTEL_CLIENT_ID=xxxxxxxxxxxxx
HUBTEL_CLIENT_SECRET=xxxxxxxxxxxxx
HUBTEL_MERCHANT_NUMBER=xxxxxxxxxxxxx
```

#### Moolre (Generic Gateway)

```env
MOOLRE_API_BASE_URL=https://api.moolre.com
MOOLRE_API_USER=your_api_user
MOOLRE_PUBLIC_KEY=your_public_key
MOOLRE_ACCOUNT_NUMBER=your_account_number
MOOLRE_BUSINESS_EMAIL=business@example.com
```

### SMS Configuration (BulkClix)

```env
BULKCLIX_API_KEY=xxxxxxxxxxxxx
BULKCLIX_SENDER_ID=XTRA4U
BULKCLIX_BASE_URL=https://api.bulkclix.com
```

### Mobile Money Payout Networks

```env
# Configured in config/momo.php
# MTN, Vodafone, AirtelTigo supported
```

---

## 💳 Payment & Gateway Systems

### Gateway Management Architecture

XTRA4U features a sophisticated **database-driven payment gateway system** that allows runtime configuration without code changes.

#### Key Components

1. **GatewayManager Service** (`app/Services/GatewayManager.php`)
   - Centralized gateway resolution
   - Dynamic provider selection from database
   - Fallback handling
   - Support for multiple gateway types (payment, payout, generic)

2. **PaymentGatewayConfig Model**
   - Database table: `payment_gateway_configs`
   - Fields: `gateway_name`, `is_active`, `supports_payment`, `supports_payout`, `supports_generic`
   - Hot-swappable configuration

3. **Gateway-Specific Services**
   - `PaystackPaymentService`
   - `FlutterwavePaymentService`
   - `HubtelPaymentService`
   - `MoolrePaymentService`
   - `MomoPayoutService`

#### Supported Gateways

| Gateway | Payment | Payout | Generic | Status |
|---------|---------|--------|---------|--------|
| Paystack | ✅ | ❌ | ❌ | Production-ready |
| Flutterwave | ✅ | ❌ | ❌ | Production-ready |
| Hubtel | ✅ | ❌ | ❌ | Production-ready |
| Moolre | ✅ | ✅ | ✅ | Production-ready |

#### Adding a New Gateway

1. Create service class implementing required interface
2. Add configuration to `payment_gateway_configs` table
3. Update `.env` with gateway credentials
4. Test and activate

No code deployment required for gateway switching!

---

## 🔒 Security Considerations

### Implemented Security Measures

1. **Content Security Policy (CSP)**
   - Dynamic CSP middleware
   - Gateway-specific URL whitelisting
   - XSS protection

2. **CSRF Protection**
   - Laravel's built-in CSRF tokens on all forms
   - API token validation

3. **SQL Injection Prevention**
   - Eloquent ORM query builders
   - Prepared statements
   - Input validation and sanitization

4. **Authentication & Authorization**
   - Separate auth guards for Admin and Vendor
   - Policy-based authorization
   - Password hashing with bcrypt

5. **Payment Security**
   - Webhook signature verification
   - HTTPS enforcement in production
   - Secure credential storage

6. **Data Protection**
   - Environment variable encryption
   - Database connection encryption
   - Sensitive data masking in logs

### Best Practices for Deployment

- Never commit `.env` files to version control
- Use strong, unique passwords for all accounts
- Enable 2FA for admin accounts (future enhancement)
- Regular security audits and dependency updates
- Implement rate limiting on public endpoints
- Monitor logs for suspicious activity
- Keep Laravel and dependencies updated

---

## 📁 Folder Structure

- **Registration Number:** BN582881225
- **Jurisdiction:** Ghana (Registrar General's Department)

### Permitted Use

This codebase is intended for:
- Internal development and operations of XTRA4U platform
- Authorized contractors and partners under NDA
- Investors and stakeholders for due diligence purposes

### Prohibited Actions

- Redistribution of source code
- Reverse engineering
- Creating derivative works
- Commercial use outside XTRA4U operations
- Disclosure of proprietary business logic

For licensing inquiries, partnership opportunities, or investment discussions, please contact:

**Email:** info@richprimeservices.com  
**Platform:** https://xtra4u.com

---

## 📞 Support & Contact

### Technical Support

For technical issues or questions:
- **Email:** support@xtra4u.com
- **Developer Documentation:** [Internal Wiki/Confluence]

### Business Inquiries

For business partnerships, vendor onboarding, or general inquiries:
- **Email:** officialfocus344@gmail.com
- **Website:** https://xtra4u.com

---

<div align="center">

**Built with ❤️ in Ghana**

*Empowering digital commerce across West Africa*

</div>
- `MOMO_COLLECTION_SUBSCRIPTION_KEY`
- `MOMO_DISBURSEMENT_SUBSCRIPTION_KEY`
- `MOMO_API_USER`
- `MOMO_API_KEY`
- `MOMO_ENVIRONMENT` - (sandbox/production)

### Paystack callback
Set `APP_URL` to the public URL Paystack can reach. For local dev, use an HTTPS tunnel (ngrok) and keep `payment.callback` exposed. If you hit cURL error 60 on Windows, point PHP to a CA bundle (set `curl.cainfo` and `openssl.cafile` in `php.ini`).

## Development workflow
- `npm run dev` — Vite dev server
- `php artisan serve` — app server
- `php artisan queue:work` — process jobs
- `composer run dev` — runs server, queue worker, pail logs, and Vite together
- `composer pint` — code style (Laravel Pint)

## Testing
```powershell
php artisan test
# or
php artisan test --testsuite=Feature
```
Use sqlite for tests (`DB_CONNECTION=sqlite`) or a dedicated test DB. Factories live in `database/factories`.

## Troubleshooting
- Logs: `storage/logs/laravel.log`
- Clear caches after env/config changes:
	```powershell
	php artisan config:clear
	php artisan cache:clear
	php artisan route:clear
	php artisan view:clear
	```
- Windows perms: ensure `storage/` and `bootstrap/cache` are writable.

## Deployment notes
- Set `APP_ENV=production`, `APP_DEBUG=false`
- Build assets: `npm ci && npm run build`
- Optimize: `composer install --no-dev --optimize-autoloader`
- Public uploads: run `php artisan storage:link` so `/storage/*` URLs resolve (this creates `public/storage` → `storage/app/public`)
- Run migrations: `php artisan migrate --force`
- Keep a queue worker alive (Supervisor/systemd)

If you deploy by uploading only the `public/` folder (shared hosting), note that `public/storage` is usually a symlink and may not transfer; in that case you must either run `php artisan storage:link` on the server or copy `storage/app/public/*` into a real `public/storage/` directory.

## Contributing
1) Branch from `main`
2) Add tests for changes
3) Keep PRs small and documented

## 📂 Project Structure

### Key Directories
```
app/
├── Console/Commands/        # Scheduled commands (CleanupPendingPayments)
├── Events/                  # Order events (OrderCompleted)
├── Listeners/               # Event handlers (SendOrderCompletionNotification)
├── Mail/                    # Email templates (OrderPlaced, VendorPasswordReset)
├── Models/                  # Eloquent models (User, Vendor, Order, Product, etc.)
├── Http/
│   ├── Controllers/         # Application controllers
│   ├── Middleware/          # Custom middleware
│   └── Traits/             # Reusable traits
├── Policies/               # Authorization policies (VendorPolicy)
├── Providers/              # Service providers (DynamicMailServiceProvider)
└── Services/               # Business logic services
    ├── PaymentService.php           # Payment gateway abstraction
    ├── GatewayManager.php           # Multi-gateway management
    ├── PaystackPaymentService.php   # Paystack integration
    ├── FlutterwavePaymentService.php # Flutterwave integration
    ├── HubtelPaymentService.php     # Hubtel integration
    ├── MomoPayoutService.php        # MTN MoMo payouts
    ├── TransactionService.php       # Transaction processing
    └── SmsService.php               # SMS notifications

database/
├── migrations/             # Database schema
├── factories/             # Model factories for testing
└── seeders/               # Database seeders

resources/
├── views/
│   ├── storefront/        # Customer-facing pages
│   ├── vendor/           # Vendor dashboard
│   ├── admin/            # Admin panel
│   ├── pages/            # Static pages (About, Privacy, Terms)
│   └── components/       # Reusable Blade components
├── js/                    # JavaScript (Alpine.js)
└── css/                   # Tailwind CSS

routes/
├── web.php               # Web routes
└── console.php           # Console commands

tests/
├── Feature/              # Integration tests
└── Unit/                 # Unit tests
```

## 🎯 Key Features Implementation

### AFA Reseller System
The AFA (Academic Facility Access) reseller system allows vendors to either:
1. **Direct Provider Mode**: Set their own AFA prices
2. **Reseller Mode**: Choose a source vendor and add markup

**Split Payment Flow:**
- Source vendor receives: `base_price - 2%` commission
- Reseller receives: `markup - 2%` commission
- Platform keeps: 2% from each party (4% total)

**Database Schema:**
```php
vendors table:
- afa_enabled (boolean)
- afa_price (decimal)
- afa_reseller_enabled (boolean)
- afa_source_vendor_id (foreign key)
- afa_base_price (decimal)
- afa_markup (decimal)
- afa_selling_price (decimal) // calculated: base + markup
```

### Payment Gateway Architecture
Multi-gateway support with dynamic configuration:
- `GatewayManager` - Factory pattern for gateway selection
- `PaymentService` - Gateway interface abstraction
- Individual gateway services implement common interface
- Runtime gateway configuration from database
- Automatic fallback and error handling

### Automated Cleanup
Scheduled command runs every 6 hours:
```php
CleanupPendingPayments
- Finds orders pending > 24 hours
- Updates status to 'cancelled'
- Cleans up abandoned transactions
```

## 🔐 Security Features
- CSRF protection on all forms
- SQL injection prevention via Eloquent ORM
- XSS protection via Blade templating
- Password hashing with bcrypt
- Vendor policy authorization
- Rate limiting on API endpoints
- Secure payment callbacks with signature verification

## 📊 Database Models

### Core Models
- **User** - Admin accounts
- **Vendor** - Vendor accounts with approval workflow
- **Product** - Digital products/services
- **ResellerProduct** - Reseller-specific product pricing
- **Order** - Customer orders with status tracking
- **Transaction** - Payment transactions
- **NetworkService** - Telco network services (MTN, Vodafone, AirtelTigo)
- **AfaRegistration** - AFA exam registration orders
- **VendorWithdrawal** - Payout requests
- **VendorNotification** - Vendor notifications
- **AdminNotification** - Admin notifications
- **PaymentGatewayConfig** - Dynamic gateway configuration
- **Setting** - Platform-wide settings
