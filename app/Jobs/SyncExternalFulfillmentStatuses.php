<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\Contracts\SupportsStatusPolling;
use App\Services\ExternalFulfillment\ExternalFulfillmentClientFactory;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use App\Services\ExternalFulfillment\ExternalFulfillmentStatusSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for orders a provider accepted but never reported back on.
 *
 * Webhooks are the primary channel; this job exists because a missed or
 * undeliverable webhook would otherwise strand an order in "Processing"
 * forever. It only reads from providers and hands whatever it finds to
 * ExternalFulfillmentStatusSynchronizer, so polling and webhooks apply status
 * through exactly the same code path.
 *
 * Credentials are per-vendor, so work is grouped by the vendor responsible for
 * fulfilling each order.
 */
class SyncExternalFulfillmentStatuses implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Stops overlapping runs if one poll outlives the schedule interval. */
    public int $uniqueFor = 900;

    public function __construct(public ?int $vendorId = null) {}

    public function uniqueId(): string
    {
        return 'sync-external-fulfillment:'.($this->vendorId ?? 'all');
    }

    public function handle(ExternalFulfillmentStatusSynchronizer $synchronizer): void
    {
        if (! config('external_fulfillment.polling.enabled', true)) {
            return;
        }

        $pollable = (array) config('external_fulfillment.polling.pollable', []);
        if ($pollable === []) {
            return;
        }

        $vendorIds = $this->vendorId !== null
            ? [$this->vendorId]
            : $this->vendorsWithPendingOrders($pollable);

        foreach ($vendorIds as $vendorId) {
            try {
                $this->syncVendor((int) $vendorId, $pollable, $synchronizer);
            } catch (\Throwable $e) {
                // One vendor's bad credentials must not stop every other vendor.
                Log::error('External fulfillment status sync failed for vendor', [
                    'vendor_id' => $vendorId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int,string>  $pollable
     */
    private function syncVendor(int $vendorId, array $pollable, ExternalFulfillmentStatusSynchronizer $synchronizer): void
    {
        $vendor = Vendor::query()->find($vendorId);
        if (! $vendor) {
            return;
        }

        $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);

        foreach ($pollable as $provider) {
            if (! $config->isProviderReady($provider)) {
                continue;
            }

            $client = ExternalFulfillmentClientFactory::make($config, $provider);

            if (! $client instanceof SupportsStatusPolling) {
                continue;
            }

            $orders = $this->pendingOrdersQuery($vendorId, $provider)
                ->limit((int) config('external_fulfillment.polling.batch_size', 100))
                ->get(['id', 'external_fulfillment_remote_reference']);

            foreach ($orders as $order) {
                $this->syncOrder($order, $provider, $client, $synchronizer);
            }
        }
    }

    private function syncOrder(
        Order $order,
        string $provider,
        SupportsStatusPolling $client,
        ExternalFulfillmentStatusSynchronizer $synchronizer
    ): void {
        $reference = (string) $order->external_fulfillment_remote_reference;

        // Stamp the attempt before the call so a provider that times out every
        // time cannot be re-polled on a tight loop.
        Order::whereKey($order->id)->update([
            'external_fulfillment_last_status_check_at' => now(),
        ]);

        $result = $client->fetchRemoteStatus($reference);

        if (! ($result['success'] ?? false) || ($result['status'] ?? null) === null) {
            Log::info('External fulfillment status poll returned no usable status', [
                'order_id' => $order->id,
                'provider' => $provider,
                'remote_reference' => $reference,
                'message' => $result['message'] ?? null,
            ]);

            return;
        }

        $synchronizer->apply((int) $order->id, $provider, (string) $result['status'], [
            'source' => 'polling',
            'remote_reference' => $reference,
        ]);
    }

    /**
     * Orders this provider accepted that have not reached a local terminal
     * state yet, throttled by how recently each was checked.
     */
    private function pendingOrdersQuery(int $vendorId, string $provider)
    {
        $minRecheck = (int) config('external_fulfillment.polling.min_recheck_minutes', 10);
        $maxAgeHours = (int) config('external_fulfillment.polling.max_age_hours', 168);

        return Order::query()
            ->where('status', 'Processing')
            ->whereIn('payment_status', ['paid', 'completed'])
            ->where('external_fulfillment_provider_used', $provider)
            ->whereIn('external_fulfillment_status', ['processing', 'succeeded'])
            ->whereNotNull('external_fulfillment_remote_reference')
            ->where('external_fulfillment_remote_reference', '!=', 'duplicate-order-detected')
            ->where(function ($query) use ($vendorId) {
                // Whoever owns fulfillment owns the provider credentials:
                // the selling vendor normally, the product owner for reseller orders.
                $query->where(function ($q) use ($vendorId) {
                    $q->where('is_reseller_order', false)->where('vendor_id', $vendorId);
                })->orWhere(function ($q) use ($vendorId) {
                    $q->where('is_reseller_order', true)->where('owner_vendor_id', $vendorId);
                });
            })
            ->where('created_at', '>=', now()->subHours($maxAgeHours))
            ->where(function ($query) use ($minRecheck) {
                $query->whereNull('external_fulfillment_last_status_check_at')
                    ->orWhere('external_fulfillment_last_status_check_at', '<=', now()->subMinutes($minRecheck));
            })
            ->orderBy('id');
    }

    /**
     * @param  array<int,string>  $pollable
     * @return array<int,int>
     */
    private function vendorsWithPendingOrders(array $pollable): array
    {
        $maxAgeHours = (int) config('external_fulfillment.polling.max_age_hours', 168);

        return Order::query()
            ->where('status', 'Processing')
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereIn('external_fulfillment_provider_used', $pollable)
            ->whereIn('external_fulfillment_status', ['processing', 'succeeded'])
            ->whereNotNull('external_fulfillment_remote_reference')
            ->where('created_at', '>=', now()->subHours($maxAgeHours))
            ->selectRaw('DISTINCT COALESCE(owner_vendor_id, vendor_id) as fulfilling_vendor_id')
            ->pluck('fulfilling_vendor_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
