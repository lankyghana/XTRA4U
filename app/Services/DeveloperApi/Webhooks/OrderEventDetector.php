<?php

namespace App\Services\DeveloperApi\Webhooks;

use App\Http\Resources\DeveloperApi\OrderResource;
use App\Models\Developer\DeveloperOrderState;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled sweeper that turns OBSERVED order state into developer events.
 *
 * Why a sweeper: orders change through many paths (webhooks, reconciler, admin
 * actions, query-builder updates in PaymentFailureTransition) and several skip
 * Eloquent events. The platform also keeps NO order status history. So the only
 * reliable source of truth is the order row itself, polled.
 *
 * Guarantees and limits (deliberately honest):
 *  - It detects the LATEST state at each sweep. If an order moves through two
 *    states between sweeps, only the later state is observed; the skipped
 *    intermediate event is not fabricated. (order.created and payment.pending are
 *    additionally recorded at the moment the API itself causes them, which narrows
 *    that gap for the two states we trigger.)
 *  - Each (order, event type) is recorded at most once, ever (unique index).
 *  - Events are persisted before delivery; the checkpoint row only records what was
 *    last seen and is safe to lose or rebuild — the unique index remains the guard.
 *  - A failed order stays under watch for the detection window because a later
 *    trusted payment can still settle it (existing platform behaviour).
 */
class OrderEventDetector
{
    public function __construct(private readonly DeveloperEventRecorder $recorder) {}

    /** @return int number of NEW events recorded */
    public function sweep(): int
    {
        $created = 0;
        $window = now()->subDays((int) config('developer_api.webhooks.detect_window_days', 14));

        Order::query()
            ->whereNotNull('developer_application_id')
            ->where('created_at', '>=', $window)
            ->whereNotIn('id', DeveloperOrderState::query()->select('order_id')->where('is_terminal', true))
            ->orderBy('id')
            ->chunkById((int) config('developer_api.webhooks.sweep_batch', 200), function ($orders) use (&$created) {
                foreach ($orders as $order) {
                    $created += $this->observe($order);
                }
            });

        return $created;
    }

    public function observe(Order $order): int
    {
        $payment = OrderResource::paymentState($order);
        $fulfillment = OrderResource::fulfillmentState($order);
        $created = 0;

        foreach ($this->eventsFor($payment, $fulfillment, OrderResource::overallState($order)) as $type) {
            if ($this->recorder->record($order, $type)) {
                $created++;
            }
        }

        DB::transaction(function () use ($order, $payment, $fulfillment) {
            DeveloperOrderState::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'application_id' => $order->developer_application_id,
                    'payment_state' => $payment,
                    'fulfillment_state' => $fulfillment,
                    'is_terminal' => $fulfillment === 'completed',
                    'last_seen_at' => now(),
                ]
            );
        });

        return $created;
    }

    /**
     * State -> event mapping. Only real, existing states:
     *   payment : unpaid | pending | paid | failed        (from payment_status / payment_reference)
     *   order   : Pending | Processing | Completed | Failed (orders.status)
     *
     * @return list<string>
     */
    public function eventsFor(string $payment, string $fulfillment, string $overall): array
    {
        $types = ['order.created'];

        $types[] = match ($payment) {
            'pending' => 'payment.pending',
            'paid' => 'payment.succeeded',
            'failed' => 'payment.failed',
            default => null,
        };

        if ($overall === 'failed') {
            $types[] = 'order.failed';
        } elseif ($fulfillment === 'completed') {
            $types[] = 'order.completed';
        } elseif ($payment === 'paid' && $fulfillment === 'processing') {
            $types[] = 'order.processing';
        }

        return array_values(array_filter($types));
    }
}
