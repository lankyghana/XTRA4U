<?php

namespace App\Http\Resources\DeveloperApi;

use App\Models\Developer\SandboxOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a SANDBOX order. Same keys as the production OrderResource (so an integration can use
 * one parser for both) plus explicit sandbox markers. Whitelist only: no internal ids, no key/vendor ids.
 *
 * @mixin SandboxOrder
 */
class SandboxOrderResource extends JsonResource
{
    public const PAYMENT_METHOD = 'simulated_mobile_money';

    public function toArray(Request $request): array
    {
        $offer = $this->product_snapshot['offer'] ?? [];

        return [
            'id' => $this->order_ref,
            'environment' => 'sandbox',
            'simulated' => true,
            'status' => $this->status,
            'amount' => (float) $this->total_amount,
            'currency' => $this->currency,
            'quantity' => (int) $this->quantity,
            'recipient_phone' => $this->recipient_phone,
            'client_reference' => $this->client_reference,
            'product' => [
                'name' => $offer['name'] ?? null,
                'network' => $offer['network'] ?? null,
                'size' => $offer['size'] ?? null,
            ],
            'seller' => $offer['seller'] ?? null,
            'payment' => [
                'status' => $this->payment_status,
                'reference' => $this->payment_reference,
                'method' => $this->payment_reference ? self::PAYMENT_METHOD : null,
                'simulated' => true,
            ],
            'fulfillment' => [
                'status' => $this->fulfillment_status,
                'simulated' => true,
                'result' => $this->fulfillment_result,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
