<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminTransactionController extends Controller
{
	public function index(): View
	{
		$transactions = Transaction::with(['order', 'vendor'])
			->latest()
			->paginate(20);

		$totals = [
			'processed' => Transaction::count(),
			'today' => Transaction::whereDate('created_at', now()->toDateString())->count(),
			'revenue' => Transaction::sum('amount'),
		];

		return view('admin.transactions', compact('transactions', 'totals'));
	}

	public function show(Transaction $transaction): View
	{
		$transaction->load(['order', 'vendor']);

		return view('admin.transaction_show', compact('transaction'));
	}

	public function update(Request $request, Transaction $transaction)
	{
		$data = $request->validate([
			'payment_status' => ['required', 'in:pending,successful,completed,failed'],
		]);

		$transaction->update([
			'payment_status' => $data['payment_status'],
		]);

		return back()->with('success', 'Transaction status updated.');
	}

	/**
	 * Confirm the payment behind a transaction.
	 *
	 * This is for cases where the transaction exists but the payment gateway/webhook
	 * could not be validated automatically. We complete the related order using the
	 * same idempotent logic as normal payments.
	 */
	public function confirmPayment(Request $request, Transaction $transaction, PaymentService $paymentService)
	{
		try {
			$transaction->load('order');
			$order = $transaction->order;

			if (! $order) {
				return back()->with('error', 'This transaction is not linked to an order.');
			}

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
			Log::error('Admin confirmPayment (transaction) failed', [
				'transaction_id' => $transaction->id,
				'order_id' => $transaction->order_id,
				'error' => $e->getMessage(),
			]);

			return back()->with('error', 'Confirm payment failed. Please try again or check logs.');
		}
	}
}
