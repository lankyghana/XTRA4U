<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\VendorWithdrawal;
use App\Services\MoolrePaymentService;
use App\Services\PaymentService;

class MoolreWebhookController extends Controller
{
    public function handlePaymentWebhook(Request $request, MoolrePaymentService $moolre, PaymentService $paymentService)
    {
        $payload = $request->all();
        
        Log::info('Moolre payment webhook received', ['payload' => $payload]);

        // Moolre sends webhook secret IN the payload, not as a header
        $webhookSecret = $payload['secret'] ?? null;
        if (!$webhookSecret) {
            Log::warning('Moolre webhook missing secret field', ['payload' => $payload]);
            abort(403, 'Missing webhook secret');
        }

        // Verify webhook signature
        if (!$moolre->verifyWebhook($payload, $webhookSecret)) {
            Log::warning('Moolre webhook signature verification failed', ['payload' => $payload]);
            abort(403, 'Invalid signature');
        }

        // Get payment reference
        $reference = $payload['reference'] ?? $payload['transaction_reference'] ?? null;
        if (!$reference) {
            Log::warning('Moolre webhook missing reference', ['payload' => $payload]);
            return response()->json(['success' => false, 'message' => 'Missing reference'], 400);
        }

        // Find order
        $order = Order::where('payment_reference', $reference)->first();
        if (!$order) {
            Log::warning('Moolre webhook: order not found', [
                'reference' => $reference,
                'payload' => $payload,
            ]);
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        // Check if already completed (idempotency)
        if ($order->payment_status === 'completed') {
            Log::info('Moolre webhook: order already completed', [
                'order_id' => $order->id,
                'reference' => $reference,
            ]);
            return response()->json(['success' => true, 'message' => 'Already processed']);
        }

        // Check payment status
        $status = strtolower($payload['status'] ?? 'failed');

        if ($status === 'success' || $status === 'successful' || $status === 'completed') {
            // Payment successful - complete order
            try {
                $paymentService->completeOrder($order);

                Log::info('Moolre webhook: order completed successfully', [
                    'order_id' => $order->id,
                    'reference' => $reference,
                ]);

                return response()->json(['success' => true]);
            } catch (\Exception $e) {
                Log::error('Moolre webhook: failed to complete order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process order',
                ], 500);
            }
        } else {
            // Payment failed - DELETE order (unsuccessful payments not recorded)
            $paymentService->markTransactionFailed($order, "Webhook status: {$status}");

            Log::info('Moolre webhook: payment failed, order deleted', [
                'reference' => $reference,
                'status' => $status,
            ]);

            return response()->json(['success' => true, 'message' => 'Failed order deleted']);
        }
    }

    public function handlePayoutWebhook(Request $request, MoolrePaymentService $moolre)
    {
        $payload = $request->all();
        $signature = $request->header('X-Moolre-Signature');
        if (!$moolre->verifyWebhook($payload, $signature)) {
            Log::warning('Moolre payout webhook signature failed', ['payload' => $payload]);
            abort(403, 'Invalid signature');
        }
        $withdrawal = VendorWithdrawal::where('payout_reference', $payload['reference'] ?? null)->first();
        if (!$withdrawal) {
            Log::warning('Moolre payout webhook: withdrawal not found', ['payload' => $payload]);
            return response()->json(['success' => false], 404);
        }
        $withdrawal->payout_status = $payload['status'] ?? 'failed';
        $withdrawal->save();
        return response()->json(['success' => true]);
    }
}
