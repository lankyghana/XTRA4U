<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\WalletTopup;
use App\Services\AfaPaymentService;
use App\Services\PaymentService;
use App\Services\PaystackPaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentService $paymentService,
        AfaPaymentService $afaPaymentService,
        WalletService $walletService
    ) {
        // 1. Retrieve the Paystack signature and the raw payload
        $signature = $request->header('x-paystack-signature');
        $payload = $request->getContent();

        // 2. Validate the signature using the Paystack secret key
        $paystackService = new PaystackPaymentService();
        $secretKey = $paystackService->getSecretKey();

        if (empty($secretKey)) {
            Log::warning('Paystack webhook: Missing secret key configuration');
            return response()->json(['success' => true, 'message' => 'OK']);
        }

        if (!$signature || $signature !== hash_hmac('sha512', $payload, $secretKey)) {
            Log::warning('Paystack webhook: Invalid signature');
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        // 3. Parse the payload
        $eventData = json_decode($payload, true);

        // We only care about successful charges
        if (($eventData['event'] ?? '') !== 'charge.success') {
            return response()->json(['success' => true, 'message' => 'Event ignored']);
        }

        $reference = $eventData['data']['reference'] ?? null;
        if (!$reference) {
            return response()->json(['success' => false, 'message' => 'Missing reference'], 400);
        }

        // 4. Verify payment via Paystack API (source of truth)
        $verification = $paystackService->verifyPayment($reference);

        if (!($verification['success'] ?? false)) {
            Log::warning('Paystack webhook: Verification failed', [
                'reference' => $reference,
                'message' => $verification['message'] ?? null,
            ]);
            return response()->json(['success' => true, 'message' => 'Verification failed']);
        }

        // Cache the successful verification so the redirect callback can serve it
        // instantly without another round-trip to Paystack. The webhook fires before
        // Paystack redirects the user, so this cache entry will typically be ready
        // by the time PaymentCallbackController::handle() runs.
        Cache::put('paystack_verify:' . $reference, $verification, now()->addMinutes(15));

        $status = strtolower((string) data_get($verification, 'data.status', 'unknown'));
        if (!in_array($status, ['success', 'successful', 'paid', 'completed'], true)) {
            // Not a successful payment according to the API
            return response()->json(['success' => true, 'message' => 'Not successful']);
        }

        // The verifyPayment() method already normalizes the amount to major units (GHS)
        $verifiedAmount = (float) data_get($verification, 'data.amount', 0);
        // 5. Route the payment to the correct fulfillment logic
        
        // Check Orders
        $order = Order::where('payment_reference', $reference)->first();
        if ($order) {
            if (in_array($order->payment_status, ['paid', 'completed'], true)) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }

            if ($verifiedAmount > 0 && ((float) $order->amount_paid) <= 0) {
                $order->amount_paid = $verifiedAmount;
                $order->save();
            }

            $paymentService->completeOrder($order);
            return response()->json(['success' => true, 'message' => 'Order processed']);
        }

        // Check AFA Registrations
        $registration = AfaRegistration::query()
            ->where('payment_reference', $reference)
            ->orWhere('reference', $reference)
            ->first();

        if ($registration) {
            if ($registration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }

            $afaPaymentService->completeRegistration($registration);
            return response()->json(['success' => true, 'message' => 'AFA Registration processed']);
        }

        // Check Wallet Top-ups
        // Note: Paystack webhooks might arrive before the frontend polls if the user closes the window.
        $topupRecord = WalletTopup::where('reference', $reference)->first();
        
        if (!$topupRecord) {
            $cached = Cache::get("wallet_topup:{$reference}");
            $meta = $verification['data']['metadata'] ?? [];

            if ($cached && is_array($cached) && !empty($cached['vendor_id'])) {
                $topupRecord = WalletTopup::create([
                    'reference' => $reference,
                    'vendor_id' => $cached['vendor_id'],
                    'amount' => $cached['amount'],
                    'status' => 'initiated',
                    'metadata' => ['purpose' => 'wallet_topup'],
                ]);
            } elseif (is_array($meta) && !empty($meta['vendor_id']) && ($meta['purpose'] ?? '') === 'wallet_topup') {
                $topupRecord = WalletTopup::create([
                    'reference' => $reference,
                    'vendor_id' => (int) $meta['vendor_id'],
                    'amount' => $verifiedAmount,
                    'status' => 'initiated',
                    'metadata' => $meta,
                ]);
            }
        }

        if ($topupRecord) {
            if ($topupRecord->status === 'completed') {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }

            $vendorId = (int) $topupRecord->vendor_id;
            $initiatedAmount = (float) $topupRecord->amount;

            // Ensure the amount paid on the gateway is at least the initiated amount (accounting for fees)
            // Always credit the wallet with the originally requested amount.
            if ($vendorId > 0 && $initiatedAmount > 0 && round($verifiedAmount, 2) >= round($initiatedAmount, 2)) {
                $credited = false;
                DB::transaction(function () use ($vendorId, $initiatedAmount, $reference, &$credited, $verification, $walletService) {
                    $credited = $walletService->creditVendor($vendorId, $initiatedAmount, ['reference' => $reference]);
                    if ($credited) {
                        WalletTopup::where('reference', $reference)->update([
                            'status' => 'completed',
                            'gateway_response' => $verification,
                        ]);
                        Cache::forget("wallet_topup:{$reference}");
                    }
                });

                if ($credited) {
                    Cache::forget("vendor:{$vendorId}:topups_available");
                    return response()->json(['success' => true, 'message' => 'Wallet Top-up processed']);
                }
            }
        }

        Log::warning('Paystack webhook: Unmatched reference', ['reference' => $reference]);
        return response()->json(['success' => true, 'message' => 'OK']);
    }
}
