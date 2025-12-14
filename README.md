# XTRA4U

Laravel-based multi-vendor marketplace for digital services in Ghana, featuring data bundles, airtime, vouchers, utilities, and the innovative **AFA Registration System** with reseller capabilities.

## 🚀 Stack
- **Backend:** Laravel ^12, PHP ^8.2
- **Frontend:** Blade Templates, Tailwind CSS, Alpine.js (with Collapse plugin)
- **Assets:** Vite for modern frontend tooling
- **Queue:** Database queues (configurable for Redis/SQS)
- **Payment:** Paystack, Flutterwave, Hubtel (multi-gateway support)

## ✨ Features

### Customer-Facing
- 🏪 **Vendor Storefronts** - Unique store links for each vendor (`/store/{vendor_code}`)
- 📱 **Service Marketplace** - Browse data bundles, airtime, utilities across all networks
- 💳 **Multi-Gateway Payments** - Paystack, Flutterwave, Hubtel integration
- 📄 **Legal Pages** - About Us, Privacy Policy, Terms of Service
- 🎓 **AFA Registration** - Register for exam results checking service with mobile money payment

### Vendor Features
- 📊 **Dashboard** - Sales metrics, order tracking, earnings overview
- 🛍️ **Product Management** - Create and manage digital products with custom pricing
- ⚙️ **Settings** - Profile, payout (MoMo), password management, store link
- 🤝 **AFA Reseller System** - Become a direct provider or reseller with markup pricing
  - Direct providers set their own prices
  - Resellers select source vendor and add markup
  - Automatic split payment (source vendor gets base - 2%, reseller gets markup - 2%)
- 💰 **Commission System** - Platform takes 2% commission on all transactions
- 📨 **Notifications** - Real-time order updates via email

### Admin Features
- ✅ **Vendor Approval** - Review and approve/reject vendor applications
- 📈 **Platform Analytics** - Monitor sales, commissions, vendor performance
- 🔧 **System Management** - Configure payment gateways, SMS settings
- 🗄️ **Data Cleanup** - Automated cleanup of pending payments after 24 hours

## Quick start (Windows / PowerShell)
```powershell
git clone https://github.com/lankyghana/XTRA4U.git
cd XTRA4U
composer install --no-interaction --prefer-dist
copy .env.example .env
php artisan key:generate

# DB: use sqlite for fast local setup
mkdir database
ni database\database.sqlite -ItemType File
# then set DB_CONNECTION=sqlite in .env

npm install
php artisan migrate --seed
npm run dev   # Vite dev server
php artisan serve
```

## 🔧 Environment Configuration
Set these in `.env`:

### Core
- `APP_NAME` - Application name (XTRA4U)
- `APP_ENV` - Environment (local/production)
- `APP_URL` - Base URL (required for payment callbacks)
- `APP_KEY` - Encryption key (run `php artisan key:generate`)
- `APP_DEBUG` - Debug mode (true/false)

### Database
- `DB_CONNECTION` - Driver (mysql/sqlite)
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

### Mail
- `MAIL_MAILER` - Driver (smtp/log/mailgun)
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`

### Queue
- `QUEUE_CONNECTION` - Driver (database/redis/sync)

### Payment Gateways
**Paystack:**
- `PAYSTACK_PUBLIC_KEY`
- `PAYSTACK_SECRET_KEY`
- `PAYSTACK_PAYMENT_URL` - `https://api.paystack.co`

**Flutterwave:**
- `FLUTTERWAVE_PUBLIC_KEY`
- `FLUTTERWAVE_SECRET_KEY`
- `FLUTTERWAVE_ENCRYPTION_KEY`

**Hubtel:**
- `HUBTEL_CLIENT_ID`
- `HUBTEL_CLIENT_SECRET`
- `HUBTEL_MERCHANT_NUMBER`

### SMS (BulkClix)
- `BULKCLIX_API_KEY`
- `BULKCLIX_SENDER_ID`
- `BULKCLIX_BASE_URL`

### MoMo Payouts
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
- Run migrations: `php artisan migrate --force`
- Keep a queue worker alive (Supervisor/systemd)

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
