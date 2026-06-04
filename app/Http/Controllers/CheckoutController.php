<?php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\ResellerProduct;
use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Services\PaymentService;
use App\Services\PaystackPaymentService;
use Illuminate\Support\Facades\Auth;
use App\Services\GatewayManager;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class CheckoutController extends Controller
{
	protected PaymentService $paymentService;
	protected GatewayManager $gatewayManager;

	public function __construct(PaymentService $paymentService, GatewayManager $gatewayManager)
	{
		$this->paymentService = $paymentService;
		$this->gatewayManager = $gatewayManager;
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
			->whereHas('vendor', fn($q) => $q->where('is_approved', true))
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
			->whereHas('product', fn($q) => $q->where('is_active', true)->where('is_resellable', true))
			->whereHas('resellerVendor', fn($q) => $q->where('is_approved', true))
			->with(['product', 'resellerVendor:id,name,email,phone_number'])
			->get()
			->map(function ($resellerProduct) use ($networkServices) {
				$product = $resellerProduct->product;
				$metadata = $this->decodeDescription($product->description);
				
				return (object) [
					'id' => 'reseller_' . $resellerProduct->id,
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
		$products = $allProducts->map(fn($p) => [
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

		return $pdf->stream('receipt-order-' . $order->id . '.pdf');
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
		]);

		$isResellerOrder = $request->boolean('is_reseller_product', false);
		$originalProductId = $validated['original_product_id'] ?? null;
		$product = null;
		if (is_numeric($originalProductId)) {
			$product = Product::find((int) $originalProductId);
		}

		$resellerProduct = null;
		if ($isResellerOrder && ! empty($validated['reseller_product_id'])) {
			$resellerProduct = ResellerProduct::with(['product'])
				->where('id', (int) $validated['reseller_product_id'])
				->where('reseller_vendor_id', (int) $validated['vendor_id'])
				->first();

			if (! $resellerProduct) {
				return response()->json([
					'success' => false,
					'message' => 'The selected reseller product is unavailable.',
					'errors' => ['reseller_product_id' => ['The selected reseller product is unavailable.']],
				], 422);
			}
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

		$order = Order::create([
			'recipient_phone_number' => $validated['recipient_phone'],
			'mobile_money_number' => $payerPhoneForStorage,
			'mobile_money_network' => $validated['payer_network'] ?? null,
			'service_purchased' => $resolvedServiceName,
			'amount_paid' => $validated['amount'],
			'vendor_id' => $validated['vendor_id'],
			'vendor_service_id' => $product?->id,
			'reseller_product_id' => $resellerProduct?->id,
			'owner_vendor_id' => $isResellerOrder
				? ($resellerProduct?->owner_vendor_id ?? $product?->vendor_id)
				: null,
			'reseller_vendor_id' => $isResellerOrder ? (int) $validated['vendor_id'] : null,
			'is_reseller_order' => $isResellerOrder,
			'status' => 'Pending',
			'payment_status' => 'unpaid',
			'payment_gateway' => $gatewayName,
		]);

		// Use vendor's email for Paystack instead of requiring customer email
		$vendor = Vendor::find($validated['vendor_id']);
		$vendorEmail = $vendor->email ?? 'noreply@xtra4u.com';
		
		$init = $this->paymentService->initiatePayment($order, $vendorEmail, (float) $validated['amount']);

		if (! ($init['success'] ?? false)) {
			return response()->json([
				'success' => false,
				'message' => $init['message'] ?? 'Failed to start payment.',
				'order_id' => $order->id,
				'reference' => $init['reference'] ?? null,
				'errors' => $init['errors'] ?? null,
			], 422);
		}

		return response()->json([
			'success' => true,
			'message' => $init['message'] ?? 'Redirecting to payment.',
			'order_id' => $order->id,
			'reference' => $init['reference'] ?? null,
			'redirect' => $init['authorization_url'] ?? null,
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

		$verification = $this->paymentService->checkPaymentStatus($reference);
		if (! ($verification['success'] ?? false)) {
			return response()->json([
				'success' => false,
				'message' => $verification['message'] ?? 'Verification failed.',
			], 422);
		}

		$paymentStatus = strtolower((string) data_get($verification, 'data.status', ''));
		if ($paymentStatus === 'pending' || $paymentStatus === 'unknown' || $paymentStatus === '') {
			return response()->json([
				'success' => true,
				'status' => 'pending',
				'message' => 'Payment pending. Please approve the MoMo prompt.',
				'order_id' => $order->id,
			]);
		}

		$isSuccess = in_array($paymentStatus, ['success', 'successful', 'completed', 'paid'], true);

		if (! $isSuccess) {
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

		// Paystack returns amounts in kobo/pesewas (×100); normalizeAmount() handles the conversion.
		$amount = data_get($verification, 'data.amount');
		if ($amount) {
			$numericAmount = (float) $amount;
			$order->amount_paid = ($order->payment_gateway ?? null) === 'paystack'
				? PaystackPaymentService::normalizeAmount($numericAmount)
				: $numericAmount;
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
