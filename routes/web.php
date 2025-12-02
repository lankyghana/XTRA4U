<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorRequestController;
use App\Http\Controllers\VendorAuthController;
use App\Http\Controllers\VendorDashboardController;
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

Route::get('/', [StorefrontController::class, 'index'])->name('storefront.index');
Route::get('/store/{vendor}', [StorefrontController::class, 'showVendorStore'])->name('storefront.vendor');
Route::get('/vendor/request', [VendorController::class, 'showRequestForm'])->name('vendor.request.form');
Route::get('/vendor/request/create', [VendorRequestController::class, 'create'])->name('vendor.request.create');
Route::post('/vendor/request', [VendorRequestController::class, 'store'])->name('vendor.request.store');
Route::post('/vendor/request/submit', [VendorController::class, 'submitRequest'])->name('vendor.request.submit');
Route::get('/vendor/login', [VendorAuthController::class, 'showLoginForm'])->name('vendor.login.form');
Route::post('/vendor/login', [VendorAuthController::class, 'login'])->name('vendor.login');
Route::middleware(['vendor.approved'])
    ->prefix('vendor')
    ->name('vendor.')
    ->group(function () {
        Route::get('dashboard', [VendorDashboardController::class, 'index'])->name('dashboard');
        Route::resource('products', ProductController::class);

        Route::get('orders', fn () => redirect()->route('vendor.dashboard'))
            ->name('orders.index');
        Route::get('transactions', fn () => redirect()->route('vendor.dashboard'))
            ->name('transactions.index');

        Route::get('withdrawals', [VendorDashboardController::class, 'withdrawals'])
            ->name('withdrawals.index');
        Route::post('withdrawals', [VendorDashboardController::class, 'requestWithdrawal'])
            ->name('withdrawals.store');
    });
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'process'])->name('checkout.process');
Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name('checkout.success');
Route::middleware('prune.purchase.tokens')->group(function () {
    Route::post('/purchase', [\App\Http\Controllers\PurchaseController::class, 'store'])->name('purchase');
    Route::get('/purchase/callback/{token}', [\App\Http\Controllers\PurchaseController::class, 'paymentCallback'])->name('purchase.callback');
});
Route::post('/payment/callback', [PaymentCallbackController::class, 'handle'])->name('payment.callback');

Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

Route::middleware(['admin.only'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::resource('vendors', AdminVendorController::class)->only(['index', 'update', 'destroy']);
    Route::post('vendors/{vendor}/approve', [AdminVendorController::class, 'approve'])->name('vendors.approve');
    Route::post('vendors/{vendor}/reject', [AdminVendorController::class, 'reject'])->name('vendors.reject');
    Route::resource('orders', AdminOrderController::class);
    Route::resource('transactions', AdminTransactionController::class);
    Route::get('withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::post('withdrawals/{withdrawal}/processing', [AdminWithdrawalController::class, 'markProcessing'])->name('withdrawals.processing');
    Route::post('withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->name('withdrawals.approve');
    Route::post('withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])->name('withdrawals.reject');
});
Route::get('/vendor/product/create', [ProductController::class, 'create'])->name('product.create');
Route::post('/vendor/product', [ProductController::class, 'store'])->name('product.store');
Route::get('/vendor/product/{id}/edit', [ProductController::class, 'edit'])->name('product.edit');
Route::put('/vendor/product/{id}', [ProductController::class, 'update'])->name('product.update');
