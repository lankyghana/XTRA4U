
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
use Illuminate\Support\Facades\Artisan;

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
        
        // Vendor Settings Routes
        Route::get('settings', [VendorDashboardController::class, 'settings'])->name('settings.index');
        Route::put('settings', [VendorDashboardController::class, 'updateSettings'])->name('settings.update');
        Route::put('settings/password', [VendorDashboardController::class, 'updatePassword'])->name('settings.password');

        // Vendor External Fulfillment Settings
        Route::get('settings/external-fulfillment', [\App\Http\Controllers\Vendor\ExternalFulfillmentSettingsController::class, 'index'])
            ->name('settings.external-fulfillment');
        Route::put('settings/external-fulfillment', [\App\Http\Controllers\Vendor\ExternalFulfillmentSettingsController::class, 'update'])
            ->name('settings.external-fulfillment.update');

        // Vendor wallet top-up and ledger
        Route::post('wallet/topup', [\App\Http\Controllers\VendorWalletController::class, 'initiateTopup'])
            ->name('wallet.topup');
        // Polling endpoint for client-side verification of inline top-ups
        Route::get('wallet/topup/status/{reference}', [\App\Http\Controllers\VendorWalletController::class, 'topupStatus'])
            ->name('wallet.topup.status');
        Route::get('wallet/{vendor}', [\App\Http\Controllers\VendorWalletController::class, 'ledger'])
            ->whereNumber('vendor')
            ->name('wallet.ledger');
        // Lightweight balance endpoint (used by quick-buy UI polling)
        Route::get('wallet/balance', [\App\Http\Controllers\VendorWalletController::class, 'balance'])
            ->name('wallet.balance');
        
        // Vendor Quick Buy (dashboard shortcut)
        Route::get('quick-buy', [VendorQuickBuyController::class, 'show'])->name('quick-buy.show');
        Route::post('quick-buy', [VendorQuickBuyController::class, 'store'])->name('quick-buy.store');
        
        // NOTE: the actual payment gateway callback for top-ups should be public
        // and not require vendor authentication. The topup callback route is
        // defined outside the `vendor.approved` group below as `vendor.wallet.topup.callback`.
        Route::get('wallet/{vendor}', [\App\Http\Controllers\VendorWalletController::class, 'ledger'])
            ->whereNumber('vendor')
            ->name('wallet.ledger');
    });
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'process'])->name('checkout.process');
Route::post('/checkout/verify', [CheckoutController::class, 'verify'])->name('checkout.verify');
// Public wallet top-up callback (payment gateway will call/redirect here)
Route::match(['GET','POST'], '/vendor/wallet/topup/callback/{reference}', [\App\Http\Controllers\VendorWalletController::class, 'topupCallback'])
    ->name('vendor.wallet.topup.callback');
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
Route::match(['GET', 'POST'], '/payment/callback', [PaymentCallbackController::class, 'handle'])->name('payment.callback');

// Admin Authentication Routes
Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

Route::middleware(['admin.only'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::resource('vendors', AdminVendorController::class)->only(['index', 'update', 'destroy']);
    Route::resource('network-services', AdminNetworkServiceController::class)
        ->except(['show'])
        ->parameters(['network-services' => 'network_service']);
    Route::post('vendors/{vendor}/approve', [AdminVendorController::class, 'approve'])->name('vendors.approve');
    Route::post('vendors/{vendor}/reject', [AdminVendorController::class, 'reject'])->name('vendors.reject');
	Route::post('vendors/{vendor}/disable-affiliate', [AdminVendorController::class, 'disableAffiliate'])->name('vendors.disable-affiliate');
        Route::resource('orders', AdminOrderController::class)->only(['index']);
	Route::post('orders/{order}/confirm-payment', [AdminOrderController::class, 'confirmPayment'])
		->name('orders.confirm-payment');
        Route::resource('transactions', AdminTransactionController::class)->only(['index']);
    Route::post('transactions/{transaction}/confirm-payment', [AdminTransactionController::class, 'confirmPayment'])
        ->name('transactions.confirm-payment');
    Route::get('wallet-topups', [AdminWalletTopupController::class, 'index'])->name('wallet-topups.index');
    Route::get('withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::post('withdrawals/{withdrawal}/refresh', [AdminWithdrawalController::class, 'refresh'])->name('withdrawals.refresh');

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
    
    // Email Settings
    Route::get('settings/email', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'index'])->name('settings.email');
    Route::put('settings/email', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'update'])->name('settings.email.update');
    Route::post('settings/email/test', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'sendTestEmail'])->name('settings.email.test');

    // External Fulfillment Settings

    // Manual Queue Run Trigger (scheduler-bridge)
    Route::post('queue/run', [\App\Http\Controllers\Admin\ManualQueueRunController::class, 'run'])->name('queue.run');
});
Route::get('/vendor/product/create', [ProductController::class, 'create'])->name('product.create');
Route::post('/vendor/product', [ProductController::class, 'store'])->name('product.store');
Route::get('/vendor/product/{id}/edit', [ProductController::class, 'edit'])->name('product.edit');
Route::put('/vendor/product/{id}', [ProductController::class, 'update'])->name('product.update');
