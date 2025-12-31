<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminOrderController extends Controller
{
	public function index()
	{
		$orders = Order::with(['vendor', 'service'])
			->latest()
			->paginate(20);

		return view('admin.orders', compact('orders'));
	}

	public function update(Request $request, Order $order)
	{
		$data = $request->validate([
			'status' => ['required', 'in:Pending,Processing,Completed,Cancelled,Failed'],
		]);

		$order->update([
			'status' => $data['status'],
		]);

		$transactionStatus = match ($data['status']) {
			'Completed' => 'completed',
			'Cancelled', 'Failed' => 'failed',
			default => 'pending',
		};

		Transaction::where('order_id', $order->id)->update([
			'payment_status' => $transactionStatus,
		]);

		return back()->with('success', 'Order status updated.');
	}

	/**
	 * Manually confirm payment for an order.
	 *
	 * This is intended for cases where the gateway/webhook could not be validated
	 * due to network/provider issues, but the admin has verified the payment.
	 *
	 * Safety:
	 * - Uses PaymentService::completeOrder() which is idempotent and concurrency-safe.
	 * - Does not rely on Transaction.payment_status alone.
	 */
	public function confirmPayment(Request $request, Order $order, PaymentService $paymentService)
	{
		try {
			$order->refresh();

			if (in_array($order->payment_status, ['paid', 'completed'], true)) {
				return back()->with('success', 'Order is already marked as paid.');
			}

			$didComplete = $paymentService->completeOrder($order);
			if (! $didComplete) {
				return back()->with('error', 'Unable to confirm payment for this order.');
			}

			return back()->with('success', 'Payment confirmed and order processed successfully.');
		} catch (\Throwable $e) {
			Log::error('Admin confirmPayment failed', [
				'order_id' => $order->id,
				'error' => $e->getMessage(),
			]);

			return back()->with('error', 'Confirm payment failed. Please try again or check logs.');
		}
	}
}
