<?php

namespace App\Services\ExternalFulfillment;

use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ExternalFulfillmentClient
{
    public function __construct(private ExternalFulfillmentConfig $config)
    {
    }

    public function sendOrder(Order $order, string $idempotencyKey): Response
    {
        $fulfillmentVendorId = (int) ($order->owner_vendor_id ?: $order->vendor_id);

        $payload = [
            'order_id' => (int) $order->id,
            'recipient_phone_number' => (string) $order->recipient_phone_number,
            'service_purchased' => (string) $order->service_purchased,
            'amount_paid' => (float) $order->amount_paid,
            'vendor_id' => $fulfillmentVendorId,
            'payment_reference' => (string) ($order->payment_reference ?? ''),
            'idempotency_key' => $idempotencyKey,
        ];

        return Http::timeout($this->config->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->withToken($this->config->token)
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post($this->config->url(), $payload);
    }
}
