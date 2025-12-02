<?php

namespace App\Http\Controllers;

use App\Models\VendorWithdrawal;
use Illuminate\Http\RedirectResponse;
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
            'pending' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PENDING)->count(),
            'processing' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PROCESSING)->count(),
            'approved' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_APPROVED)->count(),
            'rejected' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_REJECTED)->count(),
            'pending_amount' => VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PENDING)->sum('amount'),
            'total_amount' => VendorWithdrawal::sum('amount'),
        ];

        return view('admin.withdrawals', [
            'withdrawals' => $withdrawals,
            'statusFilter' => $statusFilter,
            'summary' => $summary,
        ]);
    }

    public function markProcessing(Request $request, VendorWithdrawal $withdrawal): RedirectResponse
    {
        return $this->updateStatus(
            $request,
            $withdrawal,
            VendorWithdrawal::STATUS_PROCESSING,
            [VendorWithdrawal::STATUS_PENDING],
            'Withdrawal marked as processing.'
        );
    }

    public function approve(Request $request, VendorWithdrawal $withdrawal): RedirectResponse
    {
        return $this->updateStatus(
            $request,
            $withdrawal,
            VendorWithdrawal::STATUS_APPROVED,
            [VendorWithdrawal::STATUS_PENDING, VendorWithdrawal::STATUS_PROCESSING],
            'Withdrawal approved and queued for payout.'
        );
    }

    public function reject(Request $request, VendorWithdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:255'],
        ], [
            'notes.required' => 'Please provide a short reason for rejecting the withdrawal.',
        ]);

        return $this->updateStatus(
            $request,
            $withdrawal,
            VendorWithdrawal::STATUS_REJECTED,
            [VendorWithdrawal::STATUS_PENDING, VendorWithdrawal::STATUS_PROCESSING],
            'Withdrawal rejected.',
            $data['notes']
        );
    }

    private function updateStatus(
        Request $request,
        VendorWithdrawal $withdrawal,
        string $targetStatus,
        array $allowedCurrentStatuses,
        string $successMessage,
        ?string $notesOverride = null
    ): RedirectResponse {
        $withdrawal->refresh();

        if (! in_array($withdrawal->status, $allowedCurrentStatuses, true)) {
            return back()->withErrors([
                'withdrawal' => 'This withdrawal can no longer be updated. Please refresh the page.',
            ]);
        }

        $payload = [
            'status' => $targetStatus,
        ];

        $incomingNotes = $notesOverride ?? $request->input('notes');
        if ($incomingNotes) {
            $payload['notes'] = $incomingNotes;
        }

        $withdrawal->update($payload);

        return back()->with('status', $successMessage);
    }
}
