<?php
namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\ResellerProduct;
use App\Models\NetworkService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
	protected $paymentService;

	public function __construct(PaymentService $paymentService)
	{
		$this->paymentService = $paymentService;
	}

	public function show()
	{
		// Fetch network services for image lookup
		$networkServices = NetworkService::active()
			->whereNotNull('image_path')
			->get()
			->keyBy(fn ($service) => strtolower($service->name));

		// Get IDs of vendors that have reseller products
		$vendorsWithResellerProducts = ResellerProduct::where('is_active', true)
			->whereHas('product', fn ($q) => $q->where('is_active', true)->where('is_resellable', true))
			->pluck('reseller_vendor_id')
			->unique();

		$vendors = Vendor::query()
			->select(['id', 'name', 'email', 'phone_number'])
			->where('is_approved', true)
			->with(['products' => function ($query) {
				$query->where('is_active', true)
					->select(['id', 'vendor_id', 'name', 'description', 'price']);
			}])
			->where(function ($query) use ($vendorsWithResellerProducts) {
				// Include vendors with owned products OR reseller products
				$query->whereHas('products', fn ($q) => $q->where('is_active', true))
					->orWhereIn('id', $vendorsWithResellerProducts);
			})
			->get()
			->map(function (Vendor $vendor) use ($networkServices) {
				// Process owned products
				$ownedProducts = $vendor->products->map(function ($product) use ($networkServices) {
					$metadata = $this->decodeDescription($product->description);
					$product->structured_metadata = $metadata;
					$product->metadata = $metadata;
					$product->display_description = $metadata['notes']
						?? $metadata['description']
						?? $product->description;
					$product->is_reseller_product = false;
					
					// Add network image URL
					$networkName = $metadata['network'] ?? null;
					$networkService = $networkName ? $networkServices->get(strtolower($networkName)) : null;
					$product->network_image_url = $networkService ? $networkService->image_url : null;
					
					return $product;
				});

				// Get reseller products for this vendor
				$resellerProducts = ResellerProduct::where('reseller_vendor_id', $vendor->id)
					->where('is_active', true)
					->with(['product' => function ($query) {
						$query->where('is_active', true)->where('is_resellable', true);
					}])
					->get()
					->filter(fn($rp) => $rp->product !== null)
					->map(function ($resellerProduct) use ($networkServices) {
						$product = $resellerProduct->product;
						$metadata = $this->decodeDescription($product->description);
						
						$displayProduct = clone $product;
						$displayProduct->id = $resellerProduct->id; // Use reseller product ID
						$displayProduct->reseller_product_id = $resellerProduct->id;
						$displayProduct->original_product_id = $product->id;
						$displayProduct->name = $product->name;
						$displayProduct->price = $resellerProduct->selling_price;
						$displayProduct->is_reseller_product = true;
						$displayProduct->structured_metadata = $metadata;
						$displayProduct->metadata = $metadata;
						$displayProduct->display_description = $metadata['notes']
							?? $metadata['description']
							?? $product->description;
						
						// Add network image URL
						$networkName = $metadata['network'] ?? null;
						$networkService = $networkName ? $networkServices->get(strtolower($networkName)) : null;
						$displayProduct->network_image_url = $networkService ? $networkService->image_url : null;
						
						return $displayProduct;
					});

				// Combine both product types and set the relation properly for JSON serialization
				$vendor->setRelation('products', $ownedProducts->concat($resellerProducts));

				return $vendor;
			})
			// Filter out vendors with no products at all
			->filter(fn ($vendor) => $vendor->products->isNotEmpty());

		return view('checkout', compact('vendors'));
	}

    public function success($orderId)
    {
        $order = Order::findOrFail($orderId);
        return view('checkout.success', compact('order'));
    }	public function process(Request $request)
	{
		$order = Order::findOrFail($request->order_id);
		$recipientPhone = $request->recipient_phone;
		$amount = $request->amount;
		$transaction = $this->paymentService->processPayment($order, $recipientPhone, $amount);
		// Dispatch event for order completion
		event(new OrderCompleted($order));
		// ...additional logic (redirect, response, etc.)
		return response()->json(['transaction' => $transaction]);
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
