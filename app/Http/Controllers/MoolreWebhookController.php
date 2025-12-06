<?php

namespace App\Http\Controllers;

use App\Models\VendorNotification;
use App\Models\VendorWithdrawal;
use App\Services\MoolrePayoutService;
use App\Services\PaymentService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MoolreWebhookController extends Controller
{
    protected PaymentService $paymentService;
    protected MoolrePayoutService $payoutService;

    public function __construct(PaymentService $paymentService, MoolrePayoutService $payoutService)
    {
        $this->paymentService = $paymentService;
        $this->payoutService = $payoutService;
    }

    /**
     * Handle Moolre payment webhook callbacks
     * This handles both collection (customer payments) and disbursement (vendor payouts) webhooks
     */
    public function handleWebhook(Request $request)
    {
        Log::info('Moolre Webhook Incoming', [
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        $payload = $request->all();

        // Validate webhook (you may want to add signature verification here)
        if (empty($payload)) {
            Log::warning('Moolre Webhook: Empty payload');
            return response()->json([
                'status' => 0,
                'message' => 'Empty payload',
            ], 400);
        }

        try {
            $reference = $payload['reference'] ?? '';

            // Check if this is a payout webhook (reference starts with XTRA4U-PAYOUT-)
            if (str_starts_with($reference, 'XTRA4U-PAYOUT-')) {
                return $this->handlePayoutWebhook($payload);
            }

            // Otherwise, it's a collection (payment) webhook
            return $this->handlePaymentWebhook($payload);

        } catch (\Exception $e) {
            Log::error('Moolre Webhook Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 0,
                'message' => 'Internal error processing webhook',
            ], 500);
        }
    }

    /**
     * Handle payment collection webhook (customer paid)
     */
    protected function handlePaymentWebhook(array $payload)
    {
        $result = $this->paymentService->handleWebhook($payload);

        if ($result['success']) {
            return response()->json([
                'status' => 1,
                'message' => $result['message'],
            ], 200);
        }

        return response()->json([
            'status' => 0,
            'message' => $result['message'],
        ], 400);
    }

    /**
     * Handle payout/disbursement webhook (vendor payout status update)
     */
    protected function handlePayoutWebhook(array $payload)
    {
        $result = $this->payoutService->processPayoutWebhook($payload);

        if (!$result['success']) {
            return response()->json([
                'status' => 0,
                'message' => $result['message'],
            ], 400);
        }

        $withdrawal = VendorWithdrawal::find($result['withdrawal_id']);

        if (!$withdrawal) {
            return response()->json([
                'status' => 0,
                'message' => 'Withdrawal not found',
            ], 404);
        }

        $status = strtolower($result['status'] ?? '');

        // Update payout status based on webhook
        if (in_array($status, ['success', 'successful', 'completed', 'paid'])) {
            $withdrawal->update([
                'payout_status' => 'completed',
                'paid_at' => $withdrawal->paid_at ?? now(),
            ]);

            Log::info('Payout webhook: Payout completed', [
                'withdrawal_id' => $withdrawal->id,
                'reference' => $result['reference'],
            ]);

            // Notify vendor that payout completed successfully
            $this->notifyVendorPayoutCompleted($withdrawal);

        } elseif (in_array($status, ['failed', 'cancelled', 'rejected', 'declined'])) {
            // Payout failed - update status and notify
            $withdrawal->update([
                'payout_status' => 'failed',
                'status' => VendorWithdrawal::STATUS_REJECTED,
                'notes' => ($withdrawal->notes ? $withdrawal->notes . ' | ' : '') . 'Payout failed: ' . ($payload['message'] ?? 'Unknown error'),
            ]);

            // Restore vendor's ability to request withdrawal again
            Log::error('Payout webhook: Payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'reference' => $result['reference'],
                'status' => $status,
            ]);

            // Notify vendor about failed payout
            $this->notifyVendorPayoutFailed($withdrawal, $payload['message'] ?? 'Payout could not be completed');

        } else {
            // Pending or other status
            $withdrawal->update([
                'payout_status' => $status,
            ]);

            Log::info('Payout webhook: Status update', [
                'withdrawal_id' => $withdrawal->id,
                'reference' => $result['reference'],
                'status' => $status,
            ]);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Payout webhook processed',
        ], 200);
    }

    /**
     * Notify vendor that payout completed successfully
     */
    protected function notifyVendorPayoutCompleted(VendorWithdrawal $withdrawal): void
    {
        VendorNotification::create([
            'vendor_id' => $withdrawal->vendor_id,
            'type' => 'payout_completed',
            'title' => 'Payout Completed',
            'message' => 'Your payout of GHS ' . number_format($withdrawal->amount, 2) . ' has been successfully delivered to ' . $withdrawal->momo_number . '.',
            'data' => [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'momo_number' => $withdrawal->momo_number,
                'reference' => $withdrawal->payout_reference,
            ],
        ]);
    }

    /**
     * Notify vendor that payout failed
     */
    protected function notifyVendorPayoutFailed(VendorWithdrawal $withdrawal, string $reason): void
    {
        VendorNotification::create([
            'vendor_id' => $withdrawal->vendor_id,
            'type' => 'payout_failed',
            'title' => 'Payout Failed',
            'message' => 'Your payout of GHS ' . number_format($withdrawal->amount, 2) . ' to ' . $withdrawal->momo_number . ' failed. Reason: ' . $reason . '. Please contact support.',
            'data' => [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'momo_number' => $withdrawal->momo_number,
                'reason' => $reason,
            ],
        ]);

        // Also send SMS for failed payouts
        try {
            $smsService = app(SmsService::class);
            $vendor = $withdrawal->vendor;
            
            if ($vendor && $vendor->phone_number) {
                $message = "XTRA4U: Your payout of GHS " . number_format($withdrawal->amount, 2) . " failed. Please contact support. Ref: {$withdrawal->reference}";
                $smsService->send($vendor->phone_number, $message);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send payout failure SMS', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle payment callback redirect (for user-facing redirects)
     */
    public function handleCallback(Request $request)
    {
        $reference = $request->get('reference');
        $status = $request->get('status');

        Log::info('Moolre Callback Redirect', [
            'reference' => $reference,
            'status' => $status,
            'all' => $request->all(),
        ]);

        if ($status === 'success' || $status === 'successful' || $status === 'completed') {
            return redirect()->route('checkout.success', ['reference' => $reference])
                ->with('success', 'Payment completed successfully!');
        }

        return redirect()->route('checkout')
            ->with('error', 'Payment was not completed. Please try again.');
    }
