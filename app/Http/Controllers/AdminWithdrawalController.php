<?php

namespace App\Http\Controllers;

use App\Models\VendorWithdrawal;
use App\Models\VendorNotification;
use App\Services\MomoPayoutService;
use App\Services\SmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $withdrawal->refresh();

        if (! in_array($withdrawal->status, [VendorWithdrawal::STATUS_PENDING, VendorWithdrawal::STATUS_PROCESSING], true)) {
            return back()->withErrors([
                'withdrawal' => 'This withdrawal can no longer be updated. Please refresh the page.'
            ]);
        }

        // Only allow automatic payout when a service is available; otherwise, fallback to manual approval
        $gatewayManager = app(\App\Services\GatewayManager::class);
        $payoutService = $gatewayManager->getPayoutService();
        if (!$payoutService && app()->bound(MomoPayoutService::class)) {
            $payoutService = app(MomoPayoutService::class);
        }
        $useAutomaticPayout = (bool) $payoutService;

        if ($useAutomaticPayout) {
            // Some services may require vendor info for payout
            $vendor = $withdrawal->vendor;
            if (method_exists($payoutService, 'processPayout')) {
                $result = $payoutService->processPayout($withdrawal, $vendor);
            } elseif (method_exists($payoutService, 'createPayout')) {
                $result = $payoutService->createPayout($withdrawal, $vendor);
            } else {
                return back()->withErrors([
                    'withdrawal' => 'Payout service does not support payouts.',
                ]);
            }

            if (!$result['success']) {
                return back()->withErrors([
                    'withdrawal' => $result['message'],
                ]);
            }

            // Update withdrawal with payout details
            $withdrawal->update([
                'status' => VendorWithdrawal::STATUS_APPROVED,
                'payout_reference' => $result['reference'] ?? null,
                'payout_transaction_id' => $result['transaction_id'] ?? null,
                'payout_status' => $result['status'] ?? 'pending',
                'paid_at' => now(),
            ]);

            Log::info('Withdrawal approved via automatic payout', [
                'withdrawal_id' => $withdrawal->id,
                'vendor_id' => $withdrawal->vendor_id,
                'amount' => $withdrawal->amount,
                'reference' => $result['reference'] ?? null,
            ]);

            $successMessage = 'Withdrawal approved! Payout of GHS ' . number_format($withdrawal->amount, 2) . ' sent to ' . $withdrawal->momo_number . '.';
        } else {
            $withdrawal->update([
                'status' => VendorWithdrawal::STATUS_APPROVED,
                'payout_status' => 'manual',
                'paid_at' => now(),
            ]);

            Log::info('Withdrawal approved manually (no payout gateway configured)', [
                'withdrawal_id' => $withdrawal->id,
                'vendor_id' => $withdrawal->vendor_id,
                'amount' => $withdrawal->amount,
            ]);

            $successMessage = 'Withdrawal approved. Please process the payout manually.';
        }

        // Notify vendor about successful payout
        $this->notifyVendorPayoutApproved($withdrawal);

        return back()->with('status', $successMessage);
    }

    /**
     * Notify vendor about approved payout
     */
    protected function notifyVendorPayoutApproved(VendorWithdrawal $withdrawal): void
    {
        // In-app notification
        VendorNotification::create([
            'vendor_id' => $withdrawal->vendor_id,
            'type' => VendorNotification::TYPE_WITHDRAWAL_APPROVED ?? 'withdrawal_approved',
            'title' => 'Withdrawal Approved',
            'message' => 'Your withdrawal of GHS ' . number_format($withdrawal->amount, 2) . ' has been approved and sent to ' . $withdrawal->momo_number . '.',
            'data' => [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'momo_number' => $withdrawal->momo_number,
                'reference' => $withdrawal->payout_reference,
            ],
        ]);

        // SMS notification
        try {
            $smsService = app(SmsService::class);
            $vendor = $withdrawal->vendor;
            
            if ($vendor && $vendor->phone_number) {
                $message = "XTRA4U: Your withdrawal of GHS " . number_format($withdrawal->amount, 2) . " has been approved and sent to {$withdrawal->momo_number}. Ref: {$withdrawal->reference}";
                $smsService->send($vendor->phone_number, $message);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send payout SMS notification', ['error' => $e->getMessage()]);
        }
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
