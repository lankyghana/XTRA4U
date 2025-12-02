<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
}
