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
		$reference = $request->input('reference')
			?? $request->input('trxref')
			?? $request->input('externalref')
			?? $request->input('externalRef');
		if (! $reference) {
			return response()->json(['success' => false, 'message' => 'Missing payment reference'], 400);
		}

		$order = Order::where('payment_reference', $reference)->first();
		if (! $order) {
			return response()->json(['success' => false, 'message' => 'Order not found for reference'], 404);
		}

		$verification = $this->paymentService->checkPaymentStatus($reference);
		if (! ($verification['success'] ?? false)) {
			Log::warning('Payment verification failed', ['reference' => $reference, 'response' => $verification]);
			return $this->redirectBackToStoreOrCheckout($order, $verification['message'] ?? 'Verification failed', true);
		}

		$paymentStatus = strtolower((string) data_get($verification, 'data.status', ''));

		if ($paymentStatus === 'pending' || $paymentStatus === 'unknown' || $paymentStatus === '') {
			return $this->redirectBackToStoreOrCheckout($order, 'Payment pending. Please wait for confirmation.', false);
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

			return $this->redirectBackToStoreOrCheckout($order, 'Payment failed.', true);
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

	private function redirectBackToStoreOrCheckout(Order $order, string $message, bool $isError)
	{
		try {
			$order->loadMissing('vendor');
		} catch (\Throwable $e) {
			// Best-effort only; fall back to checkout.
		}

		$vendorCode = $order->vendor?->vendor_code;
		if (is_string($vendorCode) && $vendorCode !== '') {
			if ($isError) {
				return redirect()->route('storefront.vendor', $vendorCode)
					->with('payment_failed', true)
					->with('payment_message', $message);
			}
			return redirect()->route('storefront.vendor', $vendorCode)->with('success', $message);
		}

		if ($isError) {
			return redirect()->route('checkout.show')
				->with('payment_failed', true)
				->with('payment_message', $message);
		}

		return redirect()->route('checkout.show')
			->with('payment_pending', true)
			->with('payment_reference', $order->payment_reference)
			->with('payment_message', $message);
	}
}
