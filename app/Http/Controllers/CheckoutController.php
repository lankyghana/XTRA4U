<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use App\Services\GatewayManager;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\PaymentService;
use App\Support\PaymentVerificationState;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    protected PaymentService $paymentService;

    protected GatewayManager $gatewayManager;

    protected CheckoutIntentGuard $intentGuard;

    public function __construct(PaymentService $paymentService, GatewayManager $gatewayManager, ?CheckoutIntentGuard $intentGuard = null)
    {
        $this->paymentService = $paymentService;
        $this->gatewayManager = $gatewayManager;
        $this->intentGuard = $intentGuard ?? app(CheckoutIntentGuard::class);
    }

    /**
     * Order-surface success/failure classifiers shared by every place this
     * controller consults CheckoutIntentGuard — kept here once so the
     * "what counts as done" definition can never drift between call sites.
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

    public function show()
    {
        if (config('storefront.checkout_coming_soon')) {
            return view('pages.coming-soon', [
                'title' => 'Marketplace',
                'subtitle' => 'We\'re working on something great.',
                'primaryCta' => [
                    'label' => 'Back to Home',
                    'href' => route('storefront.index'),
                ],
            ]);
        }

        // Fetch network services for image lookup
        $networkServices = NetworkService::active()
            ->whereNotNull('image_path')
            ->get()
            ->keyBy(fn ($service) => strtolower($service->name));

        // Collect all products from all vendors (both owned and reseller)
        $allProducts = collect();

        // Get all owned products from approved vendors
        $ownedProducts = Product::where('is_active', true)
            ->whereHas('vendor', fn ($q) => $q->where('is_approved', true))
            ->with('vendor:id,name,email,phone_number')
            ->get()
            ->map(function ($product) use ($networkServices) {
                $metadata = $this->decodeDescription($product->description);

                return (object) [
                    'id' => $product->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'vendor_id' => $product->vendor_id,
                    'vendor_name' => $product->vendor->name,
                    'vendor_email' => $product->vendor->email,
                    'vendor_phone' => $product->vendor->phone_number,
                    'is_reseller_product' => false,
                    'reseller_product_id' => null,
                    'original_product_id' => $product->id,
                    'metadata' => $metadata,
                    'display_description' => $metadata['notes'] ?? $metadata['description'] ?? $product->description,
                    'network' => $metadata['network'] ?? null,
                    'size' => $metadata['size'] ?? null,
                    'validity' => $metadata['validity'] ?? null,
                    'tag' => $metadata['tag'] ?? null,
                    'network_image_url' => isset($metadata['network'])
                        ? ($networkServices->get(strtolower($metadata['network']))?->image_url)
                        : null,
                ];
            });

        $allProducts = $allProducts->concat($ownedProducts);

        // Get all reseller products
        $resellerProducts = ResellerProduct::where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('is_resellable', true))
            ->whereHas('resellerVendor', fn ($q) => $q->where('is_approved', true))
            ->with(['product', 'resellerVendor:id,name,email,phone_number'])
            ->get()
            ->map(function ($resellerProduct) use ($networkServices) {
                $product = $resellerProduct->product;
                $metadata = $this->decodeDescription($product->description);

                return (object) [
                    'id' => 'reseller_'.$resellerProduct->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'price' => $resellerProduct->selling_price,
                    'vendor_id' => $resellerProduct->reseller_vendor_id,
                    'vendor_name' => $resellerProduct->resellerVendor->name,
                    'vendor_email' => $resellerProduct->resellerVendor->email,
                    'vendor_phone' => $resellerProduct->resellerVendor->phone_number,
                    'is_reseller_product' => true,
                    'reseller_product_id' => $resellerProduct->id,
                    'original_product_id' => $product->id,
                    'metadata' => $metadata,
                    'display_description' => $metadata['notes'] ?? $metadata['description'] ?? $product->description,
                    'network' => $metadata['network'] ?? null,
                    'size' => $metadata['size'] ?? null,
                    'validity' => $metadata['validity'] ?? null,
                    'tag' => $metadata['tag'] ?? null,
                    'network_image_url' => isset($metadata['network'])
                        ? ($networkServices->get(strtolower($metadata['network']))?->image_url)
                        : null,
                ];
            });

        $allProducts = $allProducts->concat($resellerProducts);

        // Shuffle to randomize and reduce monopoly
        $allProducts = $allProducts->shuffle();

        // Convert to simple arrays for JSON serialization
        $products = $allProducts->map(fn ($p) => [
            'id' => $p->id,
            'product_id' => $p->product_id ?? $p->id,
            'name' => $p->name,
            'price' => $p->price,
            'vendor_id' => $p->vendor_id,
            'vendor_name' => $p->vendor_name,
            'is_reseller_product' => $p->is_reseller_product ?? false,
            'reseller_product_id' => $p->reseller_product_id ?? null,
            'original_product_id' => $p->original_product_id ?? $p->id,
            'description' => $p->display_description ?? '',
            'network' => $p->network ?? null,
            'size' => $p->size ?? null,
            'validity' => $p->validity ?? null,
        ])->values();

        $currentVendor = Auth::guard('vendor')->user();

        return view('checkout', compact('products', 'currentVendor'));
    }

    public function success($orderId)
    {
        $order = Order::with(['service', 'vendor', 'ownerVendor', 'resellerVendor'])->findOrFail($orderId);

        return view('checkout.success', compact('order'));
    }

    public function receipt(Order $order)
    {
        $order->loadMissing(['service', 'vendor', 'ownerVendor', 'resellerVendor']);

        $pdf = Pdf::loadView('checkout.receipt', [
            'order' => $order,
        ])->setPaper('a4');

        return $pdf->stream('receipt-order-'.$order->id.'.pdf');
    }

    public function process(Request $request)
    {
        $gatewayName = $this->gatewayManager->getDefaultGatewayName(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION) ?? 'paystack';

        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'category_id' => 'nullable|string',
            'service_id' => 'required|string',
            'service_name' => 'nullable|string|max:255',
            'package_id' => 'required',
            'package_name' => 'nullable|string|max:255',
            'service_purchased' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.1',
            'recipient_phone' => 'required|string',
            // Backward compatible: older clients may still send payer_phone.
            'payer_phone' => 'nullable|string',
            'payer_network' => 'nullable|string|in:MTN,TELECEL,AIRTELTIGO',
            'is_reseller_product' => 'sometimes|boolean',
            'reseller_product_id' => 'nullable',
            'original_product_id' => 'nullable',
            // Phase 4: identifies one payment ATTEMPT for one checkout intent.
            // Optional for backward compatibility with older clients — see
            // CheckoutIntentGuard::evaluate() for what happens when absent.
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $intentScope = CheckoutIntentGuard::scopeForSession($request->session()->getId());

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
                'message' => 'Payment already completed.',
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
                'redirect' => route('checkout.success', ['order' => $existing->id]),
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::STILL_PENDING) {
            $existing = $intentDecision['payable'];

            // Never initialize a second charge while the first is still
            // financially ambiguous — see Phase 4 section 3/5/17-J.
            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous payment. Please don't pay again yet.",
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
                'verify_url' => route('checkout.verify'),
            ]);
        }

        // PROCEED: no existing row for this intent. RETRY_AFTER_FAILURE: the
        // previous attempt was authoritatively confirmed failed — safe to
        // create a brand-new attempt, but never by reusing its retired key.
        if ($intentDecision['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE && $idempotencyKey) {
            $idempotencyKey = $this->intentGuard->freshKeyAfterFailure($idempotencyKey);
        }

        $isResellerOrder = $request->boolean('is_reseller_product', false);
        $originalProductId = $validated['original_product_id'] ?? null;
        $product = null;
        $resellerProduct = null;

        if ($isResellerOrder && ! empty($validated['reseller_product_id'])) {
            $resellerProduct = ResellerProduct::with(['product'])
                ->where('id', (int) $validated['reseller_product_id'])
                ->where('reseller_vendor_id', (int) $validated['vendor_id'])
                ->where('is_active', true)
                ->first();

            if (! $resellerProduct || ! $resellerProduct->product || ! $resellerProduct->product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected reseller product is unavailable.',
                    'errors' => ['reseller_product_id' => ['The selected reseller product is unavailable.']],
                ], 422);
            }
        } elseif (is_numeric($originalProductId)) {
            // Owned-product order. The product must genuinely belong to the vendor
            // the customer is buying from — without this check, a forged request
            // could pair a valid vendor_id with a different vendor's (cheaper)
            // original_product_id and have the price derived from that mismatch.
            $product = Product::query()
                ->where('id', (int) $originalProductId)
                ->where('vendor_id', (int) $validated['vendor_id'])
                ->where('is_active', true)
                ->first();
        }

        if (! $product && ! $resellerProduct) {
            return response()->json([
                'success' => false,
                'message' => 'The selected product is unavailable.',
                'errors' => ['original_product_id' => ['The selected product is unavailable.']],
            ], 422);
        }

        // Authoritative price. The client-submitted `amount` is validated above
        // only for backward compatibility with older clients and is never used
        // to derive what the customer is actually charged — the price always
        // comes from the product/reseller-listing row resolved (and vendor-
        // matched) server-side above.
        $authoritativeAmount = $resellerProduct
            ? (float) $resellerProduct->selling_price
            : (float) $product->price;

        // A resolved price of zero (or less) is a data/config issue, not a valid
        // sale — never create a free/negative order regardless of what the
        // client sent for `amount`.
        if ($authoritativeAmount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'The selected product is unavailable.',
                'errors' => ['original_product_id' => ['The selected product is unavailable.']],
            ], 422);
        }

        // Server-side service availability guard. The category is resolved from the
        // underlying product metadata so a stale or forged client cannot buy a
        // category an admin has closed.
        $categoryProduct = $resellerProduct?->product ?? $product;
        $category = \App\Support\ServiceAvailability::categoryForProduct($categoryProduct);
        if (\App\Support\ServiceAvailability::isClosed($category)) {
            return response()->json([
                'success' => false,
                'message' => \App\Support\ServiceAvailability::message(),
                'errors' => ['service' => [\App\Support\ServiceAvailability::message()]],
            ], 422);
        }

        $resolvedServiceName = $product?->name
            ?? $resellerProduct?->product?->name
            ?? ($validated['service_purchased'] ?? null)
            ?? ($validated['package_name'] ?? null)
            ?? ($validated['service_name'] ?? null)
            ?? 'Unknown Service';

        // orders.mobile_money_number is NOT NULL in the schema.
        // For redirect/hosted gateways we don't require payer_phone on our UI, so fall back to recipient.
        $payerPhoneForStorage = (string) ($validated['payer_phone'] ?? $validated['recipient_phone']);

        $creationResult = $this->intentGuard->createOrReuse(
            Order::class,
            $intentScope,
            $idempotencyKey,
            fn (?string $key) => Order::create([
                'recipient_phone_number' => $validated['recipient_phone'],
                'mobile_money_number' => $payerPhoneForStorage,
                'mobile_money_network' => $validated['payer_network'] ?? null,
                'service_purchased' => $resolvedServiceName,
                'amount_paid' => $authoritativeAmount,
                'vendor_id' => $validated['vendor_id'],
                'vendor_service_id' => $product?->id ?? $resellerProduct?->product?->id,
                'reseller_product_id' => $resellerProduct?->id,
                'owner_vendor_id' => $isResellerOrder
                    ? ($resellerProduct?->owner_vendor_id ?? $product?->vendor_id)
                    : null,
                'reseller_vendor_id' => $isResellerOrder ? (int) $validated['vendor_id'] : null,
                'is_reseller_order' => $isResellerOrder,
                'status' => 'Pending',
                'payment_status' => 'unpaid',
                'payment_gateway' => $gatewayName,
                'idempotency_scope' => $key ? $intentScope : null,
                'idempotency_key' => $key,
            ]),
            fn (Order $o) => $this->orderIsSuccess($o),
            fn (Order $o) => $this->orderIsTerminalFailure($o),
            fn (Order $o) => $this->orderHasGateway($o),
        );

        if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
            $existing = $creationResult['payable'];

            return response()->json([
                'success' => true,
                'message' => 'Payment already completed.',
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
                'redirect' => route('checkout.success', ['order' => $existing->id]),
            ]);
        }

        if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
            $existing = $creationResult['payable'];

            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous payment. Please don't pay again yet.",
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
                'verify_url' => route('checkout.verify'),
            ]);
        }

        if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
            // Vanishingly rare: createOrReuse()'s single automatic retry
            // also collided with an already-failed row. Never treat someone
            // else's failed row as this request's new order — ask the
            // client to resubmit rather than misattributing it.
            return response()->json([
                'success' => false,
                'message' => 'Please try again.',
            ], 409);
        }

        // PROCEED: a fresh Order row for this intent was just created.
        $order = $creationResult['payable'];

        // Use vendor's email for Paystack instead of requiring customer email
        $vendor = Vendor::find($validated['vendor_id']);
        $vendorEmail = $vendor->email ?? 'noreply@xtra4u.com';

        $init = $this->paymentService->initiatePayment($order, $vendorEmail, $authoritativeAmount);

        if (! ($init['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $init['message'] ?? 'Failed to start payment.',
                'order_id' => $order->id,
                'reference' => $init['reference'] ?? null,
                'errors' => $init['errors'] ?? null,
            ], 422);
        }

        // flow_type/gateway_name/checkout_config are only surfaced for gateways
        // that launch a client-side SDK popup (currently Payaza only). They are
        // deliberately omitted for every other gateway: the checkout page's JS
        // has an existing (currently unreached) `flow_type === 'inline'` branch,
        // and previously never received `flow_type` at all for this endpoint —
        // adding it unconditionally would silently change Moolre/BulkClix's
        // existing checkout behaviour, which is out of scope here.
        $isSdkPopupFlow = ! empty($init['checkout_config']);

        return response()->json([
            'success' => true,
            'message' => $init['message'] ?? 'Redirecting to payment.',
            'order_id' => $order->id,
            'reference' => $init['reference'] ?? null,
            'redirect' => $init['authorization_url'] ?? null,
            'flow_type' => $isSdkPopupFlow ? $init['flow_type'] : null,
            'gateway_name' => $isSdkPopupFlow ? ($init['gateway_name'] ?? null) : null,
            // Never contains secret credentials — see PayazaPaymentService::buildCheckoutConfig().
            'checkout_config' => $isSdkPopupFlow ? $init['checkout_config'] : null,
            'callback_url' => route('payment.callback'),
            'verify_url' => route('checkout.verify'),
        ]);
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = (string) $validated['reference'];
        $order = Order::where('payment_reference', $reference)->first();
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found for reference.',
            ], 404);
        }

        // Verify against the gateway that actually created this order — never
        // whichever gateway is currently the admin default — so switching the
        // default gateway can never affect an order already in flight under
        // a different one.
        $verification = $this->paymentService->checkPaymentStatusForGateway($reference, $order->payment_gateway);

        $state = PaymentVerificationState::from($verification);

        if ($state === PaymentVerificationState::FAILED) {
            $order->update([
                'payment_status' => 'failed',
                'status' => 'Failed',
            ]);
            \App\Models\Transaction::where('order_id', $order->id)
                ->whereNotIn('payment_status', ['completed', 'successful'])
                ->update(['payment_status' => 'failed']);

            return response()->json([
                'success' => true,
                'status' => 'failed',
                'message' => 'Payment failed.',
                'order_id' => $order->id,
            ]);
        }

        if ($state !== PaymentVerificationState::SUCCESS) {
            // PENDING (gateway says still processing) or UNKNOWN (the verify
            // call itself failed). Neither is proof the customer wasn't
            // charged — leave the order untouched and keep polling.
            return response()->json([
                'success' => true,
                'status' => 'pending',
                'message' => 'Payment pending. Please approve the MoMo prompt.',
                'order_id' => $order->id,
            ]);
        }

        // Every gateway's verifyPayment() already normalizes data.amount to major
        // units (GHS) — including Paystack's, which converts from pesewas internally.
        $amount = data_get($verification, 'data.amount');

        // Amount mismatch guard. For gateways that lock the amount in via a real
        // server-to-server "initialize" call (Paystack/Flutterwave/Moolre/BulkClix),
        // the verified amount can never legitimately be less than what we expect,
        // so this is a no-op for them. It matters for Payaza: its Web Checkout SDK
        // sets checkout_amount client-side with no prior server-locked amount, so a
        // tampered popup could otherwise get a genuinely-paid-but-too-small amount
        // waved through. Never fulfil on a lower-than-expected confirmed amount.
        $expectedAmount = (float) $order->amount_paid;
        if ($amount !== null && $expectedAmount > 0 && round((float) $amount, 2) < round($expectedAmount, 2)) {
            Log::error('Checkout verify: verified amount is less than expected order amount - refusing to fulfil', [
                'order_id' => $order->id,
                'reference' => $reference,
                'expected_amount' => $expectedAmount,
                'verified_amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'failed',
                'message' => 'Payment amount mismatch. Please contact support.',
                'order_id' => $order->id,
            ]);
        }

        if ($amount) {
            $order->amount_paid = (float) $amount;
        }

        $this->paymentService->completeOrder($order);

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Payment completed.',
            'order_id' => $order->id,
            'redirect' => route('checkout.success', ['order' => $order->id]),
        ]);
    }

    private function decodeDescription(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }
}
