<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVendorWithdrawalPayout;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use App\Models\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

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
            'cancelled' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_CANCELLED)->count(),
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

    public function cancel(Request $request, VendorWithdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($withdrawal->status !== VendorWithdrawal::STATUS_PROCESSING) {
            return back()->withErrors([
                'withdrawal' => 'Only withdrawals in "processing" can be cancelled.',
            ]);
        }

        if ($withdrawal->paid_at || $withdrawal->status === VendorWithdrawal::STATUS_APPROVED) {
            return back()->withErrors([
                'withdrawal' => 'Paid/approved withdrawals cannot be cancelled.',
            ]);
        }

        DB::transaction(function () use ($withdrawal, $data, $request) {
            $lockedWithdrawal = VendorWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($lockedWithdrawal->status !== VendorWithdrawal::STATUS_PROCESSING) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'Only withdrawals in "processing" can be cancelled.',
                ]);
            }

            $vendor = Vendor::whereKey($lockedWithdrawal->vendor_id)->lockForUpdate()->firstOrFail();

            $amount = (float) $lockedWithdrawal->amount;

            $vendor->increment('wallet_balance', $amount);
            $vendor->refresh();

            WalletLedger::create([
                'vendor_id' => $vendor->id,
                'type' => 'credit',
                'source' => 'withdrawal_reversal',
                'amount' => $amount,
                'balance_after' => (float) $vendor->wallet_balance,
                'metadata' => [
                    'withdrawal_id' => $lockedWithdrawal->id,
                    'admin_id' => auth()->id(),
                    'ip_address' => $request->ip(),
                    'reason' => $data['note'],
                    'action' => 'withdrawal_cancelled',
                ],
            ]);

            $lockedWithdrawal->update([
                'status' => VendorWithdrawal::STATUS_CANCELLED,
                'notes' => $data['note'],
                'error_message' => null,
                'payout_status' => 'cancelled',
                'refunded_at' => now(),
            ]);
        });

        Log::info('Admin cancelled vendor withdrawal', [
            'admin_id' => auth()->id(),
            'admin_ip' => $request->ip(),
            'withdrawal_id' => $withdrawal->id,
            'vendor_id' => $withdrawal->vendor_id,
            'amount' => $withdrawal->amount,
            'note' => $data['note'],
        ]);

        return back()->with('status', 'Withdrawal cancelled successfully.');
    }
}
