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
			Log::warning('Payment verification failed', ['reference' => $reference, 'response' => $verification]);
			return redirect()->route('checkout.show')->withErrors(['payment' => $verification['message'] ?? 'Verification failed']);
		}

		$order = Order::where('payment_reference', $reference)->first();
		if (! $order) {
			return response()->json(['success' => false, 'message' => 'Order not found for reference'], 404);
		}

		$paymentStatus = strtolower((string) data_get($verification, 'data.status', ''));

		if ($paymentStatus === 'pending' || $paymentStatus === 'unknown') {
			return redirect()->route('checkout.show')
				->with('payment_pending', true)
				->with('payment_reference', $reference)
				->with('payment_message', 'Payment pending. Please wait for confirmation.');
		}

		if ($paymentStatus && $paymentStatus !== 'success') {
			$order->update([
				'payment_status' => 'failed',
				'status' => 'Failed',
			]);
			\App\Models\Transaction::where('order_id', $order->id)
				->whereNotIn('payment_status', ['completed', 'successful'])
				->update(['payment_status' => 'failed']);

			return redirect()->route('checkout.show')->withErrors(['payment' => 'Payment failed.']);
		}

		// Update order amount if gateway returned it.
		$amount = data_get($verification, 'data.amount');
		if ($amount) {
			$numericAmount = (float) $amount;
			if (($order->payment_gateway ?? null) === 'paystack') {
				$order->amount_paid = $numericAmount / 100;
			} else {
				$order->amount_paid = $numericAmount;
			}
		}

		// Complete order flows (wallet, notifications, transactions)
		$this->paymentService->completeOrder($order);

		return redirect()->route('checkout.success', ['order' => $order->id]);
	}
}
