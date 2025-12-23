<?php

namespace App\Http\Controllers;

use App\Models\VendorWithdrawal;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminWithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $statusFilter = $request->query('status');
        $query = VendorWithdrawal::with('vendor')->latest();

        if ($statusFilter && in_array($statusFilter, VendorWithdrawal::statuses(), true)) {
            $query->where('status', $statusFilter);
        }

        $withdrawals = $query->paginate(15)->withQueryString();

        $summary = [
            'processing' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PROCESSING)->count(),
            'approved' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_APPROVED)->count(),
            'failed' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_FAILED)->count(),
            'processing_amount' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PROCESSING)->sum('amount'),
            'total_amount' => VendorWithdrawal::sum('amount'),
        ];

        return view('admin.withdrawals', [
            'withdrawals' => $withdrawals,
            'statusFilter' => $statusFilter,
            'summary' => $summary,
        ]);
    }
}
