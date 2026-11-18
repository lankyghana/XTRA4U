<?php

namespace App\Services\DeveloperApi\Sandbox;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\SandboxOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retention for sandbox data. Deliberately narrow:
 *
 *  - OFF unless a retention is configured (config developer_api.sandbox.retention_days) or passed explicitly.
 *  - Never younger than the configured safety floor (min_retention_days), whatever is asked for.
 *  - Touches ONLY: developer_sandbox_* rows, developer_events rows with environment = 'sandbox' (plus their
 *    deliveries/attempts), and api_quotes rows with environment = 'sandbox'. Every query carries that filter.
 *  - Never touches orders, transactions, wallets, API keys, webhooks, request logs or audit events.
 *  - Idempotent: a second run over the same cutoff finds nothing.
 */
class SandboxCleanup
{
    /** @return ?int the retention that would apply (floor enforced), or null when cleanup is disabled */
    public function effectiveDays(?int $requested = null): ?int
    {
        $days = $requested ?? config('developer_api.sandbox.retention_days');

        if ($days === null || (int) $days <= 0) {
            return null;
        }

        return max((int) $days, (int) config('developer_api.sandbox.min_retention_days', 7));
    }

    /**
     * @return array{enabled:bool, days:?int, cutoff:?string, dry_run:bool, orders:int, events:int, deliveries:int, quotes:int}
     */
    public function run(?int $requestedDays = null, bool $dryRun = false): array
    {
        $days = $this->effectiveDays($requestedDays);
        $result = ['enabled' => $days !== null, 'days' => $days, 'cutoff' => null, 'dry_run' => $dryRun, 'orders' => 0, 'events' => 0, 'deliveries' => 0, 'quotes' => 0];

        if ($days === null) {
            return $result;
        }

        $cutoff = now()->subDays($days);
        $result['cutoff'] = $cutoff->toIso8601String();
        $batch = max(1, (int) config('developer_api.sandbox.prune_batch', 500));

        // "Last activity" decides, so an order that was simulated recently is never deleted for being old.
        $old = fn () => SandboxOrder::query()->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            $ids = $old()->pluck('id');
            $result['orders'] = $ids->count();
            foreach ($ids->chunk($batch) as $chunk) {
                $eventIds = $this->eventIds($chunk->all());
                $result['events'] += count($eventIds);
                $result['deliveries'] += $eventIds === [] ? 0 : DB::table('developer_webhook_deliveries')->whereIn('event_id', $eventIds)->count();
            }
            $result['quotes'] = $this->oldQuotes($cutoff)->count();
        } else {
            while (($ids = $old()->orderBy('id')->limit($batch)->pluck('id'))->isNotEmpty()) {
                $this->deleteBatch($ids->all(), $result);
            }
            $result['quotes'] = $this->deleteOldQuotes($cutoff, $batch);
        }

        Log::info('Developer sandbox cleanup', ['dry_run' => $dryRun] + array_diff_key($result, ['enabled' => 1, 'dry_run' => 1]));

        return $result;
    }

    /**
     * @param  list<int>  $orderIds
     * @param  array<string,mixed>  $result
     */
    private function deleteBatch(array $orderIds, array &$result): void
    {
        DB::transaction(function () use ($orderIds, &$result) {
            $eventIds = $this->eventIds($orderIds);

            if ($eventIds !== []) {
                $deliveryIds = DB::table('developer_webhook_deliveries')->whereIn('event_id', $eventIds)->pluck('id')->all();
                if ($deliveryIds !== []) {
                    DB::table('developer_webhook_attempts')->whereIn('delivery_id', $deliveryIds)->delete();
                    $result['deliveries'] += DB::table('developer_webhook_deliveries')->whereIn('id', $deliveryIds)->delete();
                }
                // environment = 'sandbox' again: production events can never match, whatever their order_id.
                $result['events'] += DB::table('developer_events')->where('environment', 'sandbox')->whereIn('id', $eventIds)->delete();
            }

            DB::table('developer_sandbox_order_events')->whereIn('sandbox_order_id', $orderIds)->delete();
            DB::table('developer_sandbox_payments')->whereIn('sandbox_order_id', $orderIds)->delete();
            $result['orders'] += DB::table('developer_sandbox_orders')->whereIn('id', $orderIds)->delete();
        });
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function eventIds(array $orderIds): array
    {
        return DB::table('developer_events')
            ->where('environment', 'sandbox')
            ->whereIn('order_id', $orderIds)
            ->pluck('id')->all();
    }

    /** @return Builder<ApiQuote> */
    private function oldQuotes(CarbonInterface $cutoff): Builder
    {
        return ApiQuote::query()->where('environment', 'sandbox')->where('created_at', '<', $cutoff);
    }

    private function deleteOldQuotes(CarbonInterface $cutoff, int $batch): int
    {
        $deleted = 0;
        while (($ids = $this->oldQuotes($cutoff)->orderBy('id')->limit($batch)->pluck('id'))->isNotEmpty()) {
            $deleted += ApiQuote::query()->where('environment', 'sandbox')->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }
}
