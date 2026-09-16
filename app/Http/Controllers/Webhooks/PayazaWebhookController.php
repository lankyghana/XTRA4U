<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Transaction;
use App\Services\AfaPaymentService;
use App\Services\PayazaPaymentService;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayazaWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $paymentService, AfaPaymentService $afaPaymentService)
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('x-payaza-signature', '');

        $payload = $request->all();
        $reference = (string) (
            $payload['transaction_reference']
                ?? $payload['reference']
                ?? data_get($payload, 'data.transaction_reference')
                ?? ''
        );

        if ($reference === '') {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 400);
        }

        $config = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_PAYAZA)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            Log::warning('Payaza webhook: no active payment collection config', ['reference' => $reference]);

            return response()->json(['success' => true, 'message' => 'OK']);
        }

        $expectedSecret = trim((string) $config->getConfig('secret_key', ''));

        // If a secret key is configured, require a valid HMAC-SHA512 signature.
        // Reject mismatches outright to avoid processing forged webhooks.
        if ($expectedSecret !== '') {
            $computed = base64_encode(hash_hmac('sha512', $rawBody, $expectedSecret, true));

            if ($signature === '' || ! hash_equals($computed, $signature)) {
                Log::warning('Payaza webhook signature mismatch - rejecting', ['reference' => $reference]);

                return response()->json(['success' => false, 'message' => 'Invalid webhook signature'], 403);
            }
        } else {
            // No secret configured: fall back to the status-query API below as the
            // sole source of truth (same defensive posture as MoolreWebhookController
            // when no webhook_secret is set).
            Log::info('Payaza webhook received without a configured secret_key; relying on status-query verification only', [
                'reference' => $reference,
            ]);
        }

        $order = Order::where('payment_reference', $reference)->first();
        $registration = null;
        $resultCheckerOrder = null;

        if (! $order) {
            $registration = AfaRegistration::query()
                ->where('payment_reference', $reference)
                ->orWhere('reference', $reference)
                ->first();

            if (! $registration) {
                $resultCheckerOrder = \App\Models\ResultCheckerOrder::query()
                    ->where('payment_reference', $reference)
                    ->first();

                if (! $resultCheckerOrder) {
                    Log::warning('Payaza webhook: order/afa registration/result checker not found', ['reference' => $reference]);

                    // Return 200 to avoid endless retries for a reference that's simply not ours (or not yet persisted).
                    return response()->json(['success' => true, 'message' => 'OK']);
                }
            }

            // Idempotency: ignore already-completed AFA registrations.
            if ($registration && $registration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }

            // Idempotency: ignore already-completed Result Checker orders.
            if ($resultCheckerOrder && in_array($resultCheckerOrder->status, ['completed', 'failed'], true)) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }
        } else {
            // Idempotency: ignore already-completed orders.
            if (in_array($order->payment_status, ['paid', 'completed'], true)) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }
        }

        // Webhook is treated as a trigger; the source of truth is Payaza's status-query API.
        $verification = (new PayazaPaymentService($config))->verifyPayment($reference);

        if (! (bool) ($verification['success'] ?? false)) {
            Log::warning('Payaza webhook: status verification failed', [
                'reference' => $reference,
                'message' => $verification['message'] ?? null,
            ]);

            // Return 200 to prevent endless retries.
            return response()->json(['success' => true, 'message' => 'OK']);
        }

        $status = (string) data_get($verification, 'data.status', 'unknown');
        $verifiedAmount = (float) data_get($verification, 'data.amount', 0);

        if ($status === 'success') {
            if ($order) {
                // Central payment integrity invariant. This handler already had
                // a lower-than-expected guard; it is replaced by the shared one
                // so that Payaza is held to the identical rule as every other
                // surface — EXACT amount, currency, reference, gateway and
                // single-use transaction, compared in integer minor units.
                // This matters most for Payaza: its Web Checkout SDK sets the
                // charge amount client-side, with no server-locked amount.
                $integrity = app(PaymentIntegrityGuard::class)->guard(
                    $order,
                    $verification,
                    PaymentGatewayConfig::GATEWAY_PAYAZA
                );

                if (! $integrity->passed) {
                    return response()->json(['success' => true, 'message' => 'OK']);
                }

                $order->amount_paid = $integrity->confirmedAmount;
                $order->save();

                $paymentService->completeOrder($order);

                return response()->json(['success' => true, 'message' => 'Processed']);
            }

            if ($registration) {
                // Amount mismatch guard — same protection as the Order branch above.
                // Payaza's Web Checkout SDK sets the charge amount client-side (there
                // is no server-to-server "initialize" call locking it in), so this
                // check must run on every completion path Payaza can reach, not just
                // the Order one.
                if ($verifiedAmount > 0 && round($verifiedAmount, 2) < round((float) $registration->amount, 2)) {
                    Log::error('Payaza webhook: verified amount is less than expected AFA registration amount - refusing to fulfil', [
                        'registration_id' => $registration->id,
                        'reference' => $reference,
                        'expected_amount' => $registration->amount,
                        'verified_amount' => $verifiedAmount,
                    ]);

                    return response()->json(['success' => true, 'message' => 'Amount mismatch']);
                }

                $afaPaymentService->completeRegistration($registration);

                return response()->json(['success' => true, 'message' => 'Processed']);
            }

            if ($resultCheckerOrder) {
                // Amount mismatch guard — see note above.
                if ($verifiedAmount > 0 && round($verifiedAmount, 2) < round((float) $resultCheckerOrder->total_price, 2)) {
                    Log::error('Payaza webhook: verified amount is less than expected result checker order amount - refusing to fulfil', [
                        'result_checker_order_id' => $resultCheckerOrder->id,
                        'reference' => $reference,
                        'expected_amount' => $resultCheckerOrder->total_price,
                        'verified_amount' => $verifiedAmount,
                    ]);

                    return response()->json(['success' => true, 'message' => 'Amount mismatch']);
                }

                app(\App\Services\ResultCheckerService::class)->handlePaymentCallback(
                    $resultCheckerOrder,
                    $reference,
                    PaymentGatewayConfig::GATEWAY_PAYAZA
                );

                return response()->json(['success' => true, 'message' => 'Processed']);
            }
        }

        if ($status === 'failed') {
            if ($order) {
                $order->update([
                    'payment_status' => 'failed',
                    'status' => 'Failed',
                ]);

                Transaction::where('order_id', $order->id)
                    ->whereNotIn('payment_status', ['completed', 'successful'])
                    ->update(['payment_status' => 'failed']);

                return response()->json(['success' => true, 'message' => 'Failure recorded']);
            }

            if ($registration) {
                $registration->update([
                    'payment_status' => AfaRegistration::PAYMENT_FAILED,
                    'status' => AfaRegistration::STATUS_CANCELLED,
                ]);

                return response()->json(['success' => true, 'message' => 'Failure recorded']);
            }

            if ($resultCheckerOrder) {
                $resultCheckerOrder->update(['status' => 'failed']);

                return response()->json(['success' => true, 'message' => 'Failure recorded']);
            }
        }

        // Pending/unknown: do nothing; a later webhook, the SDK-callback-triggered
        // verify, or the poll will resolve it.
        return response()->json(['success' => true, 'message' => 'Pending']);
    }
}
