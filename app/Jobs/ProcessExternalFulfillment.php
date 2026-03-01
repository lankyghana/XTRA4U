<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use App\Services\ExternalFulfillment\ExternalFulfillmentClientFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessExternalFulfillment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $orderId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $lockKey = sprintf('lock:external-fulfillment:%d', $this->orderId);
        $lock = Cache::lock($lockKey, 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $order = Order::query()->find($this->orderId);

            if (! $order) {
                return;
            }

            if (! in_array($order->payment_status, ['paid', 'completed'], true)) {
                return;
            }

            if ($order->external_fulfillment_status === 'succeeded') {
                return;
            }

            $targetVendorId = (int) ($order->owner_vendor_id ?: $order->vendor_id);
            if ($targetVendorId <= 0) {
                return;
            }

            $vendor = Vendor::query()->find($targetVendorId);
            if (! $vendor) {
                return;
            }

            $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);
            if (! $config->isReady()) {
                return;
            }

            $idempotencyKey = (string) ($order->external_fulfillment_idempotency_key ?: ('order-' . $order->id));
            $providerUsed = $config->provider ?? 'datafyhub';
            $providerNetwork = $this->resolveProviderNetwork($config, $order);

            $order->setAttribute('external_provider', $providerUsed);
            $order->setAttribute('external_provider_network', $providerNetwork);
            $order->setAttribute('external_provider_networks', config("external_fulfillment.providers.{$providerUsed}.networks", []));

            Order::whereKey($order->id)->update([
                'external_fulfillment_idempotency_key' => $idempotencyKey,
                'external_fulfillment_status' => 'processing',
                'external_fulfillment_attempts' => (int) ($order->external_fulfillment_attempts ?? 0) + 1,
                'external_fulfillment_last_attempt_at' => now(),
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            $client = ExternalFulfillmentClientFactory::make($config);
            $result = $client->sendOrder($order, $idempotencyKey);

            $status = strtolower((string) ($result['status'] ?? ''));
            $success = (bool) ($result['success'] ?? false);
            $remoteReference = $result['external_reference'] ?? null;
            $message = (string) ($result['message'] ?? '');

            if ($success || in_array($status, ['success', 'completed'], true)) {
                Order::whereKey($order->id)->update([
                    'external_fulfillment_status' => 'succeeded',
                    'external_fulfillment_completed_at' => now(),
                    'external_fulfillment_remote_reference' => $remoteReference,
                    'external_fulfillment_last_error' => null,
                    'external_fulfillment_provider_used' => $providerUsed,
                ]);

                return;
            }

            if (in_array($status, ['pending', 'processing'], true)) {
                Order::whereKey($order->id)->update([
                    'external_fulfillment_status' => 'processing',
                    'external_fulfillment_remote_reference' => $remoteReference,
                    'external_fulfillment_last_error' => null,
                    'external_fulfillment_provider_used' => $providerUsed,
                ]);

                return;
            }

            $errorMessage = $message !== '' ? $message : 'External fulfillment failed';

            Order::whereKey($order->id)->update([
                'external_fulfillment_status' => 'failed',
                'external_fulfillment_last_error' => $errorMessage,
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            Log::warning('External fulfillment failed', [
                'order_id' => $order->id,
                'status' => $status,
            ]);

            throw new \RuntimeException($errorMessage);
        } finally {
            optional($lock)->release();
        }
    }

    private function resolveProviderNetwork(ExternalFulfillmentConfig $config, Order $order): ?string
    {
        $provider = $config->provider ?? 'datafyhub';
        $product = null;
        $metadata = [];

        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $metadata = is_array($product?->decoded_description) ? $product->decoded_description : [];
        }

        $networkFromMappings = data_get($metadata, "external_mappings.{$provider}.network");
        if (is_string($networkFromMappings) && $networkFromMappings !== '') {
            return $networkFromMappings;
        }

        $genericNetwork = data_get($metadata, 'external_network');
        if (is_string($genericNetwork) && $genericNetwork !== '') {
            return $genericNetwork;
        }

        $legacyDatafyhub = data_get($metadata, 'datafyhub_network');
        if (is_string($legacyDatafyhub) && $legacyDatafyhub !== '') {
            return $legacyDatafyhub;
        }

        return null;
    }
}
