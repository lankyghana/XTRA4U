<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVendorWithdrawalPayout;
use App\Models\VendorWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminWithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $statusFilter = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $query = VendorWithdrawal::with('vendor')->latest();

        if ($statusFilter && in_array($statusFilter, VendorWithdrawal::statuses(), true)) {
            $query->where('status', $statusFilter);
        }

        if ($search !== '') {
            $searchNumeric = ctype_digit($search) ? (int) $search : null;
            $query->where(function ($q) use ($search, $searchNumeric) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('payout_reference', 'like', "%{$search}%")
                    ->orWhere('payout_transaction_id', 'like', "%{$search}%")
                    ->orWhere('momo_number', 'like', "%{$search}%")
                    ->orWhere('momo_account_name', 'like', "%{$search}%")
                    ->orWhereHas('vendor', function ($vq) use ($search, $searchNumeric) {
                        $vq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");

                        if (! is_null($searchNumeric)) {
                            $vq->orWhere('id', $searchNumeric);
                        }
                    });
            });
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
            'search' => $search,
            'summary' => $summary,
        ]);
    }

    public function refresh(Request $request, VendorWithdrawal $withdrawal): RedirectResponse
    {
        if ($withdrawal->status !== VendorWithdrawal::STATUS_PROCESSING) {
            return back()->withErrors([
                'withdrawal' => 'Only withdrawals in "processing" can be refreshed.',
            ]);
        }

        try {
            ProcessVendorWithdrawalPayout::dispatchSync($withdrawal->id);
        } catch (\Throwable $e) {
            Log::warning('Admin manual refresh payout status failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'withdrawal' => 'Unable to refresh this withdrawal right now. Please try again.',
            ]);
        }

        $withdrawal->refresh();

        if ($withdrawal->status === VendorWithdrawal::STATUS_APPROVED) {
            return back()->with('status', 'Withdrawal refreshed: marked as approved.');
        }

        if ($withdrawal->status === VendorWithdrawal::STATUS_FAILED) {
            return back()->with('status', 'Withdrawal refreshed: marked as failed' . ($withdrawal->error_message ? ' (' . $withdrawal->error_message . ')' : '.') );
        }

        return back()->with('status', 'Withdrawal refreshed: still processing (provider status pending).');
    }
}
