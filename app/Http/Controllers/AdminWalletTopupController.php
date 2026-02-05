<?php

namespace App\Http\Controllers;

use App\Models\WalletTopup;
use Illuminate\Http\Request;

class AdminWalletTopupController extends Controller
{
    public function index(Request $request)
    {
        $q = WalletTopup::with('vendor')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        if ($request->filled('gateway')) {
            $q->where('gateway', $request->input('gateway'));
        }

        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->input('to'));
        }

        $topups = $q->paginate(25)->withQueryString();

        return view('admin.wallet_topups.index', compact('topups'));
    }
}
