<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Transaction;
use App\Services\AfaPaymentService;
use App\Services\MoolrePaymentService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MoolreWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $paymentService, AfaPaymentService $afaPaymentService)
    {
        $payload = $request->all();
        $data = (array) ($payload['data'] ?? []);

        $externalRef = (string) (
            $data['externalref']
                ?? $data['externalRef']
                ?? $data['reference']
                ?? $data['trxref']
                ?? $payload['externalref']
                ?? $payload['externalRef']
                ?? $payload['reference']
                ?? $payload['trxref']
                ?? ''
        );
        $txStatus = isset($data['txstatus'])
            ? (int) $data['txstatus']
            : (isset($payload['txstatus']) ? (int) $payload['txstatus'] : null);
        $secret = (string) ($data['secret'] ?? $payload['secret'] ?? '');

        if ($externalRef === '' || $txStatus === null) {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 400);
        }

        $config = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_MOOLRE)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            Log::warning('Moolre webhook: no active payment collection config', ['externalref' => $externalRef]);
            return response()->json(['success' => true, 'message' => 'OK']);
        }

        $expectedSecret = $config?->getConfig('webhook_secret');

        // If an expected webhook secret is configured, require a matching secret
        // in the payload. Reject mismatches to avoid processing forged webhooks.
        if (!empty($expectedSecret)) {
            if ($secret === '' || !hash_equals((string) $expectedSecret, $secret)) {
                Log::warning('Moolre webhook secret mismatch - rejecting', [
                    'externalref' => $externalRef,
                ]);

                // Return 403 so operator/gateway gets clear signal the signature failed.
                return response()->json(['success' => false, 'message' => 'Invalid webhook signature'], 403);
            }
        } else {
            // Optional: capture/log secret if present (useful for ops/debug), but don't require it.
            if ($secret !== '') {
                Log::info('Moolre webhook secret observed (optional; used only for extra verification)', [
                    'externalref' => $externalRef,
                ]);
            }
        }

        $order = Order::where('payment_reference', $externalRef)->first();
        $registration = null;

        if (!$order) {
            // Support generic payments (e.g., AFA registrations) which use externalref/payment_reference too.
            $registration = AfaRegistration::query()
                ->where('payment_reference', $externalRef)
                ->orWhere('reference', $externalRef)
                ->first();

            if (!$registration) {
                Log::warning('Moolre webhook: order/afa registration not found', ['externalref' => $externalRef]);
                // Return 200 to avoid endless retries.
                return response()->json(['success' => true, 'message' => 'OK']);
            }

            // Idempotency: ignore already-completed AFA registrations.
            if ($registration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }
        } else {
            // Idempotency: ignore already-completed orders.
            if (in_array($order->payment_status, ['paid', 'completed'], true)) {
                return response()->json(['success' => true, 'message' => 'Already processed']);
            }
        }

        // Webhook is treated as a trigger; the source of truth is Moolre's status API.
        $verification = (new MoolrePaymentService($config))->verifyPayment($externalRef);

        if (!(bool) ($verification['success'] ?? false)) {
            Log::warning('Moolre webhook: status verification failed', [
                'externalref' => $externalRef,
                'message' => $verification['message'] ?? null,
            ]);

            // Return 200 to prevent endless retries.
            return response()->json(['success' => true, 'message' => 'OK']);
        }

        $status = (string) data_get($verification, 'data.status', 'unknown');
        $verifiedAmount = (float) data_get($verification, 'data.amount', 0);

        if ($status === 'success') {
            if ($order) {
                if ($verifiedAmount > 0 && ((float) $order->amount_paid) <= 0) {
                    $order->amount_paid = $verifiedAmount;
                    $order->save();
                }

                $paymentService->completeOrder($order);
                return response()->json(['success' => true, 'message' => 'Processed']);
            }

            // AFA registration completion
            $afaPaymentService->completeRegistration($registration);
            return response()->json(['success' => true, 'message' => 'Processed']);
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

            $registration->update([
                'payment_status' => AfaRegistration::PAYMENT_FAILED,
                'status' => AfaRegistration::STATUS_CANCELLED,
            ]);

            return response()->json(['success' => true, 'message' => 'Failure recorded']);
        }

        // Pending/unknown: do nothing; later webhook or polling will resolve.
        return response()->json(['success' => true, 'message' => 'Pending']);
    }
}
