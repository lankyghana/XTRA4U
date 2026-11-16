<?php

namespace App\Http\Resources\DeveloperApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Developer\ApiQuote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offer = $this->snapshot['offer'] ?? [];

        return [
            'reference' => $this->reference,
            'product_id' => $this->offer_id,
            'product_name' => $offer['name'] ?? null,
            'network' => $offer['network'] ?? null,
            'size' => $offer['size'] ?? null,
            'seller' => $offer['seller'] ?? null,
            'recipient_phone' => $this->recipient_phone,
            'price' => (float) $this->amount,
            'currency' => $this->currency,
            'availability' => 'available',
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
