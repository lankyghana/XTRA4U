<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Transaction;
use App\Services\MoolrePaymentService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MoolreWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $paymentService)
    {
        $payload = $request->all();
        $data = (array) ($payload['data'] ?? []);

        $externalRef = (string) ($data['externalref'] ?? '');
        $txStatus = isset($data['txstatus']) ? (int) $data['txstatus'] : null;
        $secret = (string) ($data['secret'] ?? '');

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

        // Optional: capture/log secret if present (useful for ops/debug), but don't require it.
        if (empty($expectedSecret) && $secret !== '') {
            Log::info('Moolre webhook secret observed (optional; used only for extra verification)', [
                'externalref' => $externalRef,
            ]);
        }

        // If a secret is configured, we can log mismatches for visibility.
        // We still verify using the status API, so a mismatch doesn't block order completion.
        if (!empty($expectedSecret) && $secret !== '' && !hash_equals((string) $expectedSecret, $secret)) {
            Log::warning('Moolre webhook secret mismatch (continuing with status verification)', [
                'externalref' => $externalRef,
            ]);
        }

        $order = Order::where('payment_reference', $externalRef)->first();

        if (!$order) {
            Log::warning('Moolre webhook: order not found', ['externalref' => $externalRef]);
            // Return 200 to avoid endless retries.
            return response()->json(['success' => true, 'message' => 'OK']);
        }

        // Idempotency: ignore already-completed orders.
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return response()->json(['success' => true, 'message' => 'Already processed']);
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
            if ($verifiedAmount > 0 && ((float) $order->amount_paid) <= 0) {
                $order->amount_paid = $verifiedAmount;
                $order->save();
            }

            $paymentService->completeOrder($order);
            return response()->json(['success' => true, 'message' => 'Processed']);
        }

        if ($status === 'failed') {
            $order->update([
                'payment_status' => 'failed',
                'status' => 'Failed',
            ]);

            Transaction::where('order_id', $order->id)
                ->whereNotIn('payment_status', ['completed', 'successful'])
                ->update(['payment_status' => 'failed']);

            return response()->json(['success' => true, 'message' => 'Failure recorded']);
        }

        // Pending/unknown: do nothing; later webhook or polling will resolve.
        return response()->json(['success' => true, 'message' => 'Pending']);
    }
}
