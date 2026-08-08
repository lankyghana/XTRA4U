<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Order;
use App\Services\ExternalFulfillment\ExternalFulfillmentStatusSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class GigshubWebhookController extends Controller
{
    public function __construct(private ExternalFulfillmentStatusSynchronizer $synchronizer) {}

    public function handle(Request $request)
    {
        if (! $this->verifySignature($request)) {
            Log::warning('GigsHub webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();

        Log::info('GigsHub webhook received', [
            'event' => $payload['event'] ?? null,
            'orderId' => $payload['orderId'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        $orderId = $payload['orderId'] ?? $payload['reference'] ?? null;
        if (! $orderId) {
            return response()->json(['success' => false, 'error' => 'Missing orderId or reference'], 400);
        }

        $order = $this->findOrder([$orderId, $payload['reference'] ?? null]);

        if (! $order) {
            Log::warning('GigsHub webhook: Order not found', [
                'orderId' => $orderId,
                'reference' => $payload['reference'] ?? null,
            ]);

            return response()->json(['success' => false, 'error' => 'Order not found'], 404);
        }

        $outcome = $this->synchronizer->apply(
            (int) $order->id,
            'gigshub',
            (string) ($payload['status'] ?? ''),
            [
                'source' => 'webhook',
                'remote_reference' => $orderId,
            ]
        );

        Log::info('GigsHub webhook processed', [
            'order_id' => $order->id,
            'gigshub_status' => $payload['status'] ?? null,
            'outcome' => $outcome,
        ]);

        return response()->json(['success' => true], 200);
    }

    /**
     * Optional shared-secret check.
     *
     * This endpoint completes real orders, so it accepts either an HMAC-SHA256
     * signature of the raw body or the secret presented verbatim — GigsHub's
     * signing scheme is not documented here, so both forms are allowed.
     *
     * When no secret is configured the request is accepted and a warning is
     * logged: the webhook has always been unauthenticated, and rejecting
     * callbacks on deploy would strand deliveries. Set GIGSHUB_WEBHOOK_SECRET
     * to close it.
     */
    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('services.gigshub.webhook_secret', '');

        if ($secret === '') {
            Log::warning('GigsHub webhook: GIGSHUB_WEBHOOK_SECRET not set; accepting unauthenticated callback.');

            return true;
        }

        $provided = (string) ($request->header('X-Gigshub-Signature')
            ?: $request->header('X-Webhook-Secret', ''));

        if ($provided === '') {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedHmac, $provided) || hash_equals($secret, $provided);
    }

    /**
     * Match on the provider's reference. Scoped to orders GigsHub actually
     * handled so a reference collision with another provider cannot pick the
     * wrong order.
     *
     * @param  array<int,string|null>  $references
     */
    private function findOrder(array $references): ?Order
    {
        $references = array_values(array_unique(array_filter(
            array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', $references),
            fn (string $value) => $value !== ''
        )));

        if ($references === []) {
            return null;
        }

        return Order::query()
            ->whereIn('external_fulfillment_remote_reference', $references)
            ->where(function ($query) {
                $query->where('external_fulfillment_provider_used', 'gigshub')
                    ->orWhereNull('external_fulfillment_provider_used');
            })
            ->orderByDesc('id')
            ->first();
    }
}
