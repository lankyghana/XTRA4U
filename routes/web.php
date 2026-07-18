<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminLoginController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminWalletTopupController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorRequestController;
use App\Http\Controllers\VendorAuthController;
use App\Http\Controllers\VendorDashboardController;
use App\Http\Controllers\VendorQuickBuyController;
use App\Http\Controllers\VendorFulfillmentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\AdminVendorController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminTransactionController;
use App\Http\Controllers\AdminWithdrawalController;
use App\Http\Controllers\AdminNetworkServiceController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\AfaRegistrationController;
use App\Http\Controllers\VendorAfaController;
use App\Http\Controllers\ResultCheckerCheckoutController;
use App\Http\Controllers\ResultCheckerPaymentCallbackController;
use App\Http\Controllers\ResultCheckerStatusController;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\AdminResultCheckerPinsController;
use App\Http\Controllers\AdminResultCheckerPricingTierController;

// Admin login routes - moved to consolidated section below

// Secure cache clear route (use a secret key to prevent abuse)
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

// Storage symlink creation (run once on production server)
Route::get('/storage-link/{secret}', function ($secret) {
    if ($secret !== 'xtra4u-2025-clear') {
        abort(404);
    }
    
    // Check if symlink already exists
    $publicStorage = public_path('storage');
    $targetPath = storage_path('app/public');
    
    if (file_exists($publicStorage)) {
        // Check if it's a valid symlink
        if (is_link($publicStorage)) {
            return response()->json([
                'success' => true,
                'message' => 'Storage symlink already exists and is valid.',
                'link' => $publicStorage,
                'target' => readlink($publicStorage)
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'A file/folder named "storage" exists in public folder. Delete it first.',
                'path' => $publicStorage
            ]);
        }
    }
    
    // Try to create symlink
    try {
        Artisan::call('storage:link');
        
        return response()->json([
            'success' => true,
            'message' => 'Storage symlink created successfully!',
            'link' => $publicStorage,
            'target' => $targetPath
        ]);
    } catch (\Exception $e) {
        // Manual symlink creation for shared hosting
        if (symlink($targetPath, $publicStorage)) {
            return response()->json([
                'success' => true,
                'message' => 'Storage symlink created manually!',
                'link' => $publicStorage,
                'target' => $targetPath
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to create symlink: ' . $e->getMessage(),
            'manual_command' => "ln -s $targetPath $publicStorage"
        ]);
    }
})->name('storage.link');

// CSRF Token refresh endpoint (prevents 419 errors after idle time)
// Public + web middleware only (no auth/vendor/admin/custom groups)
Route::get('/csrf-token', function () {
    return response()
        ->json([
            // Preferred key (new)
            'csrfToken' => csrf_token(),
            // Backward-compatible key (legacy frontend code)
            'token' => csrf_token(),
        ])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Vary', 'Cookie');
})
    ->middleware('web')
    ->name('csrf.token');

// Order Status Checker Routes
Route::get('/order-status', [OrderStatusController::class, 'index'])->name('order.status');
Route::post('/order-status/check', [OrderStatusController::class, 'check'])->name('order.status.check');
Route::post('/order-status/poll', [OrderStatusController::class, 'poll'])->name('order.status.poll');

// AFA Registration Routes (Public)
Route::get('/store/{vendor:vendor_code}/afa', [AfaRegistrationController::class, 'show'])->name('afa.register');
Route::post('/afa/store/{vendor:vendor_code}', [AfaRegistrationController::class, 'store'])->name('afa.store');
Route::get('/afa/callback', [AfaRegistrationController::class, 'paymentCallback'])->name('afa.callback');
Route::get('/afa/success/{reference}', [AfaRegistrationController::class, 'success'])->name('afa.success');
Route::post('/afa/check-status', [AfaRegistrationController::class, 'checkStatus'])->name('afa.check-status');
Route::post('/afa/verify', [AfaRegistrationController::class, 'verify'])->name('afa.verify');

Route::get('/', [StorefrontController::class, 'index'])->name('storefront.index');
Route::get('/about', fn() => view('pages.about'))->name('about');
Route::get('/privacy', fn() => view('pages.privacy'))->name('privacy');
Route::get('/terms', fn() => view('pages.terms'))->name('terms');

// Public Marketplace alias (matches common capitalized URL)
Route::get('/Marketplace', fn () => redirect()->route('checkout.show'));

Route::get('/store/{vendor:vendor_code}', [StorefrontController::class, 'showVendorStore'])->name('storefront.vendor');

// Result Checker Routes - Customer Facing
// Vendor-agnostic entry point used by the main navigation and homepage; it
// resolves the flagship store at request time so the link never 404s when
// the configured vendor code is absent (local/staging databases).
Route::get('/results-checkers', [StorefrontController::class, 'resultCheckersEntry'])->name('result-checkers.entry');
Route::get('/store/{vendor:vendor_code}/result-checkers', [StorefrontController::class, 'showResultCheckers'])->name('storefront.result-checkers');
Route::post('/store/{vendor:vendor_code}/result-checkers/checkout', [ResultCheckerCheckoutController::class, 'initiateCheckout'])
    ->middleware('throttle:20,1,rc-checkout') // 20 checkout initiations per minute
    ->name('result-checkers.checkout');
Route::match(['GET', 'POST'], '/result-checkers/payment/callback/{order}', [ResultCheckerPaymentCallbackController::class, 'handle'])->name('result-checkers.payment.callback');
Route::post('/result-checkers/payment/webhook', [ResultCheckerPaymentCallbackController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('result-checkers.payment.webhook');
Route::post('/webhooks/gigshub', [\App\Http\Controllers\Webhooks\GigshubWebhookController::class, 'handle'])->name('api.webhooks.gigshub');
Route::post('/webhooks/gigshub/balance-low', [\App\Http\Controllers\Webhooks\GigshubLowBalanceWebhookController::class, 'handle'])->name('webhooks.gigshub.balance-low');
Route::post('/webhooks/skdataplug', [\App\Http\Controllers\Webhooks\SkdataplugWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.webhooks.skdataplug');
Route::post('/webhooks/paystack', [\App\Http\Controllers\Webhooks\PaystackWebhookController::class, 'handle'])
    ->name('webhooks.paystack');

// Result Checker Status pages
// SECURITY: Add rate limiting to prevent enumeration/brute force attacks
Route::get('/results-checker/status', [ResultCheckerStatusController::class, 'index'])->name('result-checkers.status');
Route::post('/results-checker/status/check', [ResultCheckerStatusController::class, 'check'])
    ->middleware('throttle:10,1,rc-status-check') // 10 requests per minute
    ->name('result-checkers.status.check');
Route::get('/results-checker/status/{order}', [ResultCheckerStatusController::class, 'show'])
    ->middleware('throttle:10,1,rc-status-show') // 10 requests per minute
    ->name('result-checkers.status.show');

// Success/pending pages
Route::get('/result-checkers/success/{order}', function (\App\Models\ResultCheckerOrder $order) {
    $order->load('service', 'vendor');
    return view('result_checkers.success', compact('order'));
})->name('result-checkers.success');

Route::get('/result-checkers/pending-stock/{order}', function (\App\Models\ResultCheckerOrder $order) {
    $order->load('service', 'vendor');
    return view('result_checkers.pending_stock', compact('order'));
})->name('result-checkers.pending-stock');

Route::get('/vendor/request', [VendorController::class, 'showRequestForm'])->name('vendor.request.form');
Route::get('/vendor/request/create', [VendorRequestController::class, 'create'])->name('vendor.request.create');
Route::post('/vendor/request', [VendorRequestController::class, 'store'])->name('vendor.request.store');
Route::post('/vendor/request/submit', [VendorController::class, 'submitRequest'])->name('vendor.request.submit');
Route::get('/vendor/login', [VendorAuthController::class, 'showLoginForm'])->name('vendor.login.form');
Route::post('/vendor/login', [VendorAuthController::class, 'login'])->name('vendor.login');
Route::post('/vendor/logout', [VendorAuthController::class, 'logout'])->name('vendor.logout');

// Vendor Password Reset Routes
Route::get('/vendor/forgot-password', [VendorAuthController::class, 'showForgotPasswordForm'])->name('vendor.password.forgot');
Route::post('/vendor/forgot-password', [VendorAuthController::class, 'sendResetLink'])->name('vendor.password.email');
Route::get('/vendor/reset-password/{token}', [VendorAuthController::class, 'showResetPasswordForm'])->name('vendor.password.reset');
Route::post('/vendor/reset-password', [VendorAuthController::class, 'resetPassword'])->name('vendor.password.update');
Route::middleware(['vendor.approved'])
    ->prefix('vendor')
    ->name('vendor.')
    ->group(function () {
        Route::get('dashboard', [VendorDashboardController::class, 'index'])->name('dashboard');
        Route::get('dashboard/sales-stats', [VendorDashboardController::class, 'getFilteredSalesStats'])->name('dashboard.sales-stats');
        Route::resource('products', ProductController::class);

        Route::get('orders', [VendorDashboardController::class, 'orders'])
            ->name('orders.index');
        Route::get('orders/affiliate', [VendorDashboardController::class, 'affiliateOrders'])
            ->name('orders.affiliate');
        Route::patch('orders/{order}/status', [VendorDashboardController::class, 'updateOrderStatus'])
            ->name('orders.update-status');

        // Vendor Order Fulfillment (internal-only, additive)
        Route::get('fulfillment', [VendorFulfillmentController::class, 'index'])->name('fulfillment.index');
        Route::get('fulfillment/download/{network}', [VendorFulfillmentController::class, 'download'])->name('fulfillment.download');
        Route::post('fulfillment/complete/{network}', [VendorFulfillmentController::class, 'complete'])->name('fulfillment.complete');
        Route::post('fulfillment/resend-failed-api', [VendorFulfillmentController::class, 'resendAllFailedApiOrders'])->name('fulfillment.resend-failed-api');
        
        Route::get('analytics', [VendorDashboardController::class, 'analytics'])
            ->name('analytics.index');

        Route::get('affiliates', [VendorDashboardController::class, 'affiliates'])
            ->name('affiliates.index');
        Route::post('affiliates/join', [VendorDashboardController::class, 'joinAffiliate'])
            ->name('affiliates.join');
        
        // Reseller/Marketplace Routes
        Route::get('marketplace', [VendorDashboardController::class, 'marketplace'])
            ->name('marketplace.index');
        Route::post('marketplace/add', [VendorDashboardController::class, 'addResellerProduct'])
            ->name('marketplace.add');
        
        Route::get('reseller-products', [VendorDashboardController::class, 'myResellerProducts'])
            ->name('reseller.index');
        Route::patch('reseller-products/{id}', [VendorDashboardController::class, 'updateResellerProduct'])
            ->name('reseller.update');
        Route::delete('reseller-products/{id}', [VendorDashboardController::class, 'removeResellerProduct'])
            ->name('reseller.destroy');

        // Vendor Result Checker Routes
        Route::prefix('result-checkers')->name('result-checkers.')->group(function () {
            Route::get('/', [\App\Http\Controllers\VendorResultCheckerSettingsController::class, 'index'])
                ->name('index');
            Route::post('/', [\App\Http\Controllers\VendorResultCheckerSettingsController::class, 'update'])
                ->name('update');
            Route::get('orders', [\App\Http\Controllers\VendorResultCheckerOrdersController::class, 'index'])
                ->name('orders.index');
        });
        
        Route::get('transactions', fn () => redirect()->route('vendor.dashboard'))
            ->name('transactions.index');

        // Vendor wallet/withdrawal routes. Path changed to 'wallet' for clarity
        // Route names are preserved for backwards compatibility.
        Route::get('wallet', [VendorDashboardController::class, 'withdrawals'])
            ->name('withdrawals.index');
        Route::post('wallet/name-query', [VendorDashboardController::class, 'lookupWithdrawalAccountName'])
            ->name('withdrawals.name-query');
        Route::post('wallet', [VendorDashboardController::class, 'requestWithdrawal'])
            ->name('withdrawals.store');


        // Notification Routes
        Route::get('notifications', [VendorDashboardController::class, 'notifications'])
            ->name('notifications.index');
        Route::post('notifications/{notification}/read', [VendorDashboardController::class, 'markNotificationRead'])
            ->name('notifications.read');
        Route::post('notifications/read-all', [VendorDashboardController::class, 'markAllNotificationsRead'])
            ->name('notifications.read-all');
        Route::get('notifications/unread-count', [VendorDashboardController::class, 'unreadNotificationsCount'])
            ->name('notifications.unread-count');
        
        // AFA Registration Management Routes
        Route::get('afa', [VendorAfaController::class, 'index'])->name('afa.index');
        Route::get('afa/settings', [VendorAfaController::class, 'settings'])->name('afa.settings');
        Route::put('afa/settings', [VendorAfaController::class, 'updateSettings'])->name('afa.update-settings');
        Route::delete('afa/reseller', [VendorAfaController::class, 'disableReseller'])->name('afa.disable-reseller');
        Route::get('afa/stats', [VendorAfaController::class, 'stats'])->name('afa.stats');
        Route::get('afa/{registration}', [VendorAfaController::class, 'show'])->name('afa.show');
        Route::patch('afa/{registration}/status', [VendorAfaController::class, 'updateStatus'])->name('afa.update-status');
        Route::post('afa/bulk-update', [VendorAfaController::class, 'bulkUpdate'])->name('afa.bulk-update');
        Route::get('afa/{registration}/status-api', [VendorAfaController::class, 'getStatus'])->name('afa.get-status');
        
        // Vendor Settings Routes
        Route::get('settings', [VendorDashboardController::class, 'settings'])->name('settings.index');
        Route::put('settings', [VendorDashboardController::class, 'updateSettings'])->name('settings.update');
        Route::put('settings/password', [VendorDashboardController::class, 'updatePassword'])->name('settings.password');

        // Vendor External Fulfillment Settings
        Route::get('settings/external-fulfillment', [\App\Http\Controllers\Vendor\ExternalFulfillmentSettingsController::class, 'index'])
            ->name('settings.external-fulfillment');
        Route::put('settings/external-fulfillment', [\App\Http\Controllers\Vendor\ExternalFulfillmentSettingsController::class, 'update'])
            ->name('settings.external-fulfillment.update');
        Route::post('settings/external-fulfillment/test-xpres', [\App\Http\Controllers\Vendor\ExternalFulfillmentSettingsController::class, 'testXpresConnection'])
            ->name('settings.external-fulfillment.test-xpres');
        Route::get('external-services', [\App\Http\Controllers\Vendor\ExternalServicesController::class, 'index'])
            ->name('external-services.index');

        // Vendor wallet top-up and ledger
        Route::post('wallet/topup', [\App\Http\Controllers\VendorWalletController::class, 'initiateTopup'])
            ->middleware('throttle:20,1,wallet-topup')
            ->name('wallet.topup');
        // Polling endpoint for client-side verification of inline top-ups
        Route::get('wallet/topup/status/{reference}', [\App\Http\Controllers\VendorWalletController::class, 'topupStatus'])
            ->middleware('throttle:60,1,wallet-topup-status')
            ->name('wallet.topup.status');
        // Wallet ledger endpoint (no vendor_id parameter - uses authenticated context only)
        Route::get('wallet/ledger', [\App\Http\Controllers\VendorWalletController::class, 'ledger'])
            ->name('wallet.ledger');
        // Lightweight balance endpoint (used by quick-buy UI polling)
        Route::get('wallet/balance', [\App\Http\Controllers\VendorWalletController::class, 'balance'])
            ->name('wallet.balance');
        
        // Vendor USSD subscription. The gateway callback is public and lives
        // outside this group as `vendor.ussd.subscription.callback`.
        Route::get('ussd/subscription', [\App\Http\Controllers\Vendor\UssdSubscriptionController::class, 'index'])
            ->name('ussd.subscription.index');
        // NOTE: inline `throttle` keys by user/IP only — the route is not part
        // of the key — so each route needs its own prefix (3rd parameter) or
        // they all drain one shared bucket. Without prefixes, the 3s status
        // polling exhausted the callback's 10/min allowance and the gateway's
        // iframe redirect landed on a 429 page.
        Route::post('ussd/subscription/purchase', [\App\Http\Controllers\Vendor\UssdSubscriptionController::class, 'purchase'])
            ->middleware('throttle:10,1,ussd-purchase')
            ->name('ussd.subscription.purchase');
        // Polling endpoint for client-side verification of inline (MoMo) purchases.
        Route::get('ussd/subscription/status/{reference}', [\App\Http\Controllers\Vendor\UssdSubscriptionController::class, 'status'])
            ->middleware('throttle:60,1,ussd-status')
            ->name('ussd.subscription.status');

        // Vendor Quick Buy (dashboard shortcut)
        Route::get('quick-buy', [VendorQuickBuyController::class, 'show'])->name('quick-buy.show');
        Route::post('quick-buy', [VendorQuickBuyController::class, 'store'])->name('quick-buy.store');
        
        // NOTE: the actual payment gateway callback for top-ups should be public
        // and not require vendor authentication. The topup callback route is
        // defined outside the `vendor.approved` group below as `vendor.wallet.topup.callback`.
    });
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'process'])->name('checkout.process');
Route::post('/checkout/verify', [CheckoutController::class, 'verify'])->name('checkout.verify');
// Public wallet top-up callback (payment gateway will call/redirect here)
Route::match(['GET','POST'], '/vendor/wallet/topup/callback/{reference}', [\App\Http\Controllers\VendorWalletController::class, 'topupCallback'])
    ->middleware('throttle:10,1,wallet-topup-callback')
    ->name('vendor.wallet.topup.callback');

// Public USSD subscription callback. Must sit outside `vendor.approved`: the
// payment gateway redirects here without the vendor's session. It only ever
// re-verifies the reference against the gateway before activating.
Route::match(['GET', 'POST'], '/vendor/ussd/subscription/callback/{reference}', [\App\Http\Controllers\Vendor\UssdSubscriptionController::class, 'callback'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:10,1,ussd-callback')
    ->name('vendor.ussd.subscription.callback');
// Backwards-compatibility: preserve existing external bookmarks and old links.
// Requests to the old path `/vendor/withdrawals` are permanently redirected
// to `/vendor/wallet`. We keep route names unchanged so internal `route(...)`
// calls and emails continue to work.
Route::permanentRedirect('/vendor/withdrawals', '/vendor/wallet');
Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name('checkout.success');
Route::get('/checkout/receipt/{order}', [CheckoutController::class, 'receipt'])->name('checkout.receipt');
// Serve legacy browser requests for /favicon.ico using the existing 32x32 PNG.
// This avoids 404 noise when browsers automatically request /favicon.ico.
Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon-32x32.png'));
})->name('favicon');
Route::middleware('prune.purchase.tokens')->group(function () {
    Route::post('/purchase', [\App\Http\Controllers\PurchaseController::class, 'store'])->name('purchase');
    Route::get('/purchase/callback/{token}', [\App\Http\Controllers\PurchaseController::class, 'paymentCallback'])->name('purchase.callback');
});

Route::post('/webhooks/moolre/payment', [\App\Http\Controllers\Webhooks\MoolreWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('webhooks.moolre.payment');
Route::post('/api/ussd', [\App\Http\Controllers\Api\UssdController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('ussd.gateway')
    ->name('api.ussd');
// Client-side polling endpoint for inline embed/iframe payment flows
Route::get('/payment/status/{reference}', [\App\Http\Controllers\PaymentStatusController::class, 'status'])
    ->name('payment.status');
Route::match(['GET', 'POST'], '/payment/callback', [PaymentCallbackController::class, 'handle'])->name('payment.callback');

// Admin Authentication Routes
Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

Route::middleware(['admin.only'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::resource('vendors', AdminVendorController::class)->only(['index', 'update', 'destroy']);
    Route::post('vendors/{vendor}/adjust-balance', [AdminVendorController::class, 'adjustBalance'])->name('vendors.adjust-balance');
    Route::resource('network-services', AdminNetworkServiceController::class)
        ->except(['show'])
        ->parameters(['network-services' => 'network_service']);
    Route::post('vendors/{vendor}/approve', [AdminVendorController::class, 'approve'])->name('vendors.approve');
    Route::post('vendors/{vendor}/reject', [AdminVendorController::class, 'reject'])->name('vendors.reject');
	Route::post('vendors/{vendor}/disable-affiliate', [AdminVendorController::class, 'disableAffiliate'])->name('vendors.disable-affiliate');
	Route::post('vendors/{vendor}/tier', [AdminVendorController::class, 'updateTier'])->name('vendors.update-tier');
        Route::resource('orders', AdminOrderController::class)->only(['index', 'show']);
	Route::post('orders/{order}/confirm-payment', [AdminOrderController::class, 'confirmPayment'])
		->name('orders.confirm-payment');
        Route::resource('transactions', AdminTransactionController::class)->only(['index']);
    Route::post('transactions/{transaction}/confirm-payment', [AdminTransactionController::class, 'confirmPayment'])
        ->name('transactions.confirm-payment');
    Route::get('wallet-topups', [AdminWalletTopupController::class, 'index'])->name('wallet-topups.index');
    Route::get('withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::post('withdrawals/{withdrawal}/refresh', [AdminWithdrawalController::class, 'refresh'])->name('withdrawals.refresh');
    Route::post('withdrawals/{withdrawal}/cancel', [AdminWithdrawalController::class, 'cancel'])->name('withdrawals.cancel');

    Route::get('reports', [\App\Http\Controllers\AdminReportsController::class, 'index'])->name('reports.index');

    // High-volume recipient number audit log
    Route::get('recipient-numbers', [\App\Http\Controllers\AdminRecipientNumberController::class, 'index'])->name('recipient-numbers.index');
    Route::get('recipient-numbers/export', [\App\Http\Controllers\AdminRecipientNumberController::class, 'export'])->name('recipient-numbers.export');
    Route::get('recipient-numbers/copy', [\App\Http\Controllers\AdminRecipientNumberController::class, 'copy'])->name('recipient-numbers.copy');
    // Payment Gateway Management - replaces legacy paystack-config
    
    // Payment Gateway Management
    Route::resource('payment-gateways', \App\Http\Controllers\Admin\PaymentGatewayController::class)
        ->parameters(['payment-gateways' => 'gateway']);
    Route::patch('payment-gateways/{gateway}/set-default', [\App\Http\Controllers\Admin\PaymentGatewayController::class, 'setDefault'])->name('payment-gateways.set-default');
    Route::patch('payment-gateways/{gateway}/toggle-active', [\App\Http\Controllers\Admin\PaymentGatewayController::class, 'toggleActive'])->name('payment-gateways.toggle-active');
    Route::post('payment-gateways/{gateway}/test', [\App\Http\Controllers\Admin\PaymentGatewayController::class, 'test'])->name('payment-gateways.test');
    
    // Admin Notifications
    Route::get('notifications', [AdminController::class, 'notifications'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [AdminController::class, 'markNotificationRead'])->name('notifications.read');
    Route::post('notifications/read-all', [AdminController::class, 'markAllNotificationsRead'])->name('notifications.read-all');
    
    // Service Availability (open/close storefront service categories)
    Route::get('settings/service-availability', [\App\Http\Controllers\Admin\ServiceAvailabilityController::class, 'index'])->name('settings.service-availability');
    Route::put('settings/service-availability', [\App\Http\Controllers\Admin\ServiceAvailabilityController::class, 'update'])->name('settings.service-availability.update');

    // Delivery Status (broadcast a fast/slow delivery notice to vendors)
    Route::get('settings/delivery-status', [\App\Http\Controllers\Admin\DeliveryStatusController::class, 'index'])->name('settings.delivery-status');
    Route::put('settings/delivery-status', [\App\Http\Controllers\Admin\DeliveryStatusController::class, 'update'])->name('settings.delivery-status.update');

    // Email Settings
    Route::get('settings/email', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'index'])->name('settings.email');
    Route::put('settings/email', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'update'])->name('settings.email.update');
    Route::post('settings/email/test', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'sendTestEmail'])->name('settings.email.test');

    // USSD Settings
    Route::get('settings/ussd', [\App\Http\Controllers\Admin\UssdSettingsController::class, 'index'])->name('settings.ussd');
    Route::put('settings/ussd', [\App\Http\Controllers\Admin\UssdSettingsController::class, 'update'])->name('settings.ussd.update');

    // USSD Subscription Plans
    Route::resource('ussd-plans', \App\Http\Controllers\Admin\UssdPlanController::class)
        ->except(['show'])
        ->parameters(['ussd-plans' => 'ussd_plan']);
    Route::patch('ussd-plans/{ussd_plan}/toggle-active', [\App\Http\Controllers\Admin\UssdPlanController::class, 'toggleActive'])
        ->name('ussd-plans.toggle-active');

    // USSD audit trail
    Route::get('ussd-events', [\App\Http\Controllers\Admin\UssdEventController::class, 'index'])
        ->name('ussd-events.index');

    // External Fulfillment Settings

    // Results Checker Admin Routes
    Route::prefix('results-checkers')->name('result-checkers.')->group(function () {
        Route::get('dashboard', [\App\Http\Controllers\AdminResultCheckerDashboardController::class, 'index'])
            ->name('dashboard');
        Route::get('pins', [\App\Http\Controllers\AdminResultCheckerPinsController::class, 'index'])
            ->name('pins.index');
        Route::post('pins', [\App\Http\Controllers\AdminResultCheckerPinsController::class, 'store'])
            ->name('pins.store');
        Route::get('orders', [\App\Http\Controllers\AdminResultCheckerOrdersController::class, 'index'])
            ->name('orders.index');
        Route::get('orders/{order}', [\App\Http\Controllers\AdminResultCheckerOrdersController::class, 'show'])
            ->name('orders.show');
        Route::post('orders/{order}/retry', [\App\Http\Controllers\AdminResultCheckerOrdersController::class, 'retry'])
            ->name('orders.retry');
        Route::post('orders/{order}/mark-failed', [\App\Http\Controllers\AdminResultCheckerOrdersController::class, 'markFailed'])
            ->name('orders.mark-failed');
        Route::patch('orders/{order}/status', [\App\Http\Controllers\AdminResultCheckerOrdersController::class, 'updateStatus'])
            ->name('orders.update-status');
    });

    // Manual Queue Run Trigger (scheduler-bridge)
    Route::post('queue/run', [\App\Http\Controllers\Admin\ManualQueueRunController::class, 'run'])->name('queue.run');

    // Vendor Tier Management
    Route::resource('vendor-tiers', \App\Http\Controllers\Admin\VendorTierController::class)
        ->parameters(['vendor-tiers' => 'vendorTier']);
    Route::post('vendor-tiers/reorder', [\App\Http\Controllers\Admin\VendorTierController::class, 'reorder'])->name('vendor-tiers.reorder');

    // Vendor Tier Promotion Queue
    Route::get('vendor-tier-promotions', [\App\Http\Controllers\Admin\VendorTierPromotionController::class, 'index'])->name('vendor-tier-promotions.index');
    Route::post('vendor-tier-promotions/{vendor}/approve', [\App\Http\Controllers\Admin\VendorTierPromotionController::class, 'approve'])->name('vendor-tier-promotions.approve');
    Route::post('vendor-tier-promotions/{vendor}/reject', [\App\Http\Controllers\Admin\VendorTierPromotionController::class, 'reject'])->name('vendor-tier-promotions.reject');

    // Vendor Tier History / Audit Log
    Route::get('vendor-tier-history', [\App\Http\Controllers\Admin\VendorTierHistoryController::class, 'index'])->name('vendor-tier-history.index');
});
Route::get('/vendor/product/create', [ProductController::class, 'create'])->name('product.create');
Route::post('/vendor/product', [ProductController::class, 'store'])->name('product.store');
Route::get('/vendor/product/{id}/edit', [ProductController::class, 'edit'])->name('product.edit');
Route::put('/vendor/product/{id}', [ProductController::class, 'update'])->name('product.update');
Route::middleware(['web', 'admin.only'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('result-checker-pricing-tiers', [AdminResultCheckerPricingTierController::class, 'store'])->name('result-checker-pricing-tiers.store');
    Route::delete('result-checker-pricing-tiers/{tier}', [AdminResultCheckerPricingTierController::class, 'destroy'])->name('result-checker-pricing-tiers.destroy');
    Route::patch('results-checkers/base-price', [AdminResultCheckerPinsController::class, 'updateBasePrice'])->name('result-checkers.base-price.update');
});
