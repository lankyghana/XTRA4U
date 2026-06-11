<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Handles delivery notifications sent by SKDataPlug.
 *
 * SKDataPlug POSTs to this URL when an admin marks an order as delivered.
 * Each notification includes an X-SKPlug-Signature header for verification.
 *
 * Expected payload:
 *   {
 *     "order_id": "SKP36648705",
 *     "status":   "delivered"     // pending | processing | delivered | failed
 *   }
 *
 * Route: POST /webhooks/skdataplug
 * Name:  api.webhooks.skdataplug
 */
class SkdataplugWebhookController extends Controller
{
    /**
     * Status map: SKDataPlug status → internal external_fulfillment_status.
     */
    private const STATUS_MAP = [
        'delivered'  => 'succeeded',
        'failed'     => 'failed',
        'processing' => 'processing',
        'pending'    => 'processing',
    ];

    public function handle(Request $request)
    {
        // ── 1. Verify signature ────────────────────────────────────────────────
        if (! $this->verifySignature($request)) {
            Log::warning('SKDataPlug webhook: invalid signature', [
                'ip'        => $request->ip(),
                'signature' => $request->header('X-SKPlug-Signature'),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();

        // ── 2. Extract identifiers ─────────────────────────────────────────────
        $remoteOrderId = $payload['order_id'] ?? null;
        $status        = strtolower(trim($payload['status'] ?? ''));

        Log::info('SKDataPlug webhook received', [
            'order_id' => $remoteOrderId,
            'status'   => $status,
        ]);

        if (! $remoteOrderId) {
            return response()->json(['error' => 'Missing order_id.'], 400);
        }

        // ── 3. Locate internal order ───────────────────────────────────────────
        $order = Order::where('external_fulfillment_remote_reference', $remoteOrderId)->first();

        if (! $order) {
            Log::warning('SKDataPlug webhook: order not found', [
                'remote_order_id' => $remoteOrderId,
            ]);

            // Return 200 so SKDataPlug doesn't keep retrying for unknown IDs.
            return response()->json(['success' => false, 'error' => 'Order not found.'], 404);
        }

        // ── 4. Map & apply status ──────────────────────────────────────────────
        $internalStatus = self::STATUS_MAP[$status] ?? $status;

        // Idempotency: skip if already at the same terminal state.
        if ($order->external_fulfillment_status === $internalStatus) {
            Log::info('SKDataPlug webhook: duplicate notification (no change)', [
                'order_id'        => $order->id,
                'skdataplug_status' => $status,
            ]);

            return response()->json(['success' => true], 200);
        }

        $isTerminal = in_array($status, ['delivered', 'failed'], true);

        $order->update([
            'external_fulfillment_status'       => $internalStatus,
            'external_fulfillment_last_error'   => $status === 'failed'
                ? ($payload['message'] ?? 'Order failed at SKDataPlug.')
                : null,
            'external_fulfillment_completed_at' => $isTerminal
                ? now()
                : $order->external_fulfillment_completed_at,
        ]);

        Log::info('SKDataPlug webhook processed', [
            'order_id'        => $order->id,
            'skdataplug_status' => $status,
            'internal_status' => $internalStatus,
        ]);

        return response()->json(['success' => true], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verify the X-SKPlug-Signature header.
     *
     * SKDataPlug signs notifications with a shared secret. The signature is an
     * HMAC-SHA256 of the raw request body, hex-encoded. If no token is
     * configured we log a warning but still accept the request (so the webhook
     * works while the token is being set up).
     */
    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('services.skdataplug.token', '');

        // No secret configured — skip verification (warn in logs).
        if ($secret === '') {
            Log::warning('SKDataPlug webhook: SKDATAPLUG_TOKEN not set; skipping signature check.');
            return true;
        }

        $receivedSignature = (string) $request->header('X-SKPlug-Signature', '');
        if ($receivedSignature === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $receivedSignature);
    }
}
