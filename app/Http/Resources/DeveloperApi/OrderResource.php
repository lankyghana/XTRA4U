<?php

namespace App\Http\Resources\DeveloperApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of an order. Whitelist only: no internal ids, no earnings,
 * commission, wallet, gateway, vendor contact or integrity fields.
 *
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    /** unpaid | pending | paid | failed — derived from existing columns only. */
    public static function paymentState($order): string
    {
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return 'paid';
        }
        if ($order->payment_status === 'failed') {
            return 'failed';
        }

        return $order->payment_reference ? 'pending' : 'unpaid';
    }

    public static function fulfillmentState($order): string
    {
        return self::paymentState($order) === 'paid' || strtolower((string) $order->status) === 'failed'
            ? strtolower((string) ($order->status ?: 'pending'))
            : 'not_started';
    }

    /** awaiting_payment | payment_pending | processing | completed | failed */
    public static function overallState($order): string
    {
        $pay = self::paymentState($order);
        $ful = self::fulfillmentState($order);

        return match (true) {
            $pay === 'failed', $ful === 'failed' => 'failed',
            $ful === 'completed' => 'completed',
            $pay === 'paid' => 'processing',
            $pay === 'pending' => 'payment_pending',
            default => 'awaiting_payment',
        };
    }

    public function toArray(Request $request): array
    {
        $meta = $this->service?->decoded_description ?? [];

        return [
            'id' => $this->developer_order_ref,
            'status' => self::overallState($this->resource),
            'amount' => (float) $this->expected_amount,
            'currency' => $this->currency,
            'recipient_phone' => $this->recipient_phone_number,
            'product' => [
                'name' => $this->service_purchased,
                'network' => $meta['network'] ?? null,
                'size' => $meta['size'] ?? null,
            ],
            'seller' => $this->vendor ? ['code' => $this->vendor->vendor_code, 'name' => $this->vendor->name] : null,
            'payment' => [
                'status' => self::paymentState($this->resource),
                'reference' => $this->payment_reference,
            ],
            'fulfillment' => ['status' => self::fulfillmentState($this->resource)],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
