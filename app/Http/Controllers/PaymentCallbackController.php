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

		// Find order FIRST to prevent orphaned verifications
		$order = Order::where('payment_reference', $reference)->first();
		if (! $order) {
			Log::warning('Payment callback for unknown order', ['reference' => $reference]);
			return response()->json(['success' => false, 'message' => 'Order not found for reference'], 404);
		}

		// Check if already completed (idempotency)
		if ($order->payment_status === 'completed') {
			Log::info('Payment already completed', ['order_id' => $order->id, 'reference' => $reference]);
			return redirect()->route('checkout.success', ['order' => $order->id]);
		}

		// VERIFY payment with gateway before completing
		$verification = $this->paymentService->verifyPayment($reference);
		if (! ($verification['success'] ?? false)) {
			Log::warning('Payment verification failed - deleting order', [
				'order_id' => $order->id,
				'reference' => $reference,
				'response' => $verification,
			]);

			// Delete failed order (unsuccessful payments not recorded)
			$this->paymentService->markTransactionFailed($order, 'Gateway verification failed');

			return response()->json([
				'success' => false,
				'message' => $verification['message'] ?? 'Payment verification failed',
			], 400);
		}

		// Update order amount if gateway returned it
		$amount = data_get($verification, 'data.amount');
		if ($amount) {
			// Convert from kobo/pesewas to main currency if needed
			$order->amount_paid = $amount / 100;
			$order->save();
		}

		// Complete order ONLY after successful verification
		$this->paymentService->completeOrder($order);

		Log::info('Payment verified and order completed', [
			'order_id' => $order->id,
			'reference' => $reference,
		]);

		return redirect()->route('checkout.success', ['order' => $order->id]);
	}
}
