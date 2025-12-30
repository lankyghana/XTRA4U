<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
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
}
