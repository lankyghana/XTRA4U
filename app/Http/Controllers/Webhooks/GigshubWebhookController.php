<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class GigshubWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('GigsHub webhook received', [
            'event' => $payload['event'] ?? null,
            'orderId' => $payload['orderId'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        $orderId = $payload['orderId'] ?? $payload['reference'] ?? null;
        if (!$orderId) {
            return response()->json(['success' => false, 'error' => 'Missing orderId or reference'], 400);
        }

        $order = Order::query()
            ->where('external_fulfillment_remote_reference', $orderId)
            ->orWhere('external_fulfillment_remote_reference', $payload['reference'] ?? null)
            ->first();

        if (!$order) {
            Log::warning('GigsHub webhook: Order not found', [
                'orderId' => $orderId,
                'reference' => $payload['reference'] ?? null,
            ]);

            return response()->json(['success' => false, 'error' => 'Order not found'], 404);
        }

        $status = strtolower($payload['status'] ?? '');

        // Map GigsHub status to internal status
        $externalStatus = match ($status) {
            'delivered' => 'succeeded',
            'failed', 'cancelled', 'refunded' => 'failed',
            'pending', 'processing' => 'processing',
            default => $status,
        };

        $order->update([
            'external_fulfillment_status' => $externalStatus,
            'external_fulfillment_last_error' => $status === 'failed' ? 'Order failed at provider' : null,
            'external_fulfillment_completed_at' => in_array($status, ['delivered', 'failed']) ? now() : $order->external_fulfillment_completed_at,
        ]);

        Log::info('GigsHub webhook processed', [
            'order_id' => $order->id,
            'gigshub_status' => $status,
            'internal_status' => $externalStatus,
        ]);

        return response()->json(['success' => true], 200);
    }
}
