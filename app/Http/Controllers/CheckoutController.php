<?php
namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\ResellerProduct;
use App\Models\NetworkService;
use App\Services\PaymentService;
use App\Services\PaystackPaymentService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
	protected $paymentService;

	public function __construct()
	{
		$this->paymentService = new PaymentService(new PaystackPaymentService());
	}

	public function show()
	{
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

		return view('checkout', compact('products'));
	}

    public function success($orderId)
    {
        $order = Order::findOrFail($orderId);
        return view('checkout.success', compact('order'));
    }	public function process(Request $request)
	{
		// If order_id is provided, find the order; otherwise, create a new order
		if ($request->filled('order_id')) {
			$order = Order::findOrFail($request->order_id);
		} else {
			$order = Order::create([
				'recipient_phone_number' => $request->recipient_phone,
				'mobile_money_number' => $request->payer_phone,
				'service_purchased' => $request->service_id ?? $request->service_purchased,
				'amount_paid' => $request->amount,
				'vendor_id' => $request->vendor_id,
				'vendor_service_id' => $request->original_product_id ?? $request->vendor_service_id,
				'reseller_product_id' => $request->reseller_product_id ?? null,
				'owner_vendor_id' => $request->owner_vendor_id ?? null,
				'reseller_vendor_id' => $request->reseller_vendor_id ?? null,
				'is_reseller_order' => $request->is_reseller_product ?? false,
				'status' => 'pending',
				'payment_status' => 'pending',
			]);
		}
		$recipientPhone = $request->recipient_phone;
		$amount = $request->amount;
		$transaction = $this->paymentService->processPayment($order, $recipientPhone, $amount);
		// Dispatch event for order completion
		event(new OrderCompleted($order));
		// ...additional logic (redirect, response, etc.)
		return response()->json(['transaction' => $transaction, 'order_id' => $order->id]);
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
