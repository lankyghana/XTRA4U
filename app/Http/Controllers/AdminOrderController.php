<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;

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
}
