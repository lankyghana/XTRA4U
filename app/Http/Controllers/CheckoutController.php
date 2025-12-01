<?php
namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Vendor;
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
		$vendors = Vendor::query()
			->select(['id', 'name', 'email', 'phone_number'])
			->where('is_approved', true)
			->with(['products' => function ($query) {
				$query->where('is_active', true)
					->select(['id', 'vendor_id', 'name', 'description', 'price']);
			}])
			->whereHas('products', fn ($query) => $query->where('is_active', true))
			->get();

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
}
