<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
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

Route::get('/', [StorefrontController::class, 'index'])->name('storefront.index');
Route::get('/store/{vendor}', [StorefrontController::class, 'showVendorStore'])->name('storefront.vendor');
Route::get('/vendor/request', [VendorController::class, 'showRequestForm'])->name('vendor.request.form');
Route::get('/vendor/request/create', [VendorRequestController::class, 'create'])->name('vendor.request.create');
Route::post('/vendor/request', [VendorRequestController::class, 'store'])->name('vendor.request.store');
Route::post('/vendor/request/submit', [VendorController::class, 'submitRequest'])->name('vendor.request.submit');
Route::get('/vendor/login', [VendorAuthController::class, 'showLoginForm'])->name('vendor.login.form');
Route::post('/vendor/login', [VendorAuthController::class, 'login'])->name('vendor.login');
Route::middleware(['vendor.approved'])->group(function () {
    Route::get('/vendor/dashboard', [VendorDashboardController::class, 'index'])->name('vendor.dashboard');
    Route::resource('/vendor/products', ProductController::class);
    Route::resource('/vendor/orders', OrderController::class)->only(['index', 'show']);
    Route::resource('/vendor/transactions', TransactionController::class)->only(['index', 'show']);
});
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'process'])->name('checkout.process');
Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name('checkout.success');
Route::middleware('prune.purchase.tokens')->group(function () {
    Route::post('/purchase', [\App\Http\Controllers\PurchaseController::class, 'store'])->name('purchase');
    Route::get('/purchase/callback/{token}', [\App\Http\Controllers\PurchaseController::class, 'paymentCallback'])->name('purchase.callback');
});
Route::post('/payment/callback', [PaymentCallbackController::class, 'handle'])->name('payment.callback');
Route::middleware(['admin.only'])->prefix('admin')->group(function () {
    Route::resource('vendors', AdminVendorController::class);
    Route::resource('orders', AdminOrderController::class);
    Route::resource('transactions', AdminTransactionController::class);
});
Route::get('/vendor/product/create', [ProductController::class, 'create'])->name('product.create');
Route::post('/vendor/product', [ProductController::class, 'store'])->name('product.store');
Route::get('/vendor/product/{id}/edit', [ProductController::class, 'edit'])->name('product.edit');
Route::put('/vendor/product/{id}', [ProductController::class, 'update'])->name('product.update');
