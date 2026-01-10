<?php

namespace App\Services\ExternalFulfillment;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ExternalFulfillmentClient
{
    public function __construct(private ExternalFulfillmentConfig $config)
    {
    }

    public function sendOrder(Order $order, string $idempotencyKey): Response
    {
        // Datafyhub API payload shape:
        // {
        //   "network": "mtn",
        //   "reference": "17323233259547876",
        //   "recipient": "0591178627",
        //   "capacity": 2
        // }
        $payload = [
            'network' => $this->resolveDatafyhubNetwork($order),
            'reference' => $this->resolveDatafyhubReference($order, $idempotencyKey),
            'recipient' => (string) $order->recipient_phone_number,
            'capacity' => $this->resolveDatafyhubCapacity($order),
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

    private function resolveDatafyhubReference(Order $order, string $idempotencyKey): string
    {
        $reference = (string) ($order->payment_reference ?? '');
        if ($reference !== '') {
            return $reference;
        }

        return $idempotencyKey;
    }

    private function resolveDatafyhubNetwork(Order $order): string
    {
        $network = null;
        $product = null;
        $explicitDatafyhubNetwork = null;

        // Prefer structured metadata from the product description.
        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $network = is_string(data_get($product, 'decoded_description.network'))
                ? (string) data_get($product, 'decoded_description.network')
                : null;

            $explicitDatafyhubNetwork = is_string(data_get($product, 'decoded_description.datafyhub_network'))
                ? trim((string) data_get($product, 'decoded_description.datafyhub_network'))
                : null;
        }

        if ($explicitDatafyhubNetwork !== '') {
            $explicitNormalized = strtolower(preg_replace('/\s+/', '', (string) $explicitDatafyhubNetwork));
            if (in_array($explicitNormalized, ['mtn', 'telecel', 'ishare', 'bigtime', 'xpress'], true)) {
                return $explicitNormalized;
            }
        }

        // Fallback to any captured order network.
        if (! $network) {
            $network = is_string($order->mobile_money_network) ? $order->mobile_money_network : null;
        }

        $network = trim((string) ($network ?? ''));

        if ($network === '') {
            // Datafyhub requires a network key; default to mtn as a safe fallback.
            return 'mtn';
        }

        $normalized = strtolower(preg_replace('/\s+/', '', $network));

        // Datafyhub-supported networks (per docs): mtn, telecel, ishare, bigtime, xpress
        if (in_array($normalized, ['mtn', 'telecel', 'ishare', 'bigtime', 'xpress'], true)) {
            return $normalized;
        }

        if (in_array($normalized, ['vodafone'], true)) {
            return 'telecel';
        }

        if (in_array($normalized, ['airteltigo', 'airtel-tigo', 'tigo', 'airtel'], true)) {
            $hints = [];
            $hints[] = (string) ($product?->name ?? '');
            $hints[] = (string) ($order->service_purchased ?? '');
            $hints[] = (string) data_get($product, 'decoded_description.tag', '');
            $hints[] = (string) data_get($product, 'decoded_description.notes', '');
            $hint = strtolower(implode(' ', array_filter($hints)));

            if (str_contains($hint, 'ishare') || str_contains($hint, 'i-share')) {
                return 'ishare';
            }

            if (str_contains($hint, 'bigtime') || str_contains($hint, 'big time')) {
                return 'bigtime';
            }

            if (str_contains($hint, 'xpress')) {
                return 'xpress';
            }

            // If the product/network metadata doesn't disambiguate AirtelTigo package type,
            // prefer failing fast over silently sending an invalid network.
            throw new \RuntimeException('External fulfillment network mapping failed: AirtelTigo requires ishare/bigtime/xpress.');
        }

        // Unknown network: send the normalized value (external API may reject it).
        return $normalized;
    }

    private function resolveDatafyhubCapacity(Order $order): int
    {
        // Try to parse the product "size" metadata (commonly something like "2" or "2GB").
        $raw = null;
        $product = null;

        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $raw = data_get($product, 'decoded_description.size');
        }

        if (is_numeric($raw)) {
            $capacity = (int) $raw;
            return max(1, $capacity);
        }

        if (is_string($raw)) {
            if (preg_match('/(\d+)/', $raw, $m)) {
                return max(1, (int) $m[1]);
            }
        }

        // Fallback: parse digits from product/order display names.
        $hints = [];
        $hints[] = (string) ($product?->name ?? '');
        $hints[] = (string) ($order->service_purchased ?? '');
        $hint = implode(' ', array_filter($hints));

        if ($hint !== '' && preg_match('/(\d+)\s*(gb|g)?/i', $hint, $m)) {
            return max(1, (int) $m[1]);
        }

        return 1;
    }
}
