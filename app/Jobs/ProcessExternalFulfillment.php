<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\ExternalFulfillment\ExternalFulfillmentClient;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
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
        $config = ExternalFulfillmentConfig::loadFresh();

        if (! $config->isReady()) {
            return;
        }

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

            $idempotencyKey = (string) ($order->external_fulfillment_idempotency_key ?: ('order-' . $order->id));

            Order::whereKey($order->id)->update([
                'external_fulfillment_idempotency_key' => $idempotencyKey,
                'external_fulfillment_status' => 'processing',
                'external_fulfillment_attempts' => (int) ($order->external_fulfillment_attempts ?? 0) + 1,
                'external_fulfillment_last_attempt_at' => now(),
            ]);

            $client = new ExternalFulfillmentClient($config);
            $response = $client->sendOrder($order, $idempotencyKey);

            if ($response->successful()) {
                $json = $response->json();

                $remoteReference = null;
                foreach (['reference', 'id', 'transaction_id', 'data.reference', 'data.id'] as $path) {
                    $value = data_get($json, $path);
                    if (is_scalar($value) && (string) $value !== '') {
                        $remoteReference = (string) $value;
                        break;
                    }
                }

                Order::whereKey($order->id)->update([
                    'external_fulfillment_status' => 'succeeded',
                    'external_fulfillment_completed_at' => now(),
                    'external_fulfillment_remote_reference' => $remoteReference,
                    'external_fulfillment_last_error' => null,
                ]);

                return;
            }

            $errorMessage = 'HTTP ' . $response->status();
            $body = $response->body();
            if (is_string($body) && $body !== '') {
                $errorMessage .= ': ' . mb_substr($body, 0, 1000);
            }

            Order::whereKey($order->id)->update([
                'external_fulfillment_status' => 'failed',
                'external_fulfillment_last_error' => $errorMessage,
            ]);

            Log::warning('External fulfillment failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException($errorMessage);
        } finally {
            optional($lock)->release();
        }
    }
}
