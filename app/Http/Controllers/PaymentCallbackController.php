<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
	protected PaymentService $paymentService;

	public function __construct(PaymentService $paymentService)
	{
		$this->paymentService = $paymentService;
	}

	public function handle(Request $request)
	{
		$reference = $request->input('reference') ?? $request->input('trxref');
		if (! $reference) {
			return response()->json(['success' => false, 'message' => 'Missing payment reference'], 400);
		}

		$verification = $this->paymentService->checkPaymentStatus($reference);
		if (! ($verification['success'] ?? false)) {
			Log::warning('Paystack verification failed', ['reference' => $reference, 'response' => $verification]);
			return response()->json(['success' => false, 'message' => $verification['message'] ?? 'Verification failed'], 400);
		}

		$order = Order::where('payment_reference', $reference)->first();
		if (! $order) {
			return response()->json(['success' => false, 'message' => 'Order not found for reference'], 404);
		}

		// Update order amount if Paystack returned it (kobo/pesewas)
		$amount = data_get($verification, 'data.amount');
		if ($amount) {
			$order->amount_paid = $amount / 100;
		}

		// Complete order flows (wallet, notifications, transactions)
		$this->paymentService->completeOrder($order);

		return redirect()->route('checkout.success', ['order' => $order->id]);
	}
}
