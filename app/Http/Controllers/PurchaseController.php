<?php

namespace App\Http\Controllers;

use App\Mail\OrderPlacedMail;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    protected PaymentService $paymentService;

    protected CheckoutIntentGuard $intentGuard;

    public function __construct(?PaymentService $paymentService = null, ?CheckoutIntentGuard $intentGuard = null)
    {
        // Allow PaymentService to be injected (helpful for tests). If not provided,
        // fall back to the default implementation with GatewayManager.
        $this->paymentService = $paymentService ?? new PaymentService;
        $this->intentGuard = $intentGuard ?? app(CheckoutIntentGuard::class);
    }

    /**
     * Same Order-surface classifiers CheckoutController uses — this
     * controller creates rows in the same `orders` table via the same
     * lifecycle, so "done"/"failed"/"has a gateway to check" must agree.
     */
    private function orderIsSuccess(Order $order): bool
    {
        return in_array($order->payment_status, ['paid', 'completed'], true);
    }

    private function orderIsTerminalFailure(Order $order): bool
    {
        return $order->payment_status === 'failed';
    }

    private function orderHasGateway(Order $order): bool
    {
        return (bool) $order->payment_gateway && (bool) $order->payment_reference;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'recipient_phone_number' => 'required|string',
            // Make payer MoMo optional for vendor wallet purchases. The quick-buy
            // flow posts `pay_with_wallet=1` so we use `required_unless` to keep
            // it mandatory for gateway flows but optional for wallet flows.
            'mobile_money_number' => 'required_unless:pay_with_wallet,1|string',
            'vendor_id' => 'required|exists:vendors,id',
            'vendor_service_id' => 'required|exists:products,id',
            'is_reseller_product' => 'nullable|boolean',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
            // Phase 4: identifies one payment ATTEMPT for one checkout intent.
            // Optional for backward compatibility — see CheckoutIntentGuard.
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $idempotencyKey = $validated['idempotency_key'] ?? null;
        // VendorQuickBuyController reuses this action by building a bare
        // internal Request (Request::create() + Container::call()) that
        // never passes through the session middleware — $request->session()
        // throws on it. Fall back to a vendor-scoped intent (quick-buy is
        // always vendor-authenticated) rather than crashing; the auth guard
        // is resolved from the container's session store, not this specific
        // Request instance, so it still works correctly here.
        $intentScope = $request->hasSession()
            ? CheckoutIntentGuard::scopeForSession($request->session()->getId())
            : CheckoutIntentGuard::scopeForVendor((int) (auth('vendor')->id() ?? 0));

        $intentDecision = $this->intentGuard->evaluate(
            Order::class,
            $intentScope,
            $idempotencyKey,
            fn (Order $o) => $this->orderIsSuccess($o),
            fn (Order $o) => $this->orderIsTerminalFailure($o),
            fn (Order $o) => $this->orderHasGateway($o),
        );

        if ($intentDecision['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
            $existing = $intentDecision['payable'];

            return response()->json([
                'success' => true,
                'message' => 'Order paid and processed.',
                'order_id' => $existing->id,
                'redirect' => route('checkout.success', ['order' => $existing->id]),
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::STILL_PENDING) {
            $existing = $intentDecision['payable'];

            // Never initialize a second charge while the first is still
            // financially ambiguous.
            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous payment. Please don't pay again yet.",
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
                'verify_url' => route('checkout.verify'),
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE && $idempotencyKey) {
            $idempotencyKey = $this->intentGuard->freshKeyAfterFailure($idempotencyKey);
        }

        $isResellerOrder = $validated['is_reseller_product'] ?? false;

        if ($isResellerOrder && isset($validated['reseller_product_id'])) {
            // Handle reseller product
            $resellerProduct = ResellerProduct::with(['product', 'ownerVendor', 'resellerVendor'])
                ->where('id', $validated['reseller_product_id'])
                ->where('reseller_vendor_id', $validated['vendor_id'])
                ->where('is_active', true)
                ->first();

            if (! $resellerProduct || ! $resellerProduct->product || ! $resellerProduct->product->is_active) {
                throw ValidationException::withMessages([
                    'vendor_service_id' => 'The selected reseller product is unavailable.',
                ]);
            }

            $product = $resellerProduct->product;
            $price = $resellerProduct->selling_price;
        } else {
            // Handle regular product
            $product = Product::query()
                ->where('id', $validated['vendor_service_id'])
                ->where('vendor_id', $validated['vendor_id'])
                ->where('is_active', true)
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'vendor_service_id' => 'The selected vendor service is unavailable.',
                ]);
            }

            $price = $product->price;
            $resellerProduct = null;
        }

        // Wallet payment branch: vendor-initiated wallet purchases must use a
        // dedicated lightweight flow that bypasses reseller/affiliate commission
        // logic and avoids crediting wallets again. This prevents double-credit
        // and revenue leakage when vendors pay via their wallet from the dashboard.
        $payWithWallet = (bool) ($request->input('pay_with_wallet') ?? false);

        if ($payWithWallet && auth('vendor')->check()) {
            $walletService = new WalletService;
            $payingVendor = auth('vendor')->user();

            // Resolve the authoritative base price. If this is a reseller product,
            // we intentionally IGNORE reseller markup and treat the owner vendor's
            // base price as canonical for wallet purchases initiated by vendors.
            // Deliberately resolved OUTSIDE any lock/transaction — it depends only
            // on product data, never on the vendor's wallet balance.
            if ($isResellerOrder && isset($validated['reseller_product_id'])) {
                $walletResellerProduct = ResellerProduct::with(['product', 'ownerVendor'])
                    ->where('id', $validated['reseller_product_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $walletResellerProduct || ! $walletResellerProduct->product || ! $walletResellerProduct->product->is_active) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'vendor_service_id' => 'The selected reseller product is unavailable.',
                    ]);
                }

                $walletBasePrice = (float) $walletResellerProduct->base_price;
                $walletOwnerVendorId = $walletResellerProduct->owner_vendor_id;
                $walletProduct = $walletResellerProduct->product;
            } else {
                $walletProduct = Product::query()
                    ->where('id', $validated['vendor_service_id'])
                    ->where('vendor_id', $validated['vendor_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $walletProduct) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'vendor_service_id' => 'The selected vendor service is unavailable.',
                    ]);
                }

                $walletBasePrice = (float) $walletProduct->price;
                $walletOwnerVendorId = $walletProduct->vendor_id;
                $walletResellerProduct = null;
            }

            // Platform fee is charged upfront as 2% of base price.
            $walletPlatformFee = round($walletBasePrice * 0.02, 2);
            $walletCharge = round($walletBasePrice + $walletPlatformFee, 2);

            // Build order attributes. For reseller (affiliate) listings, the
            // paying vendor (reseller) should be the `vendor_id` so the order
            // appears on their dashboard. The owner vendor (product owner)
            // is recorded in `owner_vendor_id` so accounting records earnings
            // against them.
            $orderAttrs = [
                'recipient_phone_number' => $validated['recipient_phone_number'],
                'mobile_money_number' => $validated['mobile_money_number'] ?? $validated['recipient_phone_number'],
                'service_purchased' => $walletProduct->name,
                'amount_paid' => $walletCharge,
                'base_price' => $walletBasePrice,
                'platform_commission' => $walletPlatformFee,
                'vendor_service_id' => $walletProduct->id,
                'status' => 'Pending',
                'payment_status' => 'unpaid',
                'payment_source' => 'wallet',
            ];

            if ($isResellerOrder && $walletResellerProduct) {
                // Paying vendor is the reseller (the authenticated vendor)
                $orderAttrs['vendor_id'] = $payingVendor->id;
                $orderAttrs['is_reseller_order'] = true;
                $orderAttrs['reseller_vendor_id'] = $payingVendor->id;
                $orderAttrs['owner_vendor_id'] = $walletOwnerVendorId;
                $orderAttrs['reseller_product_id'] = $walletResellerProduct->id;
            } else {
                // Regular direct order - vendor is the product owner
                $orderAttrs['vendor_id'] = $walletOwnerVendorId;
                $orderAttrs['is_reseller_order'] = false;
            }

            // Phase 5 fix: resolve the SAME graceful idempotency outcome every
            // other payment path already uses, and create the order via
            // createOrReuse() (safe against a genuine concurrent double-click
            // — the DB unique index on (idempotency_scope, idempotency_key) is
            // what actually prevents two rows/two debits) BEFORE ever locking
            // the vendor row or touching the wallet. A concurrent duplicate
            // submission can therefore never reach the debit step twice, and
            // the loser gets the same friendly existing/in-progress/success
            // response as the gateway-payment path — never a raw SQL/
            // constraint exception.
            $creationResult = $this->intentGuard->createOrReuse(
                Order::class,
                $intentScope,
                $idempotencyKey,
                fn (?string $key) => Order::create(array_merge($orderAttrs, [
                    'idempotency_scope' => $key ? $intentScope : null,
                    'idempotency_key' => $key,
                ])),
                fn (Order $o) => $this->orderIsSuccess($o),
                fn (Order $o) => $this->orderIsTerminalFailure($o),
                fn (Order $o) => $this->orderHasGateway($o),
            );

            if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
                $existing = $creationResult['payable'];

                return $request->expectsJson()
                    ? response()->json(['success' => true, 'message' => 'Order paid with wallet and processed', 'order_id' => $existing->id])
                    : redirect()->route('checkout.success', ['order' => $existing->id])->with('success', 'Order paid with wallet and processed');
            }

            if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
                // A wallet debit has no gateway to re-poll — it is synchronous
                // by design — so "still pending" here only ever means another
                // concurrent request for this exact intent is mid-flight.
                $message = 'Your previous purchase is still being processed. Please wait a moment before trying again.';

                return $request->expectsJson()
                    ? response()->json(['success' => true, 'status' => 'confirming', 'message' => $message])
                    : back()->with('status', $message);
            }

            if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
                // See CheckoutController::process() for why this is vanishingly
                // rare and deliberately never reuses someone else's failed row.
                return $request->expectsJson()
                    ? response()->json(['success' => false, 'message' => 'Please try again.'], 409)
                    : back()->withErrors(['wallet' => 'Please try again.']);
            }

            // PROCEED: a fresh Order row for this intent was just created and
            // is uniquely ours — safe to lock the vendor and debit now.
            $order = $creationResult['payable'];

            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $order, $walletCharge, $walletBasePrice, $walletPlatformFee, $walletService, $payingVendor) {
                $lockedVendor = \App\Models\Vendor::whereKey($payingVendor->id)->lockForUpdate()->first();

                if (! $lockedVendor) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['vendor' => 'Vendor authentication required']);
                }

                // Debit using WalletService (which itself uses transactions/locks)
                $debited = $walletService->debitVendorFromTopups($lockedVendor->id, $walletCharge, [
                    'type' => 'vendor_wallet_order',
                    'base_price' => $walletBasePrice,
                    'platform_fee' => $walletPlatformFee,
                    'order_id' => $order->id,
                ]);

                if (! $debited) {
                    $order->update(['status' => 'Failed', 'payment_status' => 'failed']);
                    if ($request->expectsJson()) {
                        return response()->json(['success' => false, 'message' => 'Insufficient wallet balance'], 400);
                    }

                    return back()->withErrors(['wallet' => 'Insufficient wallet balance']);
                }

                // Complete the order using the vendor-wallet-specific completion
                // flow which DOES NOT credit any wallets or run reseller commission logic.
                $this->paymentService->completeVendorWalletOrder($order);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Order paid with wallet and processed',
                        'order_id' => $order->id,
                    ]);
                }

                return redirect()->route('checkout.success', ['order' => $order->id])
                    ->with('success', 'Order paid with wallet and processed');
            });
        }

        // Create order with pending status first. Race-safe: two concurrent
        // submissions of the same checkout intent can create at most one row
        // here — see CheckoutIntentGuard::createOrReuse().
        $creationResult = $this->intentGuard->createOrReuse(
            Order::class,
            $intentScope,
            $idempotencyKey,
            fn (?string $key) => Order::create([
                'recipient_phone_number' => $validated['recipient_phone_number'],
                'mobile_money_number' => $validated['mobile_money_number'] ?? $validated['recipient_phone_number'],
                'service_purchased' => $product->name,
                'amount_paid' => (float) $price,
                'vendor_id' => $validated['vendor_id'],
                'vendor_service_id' => $product->id,
                'status' => 'Pending',
                'payment_status' => 'unpaid',
                'payment_source' => $payWithWallet ? 'wallet' : null,
                'reseller_product_id' => $resellerProduct?->id ?? null,
                'owner_vendor_id' => $resellerProduct?->owner_vendor_id ?? null,
                'reseller_vendor_id' => $isResellerOrder ? $validated['vendor_id'] : null,
                'is_reseller_order' => $isResellerOrder,
                'idempotency_scope' => $key ? $intentScope : null,
                'idempotency_key' => $key,
            ]),
            fn (Order $o) => $this->orderIsSuccess($o),
            fn (Order $o) => $this->orderIsTerminalFailure($o),
            fn (Order $o) => $this->orderHasGateway($o),
        );

        if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
            $existing = $creationResult['payable'];

            return $request->expectsJson()
                ? response()->json([
                    'success' => true,
                    'message' => 'Order paid and processed.',
                    'order_id' => $existing->id,
                    'redirect' => route('checkout.success', ['order' => $existing->id]),
                ])
                : redirect()->route('checkout.success', ['order' => $existing->id]);
        }

        if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
            $existing = $creationResult['payable'];
            $message = "We're still confirming your previous payment. Please don't pay again yet.";

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'status' => 'confirming',
                    'message' => $message,
                    'order_id' => $existing->id,
                    'reference' => $existing->payment_reference,
                    'redirect' => route('checkout.show').'?payment_pending=1&reference='.$existing->payment_reference,
                ]);
            }

            return redirect()->route('checkout.show')
                ->with('payment_pending', true)
                ->with('payment_reference', $existing->payment_reference)
                ->with('payment_message', $message);
        }

        if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
            // See CheckoutController::process() for why this is vanishingly
            // rare and deliberately never reuses someone else's failed row.
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Please try again.'], 409)
                : back()->withErrors(['payment' => 'Please try again.']);
        }

        // PROCEED: a fresh Order row for this intent was just created.
        $order = $creationResult['payable'];

        // If wallet was used, run existing PaymentService pipeline to complete the order
        if ($payWithWallet) {
            // PaymentService->completeOrder expects to execute the existing payout logic.
            $this->paymentService->completeOrder($order);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order paid with wallet and processed',
                    'order_id' => $order->id,
                ]);
            }

            return redirect()->route('checkout.success', ['order' => $order->id])
                ->with('success', 'Order paid with wallet and processed');
        }

        // Use vendor's email for Paystack instead of requiring customer email
        $vendor = Vendor::find($validated['vendor_id']);
        $vendorEmail = $vendor->email ?? 'noreply@xtra4u.com';

        // Initiate payment
        $paymentResult = $this->paymentService->initiatePayment(
            $order,
            $vendorEmail,
            (float) $price
        );

        if ($paymentResult['success']) {
            // Return JSON response for AJAX or redirect for form submit
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $paymentResult['message'],
                    'reference' => $paymentResult['reference'],
                    'authorization_url' => $paymentResult['authorization_url'] ?? null,
                    'gateway_name' => $paymentResult['gateway_name'] ?? null,
                    'order_id' => $order->id,
                    'redirect' => route('checkout.show').'?payment_pending=1&reference='.$paymentResult['reference'],
                ]);
            }

            return redirect()->route('checkout.show')
                ->with('payment_pending', true)
                ->with('payment_reference', $paymentResult['reference'])
                ->with('payment_message', $paymentResult['message']);
        }

        // Payment initiation failed - mark order as failed
        $order->update([
            'status' => 'Failed',
            'payment_status' => 'failed',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $paymentResult['message'] ?? 'Payment failed. Please try again.',
            ], 400);
        }

        return back()->withErrors(['payment' => $paymentResult['message'] ?? 'Payment failed. Please try again.']);
    }

    /**
     * Legacy callback for session-based flow (kept for backwards compatibility)
     */
    public function paymentCallback(Request $request, string $token)
    {
        $stored = session()->pull("purchase.tokens.$token");

        if (! $stored) {
            abort(410, 'Purchase session expired or invalid.');
        }

        if (isset($stored['expires_at']) && Carbon::now()->greaterThan(Carbon::parse($stored['expires_at']))) {
            abort(410, 'Purchase session expired.');
        }

        $payload = $stored['payload'] ?? [];

        $validated = validator($payload, [
            'recipient_phone_number' => 'required|string',
            'mobile_money_number' => 'required|string',
            'vendor_id' => 'required|exists:vendors,id',
            'vendor_service_id' => 'required|exists:products,id',
            'service_purchased' => 'required|string',
            'amount_paid' => 'required|numeric',
            'payment_status' => 'required|in:completed,failed',
            'is_reseller_product' => 'nullable|boolean',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
        ])->validate();

        $isResellerOrder = $validated['is_reseller_product'] ?? false;

        if ($isResellerOrder && isset($validated['reseller_product_id'])) {
            $resellerProduct = ResellerProduct::with(['product', 'ownerVendor', 'resellerVendor'])
                ->where('id', $validated['reseller_product_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $product = $resellerProduct->product;
            $amountPaid = (float) $resellerProduct->selling_price;
        } else {
            $product = Product::query()
                ->where('id', $validated['vendor_service_id'])
                ->where('vendor_id', $validated['vendor_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $amountPaid = (float) $product->price;
            $resellerProduct = null;
        }

        if ((float) $validated['amount_paid'] !== $amountPaid) {
            abort(422, 'Payment amount mismatch detected.');
        }

        // Regular orders complete immediately, reseller orders start as Pending for owner to fulfill
        $isReseller = $isResellerOrder && $resellerProduct;
        if ($validated['payment_status'] === 'completed') {
            $status = $isReseller ? 'Pending' : 'Completed';
        } else {
            $status = 'Failed';
        }

        $postCommitEmails = [];

        $order = DB::transaction(function () use ($validated, $product, $amountPaid, $status, $isResellerOrder, $resellerProduct, &$postCommitEmails) {
            if ($isResellerOrder && $resellerProduct) {
                // **RESELLER ORDER - Split Payment**
                $basePrice = $resellerProduct->base_price;
                $markupPrice = $resellerProduct->markup_price;

                // Calculate commissions (2% each)
                $ownerCommission = round($basePrice * 0.02, 2);
                $resellerCommission = round($markupPrice * 0.02, 2);
                $totalPlatformCommission = $ownerCommission + $resellerCommission;

                // Calculate earnings (after commission)
                $ownerEarning = round($basePrice - $ownerCommission, 2);
                $resellerEarning = round($markupPrice - $resellerCommission, 2);

                // Create order with reseller info
                $order = Order::create([
                    'recipient_phone_number' => $validated['recipient_phone_number'],
                    'mobile_money_number' => $validated['mobile_money_number'] ?? $validated['recipient_phone_number'],
                    'service_purchased' => $product->name,
                    'amount_paid' => $amountPaid,
                    'vendor_id' => $resellerProduct->reseller_vendor_id, // The reseller is the primary vendor
                    'vendor_service_id' => $product->id,
                    'status' => $status,
                    'reseller_product_id' => $resellerProduct->id,
                    'owner_vendor_id' => $resellerProduct->owner_vendor_id,
                    'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                    'base_price' => $basePrice,
                    'markup_price' => $markupPrice,
                    'owner_earning' => $ownerEarning,
                    'reseller_earning' => $resellerEarning,
                    'platform_commission' => $totalPlatformCommission,
                    'is_reseller_order' => true,
                ]);

                // Create transaction for OWNER VENDOR (base price earnings)
                Transaction::create([
                    'order_id' => $order->id,
                    'vendor_id' => $resellerProduct->owner_vendor_id,
                    'recipient_phone' => $validated['recipient_phone_number'],
                    'amount' => $basePrice,
                    'commission_amount' => $ownerCommission,
                    'vendor_earning' => $ownerEarning,
                    'payment_status' => strtolower($status),
                ]);

                // Create transaction for RESELLER VENDOR (markup earnings)
                Transaction::create([
                    'order_id' => $order->id,
                    'vendor_id' => $resellerProduct->reseller_vendor_id,
                    'recipient_phone' => $validated['recipient_phone_number'],
                    'amount' => $markupPrice,
                    'commission_amount' => $resellerCommission,
                    'vendor_earning' => $resellerEarning,
                    'payment_status' => strtolower($status),
                ]);

                Log::info('Reseller order created with split payment', [
                    'order_id' => $order->id,
                    'owner_vendor_id' => $resellerProduct->owner_vendor_id,
                    'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                    'base_price' => $basePrice,
                    'markup_price' => $markupPrice,
                    'owner_earning' => $ownerEarning,
                    'reseller_earning' => $resellerEarning,
                    'platform_commission' => $totalPlatformCommission,
                ]);

                // Send notification to OWNER vendor (affiliate order)
                VendorNotification::create([
                    'vendor_id' => $resellerProduct->owner_vendor_id,
                    'type' => VendorNotification::TYPE_AFFILIATE_ORDER,
                    'title' => 'New Affiliate Order',
                    'message' => "A reseller has made a sale of your product '{$product->name}'. Your earning: GHS ".number_format($ownerEarning, 2),
                    'order_id' => $order->id,
                    'data' => [
                        'product_name' => $product->name,
                        'base_price' => $basePrice,
                        'earning' => $ownerEarning,
                        'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                    ],
                ]);

                // Send notification to RESELLER vendor
                VendorNotification::create([
                    'vendor_id' => $resellerProduct->reseller_vendor_id,
                    'type' => VendorNotification::TYPE_NEW_ORDER,
                    'title' => 'New Order Received',
                    'message' => "You have a new order for '{$product->name}'. Your markup earning: GHS ".number_format($resellerEarning, 2),
                    'order_id' => $order->id,
                    'data' => [
                        'product_name' => $product->name,
                        'markup_price' => $markupPrice,
                        'earning' => $resellerEarning,
                    ],
                ]);

                // Send notification to ADMIN
                AdminNotification::notifyNewOrder($order);

                // Send EMAIL to OWNER vendor (product owner)
                $postCommitEmails[] = [
                    'vendor_id' => (int) $resellerProduct->owner_vendor_id,
                    'role' => 'owner',
                    'earning' => (float) $ownerEarning,
                ];

                // Send EMAIL to RESELLER vendor
                $postCommitEmails[] = [
                    'vendor_id' => (int) $resellerProduct->reseller_vendor_id,
                    'role' => 'reseller',
                    'earning' => (float) $resellerEarning,
                ];
            } else {
                // **REGULAR ORDER - Standard 2% commission**
                $commission = round($amountPaid * 0.02, 2);
                $vendorEarnings = round($amountPaid - $commission, 2);

                $order = Order::create([
                    'recipient_phone_number' => $validated['recipient_phone_number'],
                    'mobile_money_number' => $validated['mobile_money_number'] ?? $validated['recipient_phone_number'],
                    'service_purchased' => $product->name,
                    'amount_paid' => $amountPaid,
                    'vendor_id' => $product->vendor_id,
                    'vendor_service_id' => $product->id,
                    'status' => $status,
                    'is_reseller_order' => false,
                ]);

                Transaction::create([
                    'order_id' => $order->id,
                    'vendor_id' => $product->vendor_id,
                    'recipient_phone' => $validated['recipient_phone_number'],
                    'amount' => $amountPaid,
                    'commission_amount' => $commission,
                    'vendor_earning' => $vendorEarnings,
                    'payment_status' => strtolower($status),
                ]);

                $postCommitEmails[] = [
                    'vendor_id' => (int) $product->vendor_id,
                    'role' => 'direct',
                    'earning' => (float) $vendorEarnings,
                ];

                Log::info('Regular order created', [
                    'order_id' => $order->id,
                    'commission' => $commission,
                    'vendor_earnings' => $vendorEarnings,
                ]);

                // Send notification to vendor
                VendorNotification::create([
                    'vendor_id' => $product->vendor_id,
                    'type' => VendorNotification::TYPE_NEW_ORDER,
                    'title' => 'New Order Received',
                    'message' => "You have a new order for '{$product->name}'. Earning: GHS ".number_format($vendorEarnings, 2),
                    'order_id' => $order->id,
                    'data' => [
                        'product_name' => $product->name,
                        'amount' => $amountPaid,
                        'earning' => $vendorEarnings,
                    ],
                ]);

                // Send notification to ADMIN
                AdminNotification::notifyNewOrder($order);

                // Email is sent after commit (outside the DB transaction).
            }

            return $order;
        });

        // External side-effects must not run inside DB transactions.
        // Keep synchronous delivery here to preserve legacy callback behavior.
        foreach ($postCommitEmails as $email) {
            try {
                $vendor = Vendor::find((int) $email['vendor_id']);
                if ($vendor && $vendor->email) {
                    Mail::to($vendor->email)->send(new OrderPlacedMail(
                        $order,
                        $vendor,
                        (string) $email['role'],
                        (float) $email['earning']
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send legacy callback order email', [
                    'order_id' => $order?->id,
                    'vendor_id' => $email['vendor_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('checkout_success', compact('order'));
    }
}
