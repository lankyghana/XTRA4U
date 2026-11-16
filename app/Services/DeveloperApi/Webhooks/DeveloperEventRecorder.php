<?php

namespace App\Services\DeveloperApi\Webhooks;

use App\Http\Resources\DeveloperApi\OrderResource;
use App\Models\Developer\DeveloperEvent;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Persists a developer event BEFORE any delivery is attempted.
 *
 * Duplicate-event protection is the UNIQUE (order_id, type) index: every event
 * type happens at most once per order, so however many times the sweeper,
 * the request path or a retry sees the same transition, exactly one row exists.
 */
class DeveloperEventRecorder
{
    /** @return bool true if this call created the event, false if it already existed */
    public function record(Order $order, string $type): bool
    {
        if (! in_array($type, (array) config('developer_api.webhooks.events', []), true) || ! $order->developer_application_id) {
            return false;
        }

        $order->loadMissing(['service', 'vendor:id,name,vendor_code']);

        try {
            DeveloperEvent::create([
                'event_id' => 'evt_'.Str::lower((string) Str::ulid()),
                'application_id' => $order->developer_application_id,
                'order_id' => $order->id,
                'type' => $type,
                'environment' => 'production',
                'payload' => ['order' => (new OrderResource($order))->resolve()],
                'occurred_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $code === '23000' || $code === '23505' || in_array($driverCode, [19, 1062], true);
    }
}
