<?php

namespace App\Http\Controllers;

use App\Models\Order;

class AdminOrderController extends Controller
{
	public function index()
	{
		$orders = Order::with('vendor')
			->latest()
			->paginate(20);

		return view('admin.orders', compact('orders'));
	}
}
