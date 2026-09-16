<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\WalletTopup;
use App\Services\AfaPaymentService;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Services\PaystackPaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $paystackService = new PaystackPaymentService;
        $secretKey = $paystackService->getSecretKey();

        if (empty($secretKey)) {
            Log::warning('Paystack webhook: Missing secret key configuration');

            return response()->json(['success' => true, 'message' => 'OK']);
        }

        if (! $signature || $signature !== hash_hmac('sha512', $payload, $secretKey)) {
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
        if (! $reference) {
            return response()->json(['success' => false, 'message' => 'Missing reference'], 400);
        }

        // 4. Verify payment via Paystack API (source of truth)
        $verification = $paystackService->verifyPayment($reference);

        if (! ($verification['success'] ?? false)) {
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
        Cache::put('paystack_verify:'.$reference, $verification, now()->addMinutes(15));

        $status = strtolower((string) data_get($verification, 'data.status', 'unknown'));
        if (! in_array($status, ['success', 'successful', 'paid', 'completed'], true)) {
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

            // Central payment integrity invariant. Before this, THIS webhook
            // performed no amount comparison at all — a verified Paystack
            // transaction completed the order regardless of how much was
            // actually collected against its frozen expected amount.
            $integrity = app(PaymentIntegrityGuard::class)->guard(
                $order,
                $verification,
                PaymentGatewayConfig::GATEWAY_PAYSTACK
            );

            if (! $integrity->passed) {
                // Recorded on the order and logged. 200 so Paystack stops
                // retrying a webhook that will never be accepted.
                return response()->json(['success' => true, 'message' => 'OK']);
            }

            $order->amount_paid = $integrity->confirmedAmount;
            $order->save();

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

        if (! $topupRecord) {
            $cached = Cache::get("wallet_topup:{$reference}");
            $meta = $verification['data']['metadata'] ?? [];

            // This webhook is Paystack-specific (its HMAC signature already
            // verified above), so any record it creates unambiguously
            // originated from Paystack — safe to stamp payment_gateway here.
            try {
                if ($cached && is_array($cached) && ! empty($cached['vendor_id'])) {
                    $topupRecord = WalletTopup::create([
                        'reference' => $reference,
                        'vendor_id' => $cached['vendor_id'],
                        'amount' => $cached['amount'],
                        'status' => 'initiated',
                        'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
                        'metadata' => ['purpose' => 'wallet_topup'],
                    ]);
                } elseif (is_array($meta) && ! empty($meta['vendor_id']) && ($meta['purpose'] ?? '') === 'wallet_topup') {
                    $topupRecord = WalletTopup::create([
                        'reference' => $reference,
                        'vendor_id' => (int) $meta['vendor_id'],
                        'amount' => $verifiedAmount,
                        'status' => 'initiated',
                        'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
                        'metadata' => $meta,
                    ]);
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Lost a race against another delivery of the same webhook
                // event (Paystack retries, or a duplicate network delivery)
                // that reconstructed this row first — `reference` is unique.
                // Reuse the winning row instead of a 500.
                $topupRecord = WalletTopup::where('reference', $reference)->first();
                if (! $topupRecord) {
                    throw $e;
                }
            }
        } elseif (! $topupRecord->payment_gateway) {
            // Backfill: a still-unresolved legacy row that this cryptographically-
            // verified Paystack webhook just confirmed really was a Paystack
            // payment. This is the one trustworthy source the reconciliation
            // audit allows for — a live, signature-verified confirmation, never
            // a guess — so it's safe to set going forward.
            $topupRecord->update(['payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK]);
        }

        if ($topupRecord) {
            // Single authoritative completion pipeline — shared with the
            // browser callback, the status-polling endpoint, and the
            // automatic PaymentReconciliationService. It locks the row and
            // re-checks its status before crediting, so this webhook racing
            // any of those other callers for the same reference can only
            // ever credit once. (Phase 5 fix: this previously credited
            // inline without locking the WalletTopup row first — a webhook
            // racing the poll endpoint or reconciler for the same reference
            // could double-credit the vendor's wallet.)
            $credited = $walletService->completeTopup($topupRecord, $verification);

            if ($credited) {
                Cache::forget("vendor:{$topupRecord->vendor_id}:topups_available");

                return response()->json(['success' => true, 'message' => 'Wallet Top-up processed']);
            }
        }

        Log::warning('Paystack webhook: Unmatched reference', ['reference' => $reference]);

        return response()->json(['success' => true, 'message' => 'OK']);
    }
}
