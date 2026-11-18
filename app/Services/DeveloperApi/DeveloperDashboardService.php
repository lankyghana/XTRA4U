<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApiRequest;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\SandboxOrder;
use App\Models\Developer\SandboxPayment;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Real numbers only (sandbox activity is clearly separate and simulated), every query constrained to the signed-in VENDOR's own application ids.
 * Nothing is estimated or fabricated: an empty account shows zeros and empty lists.
 */
class DeveloperDashboardService
{
    public function forVendor(Vendor $vendor): array
    {
        $apps = DeveloperApplication::query()->where('vendor_id', $vendor->id)->with('plan')->orderBy('name')->get();
        $ids = $apps->pluck('id')->all();

        return [
            'applications' => $apps,
            'application_count' => $apps->count(),
            'active_keys' => $this->activeKeys($ids),
            'active_keys_by_environment' => [
                'sandbox' => $this->activeKeys($ids, 'sandbox'),
                'production' => $this->activeKeys($ids, 'production'),
            ],
            'usage' => $this->usage($ids),
            'recent_requests' => $this->requests($ids)->latest('id')->limit(15)->get(),
            'recent_errors' => $this->requests($ids)->where('status_code', '>=', 400)->latest('id')->limit(10)->get(),
            'rate_limits' => $apps->map(fn (DeveloperApplication $a) => [
                'application' => $a,
                'per_minute' => $a->effectiveRateLimitPerMinute(),
                'source' => $a->rate_limit_per_minute ? 'application override' : ($a->plan && $a->plan->is_active ? 'plan: '.$a->plan->name : 'platform default'),
            ]),
            // Production webhooks/usage and sandbox activity are reported separately and never mixed:
            // the sandbox has no sales, earnings, commissions or wallet, so nothing here feeds those.
            'webhooks' => $this->webhooks($ids),
            'usage_by_environment' => $this->usageByEnvironment($ids),
            'sandbox' => $this->sandbox($ids),
        ];
    }

    /**
     * Simulated activity only (developer_sandbox_* tables). Every query is bound to the given application ids.
     *
     * @param  list<int>  $ids
     */
    public function sandbox(array $ids): array
    {
        $orders = fn () => SandboxOrder::query()->whereIn('application_id', $ids);
        $sandboxEvents = DeveloperEvent::query()->select('id')->where('environment', 'sandbox')->whereIn('application_id', $ids);
        $deliveries = fn () => DeveloperWebhookDelivery::query()->whereIn('event_id', $sandboxEvents);

        $since = now()->subDays(7);

        return [
            'orders' => $orders()->count(),
            'payments' => SandboxPayment::query()->whereIn('application_id', $ids)->count(),
            'completed' => $orders()->where('status', 'completed')->count(),
            'failed' => $orders()->where('status', 'failed')->count(),
            'recent_orders' => $orders()->latest('id')->limit(8)->get(),
            'recent_failures' => $orders()->where('status', 'failed')->latest('id')->limit(5)->get(),
            'webhooks' => [
                'endpoints' => DeveloperWebhook::query()->whereIn('application_id', $ids)->where('environment', 'sandbox')->count(),
                'deliveries_7d' => [
                    'succeeded' => $deliveries()->where('created_at', '>=', $since)->where('status', DeveloperWebhookDelivery::SUCCEEDED)->count(),
                    'pending' => $deliveries()->where('created_at', '>=', $since)->whereIn('status', [DeveloperWebhookDelivery::PENDING, DeveloperWebhookDelivery::DELIVERING])->count(),
                    'failed' => $deliveries()->where('created_at', '>=', $since)->where('status', DeveloperWebhookDelivery::FAILED)->count(),
                ],
                'recent' => $deliveries()->with(['event:id,type,event_id', 'webhook'])->latest('id')->limit(8)->get(),
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array{sandbox:int, production:int}
     */
    private function usageByEnvironment(array $ids): array
    {
        $base = fn (string $env) => $this->requests($ids)->where('environment', $env)->where('created_at', '>=', now()->subDay())->count();

        return ['sandbox' => $base('sandbox'), 'production' => $base('production')];
    }

    /** @param list<int> $ids */
    private function activeKeys(array $ids, ?string $environment = null): int
    {
        return DeveloperApiKey::query()
            ->whereIn('application_id', $ids)
            ->when($environment, fn ($q) => $q->where('environment', $environment))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    /** @param list<int> $ids */
    private function requests(array $ids): Builder
    {
        return DeveloperApiRequest::query()->whereIn('application_id', $ids);
    }

    /** @param list<int> $ids */
    private function usage(array $ids): array
    {
        $out = [];
        foreach (['24h' => now()->subDay(), '7d' => now()->subDays(7), '30d' => now()->subDays(30)] as $label => $since) {
            $base = $this->requests($ids)->where('created_at', '>=', $since);
            $total = (clone $base)->count();
            $failed = (clone $base)->where('status_code', '>=', 400)->count();
            $out[$label] = ['total' => $total, 'successful' => $total - $failed, 'failed' => $failed];
        }

        return $out;
    }

    /** @param list<int> $ids */
    private function webhooks(array $ids): array
    {
        $since = now()->subDays(7);
        $deliveries = DeveloperWebhookDelivery::query()
            ->whereIn('webhook_id', DeveloperWebhook::withTrashed()->select('id')->whereIn('application_id', $ids)->where('environment', 'production'))
            ->where('created_at', '>=', $since);

        return [
            'endpoints' => DeveloperWebhook::query()->whereIn('application_id', $ids)->where('environment', 'production')->count(),
            'active_endpoints' => DeveloperWebhook::query()->whereIn('application_id', $ids)->where('environment', 'production')->where('is_active', true)->count(),
            'deliveries_7d' => [
                'succeeded' => (clone $deliveries)->where('status', DeveloperWebhookDelivery::SUCCEEDED)->count(),
                'pending' => (clone $deliveries)->whereIn('status', [DeveloperWebhookDelivery::PENDING, DeveloperWebhookDelivery::DELIVERING])->count(),
                'failed' => (clone $deliveries)->where('status', DeveloperWebhookDelivery::FAILED)->count(),
            ],
            'recent' => DeveloperWebhookDelivery::query()
                ->with(['event:id,type,event_id', 'webhook'])
                ->whereIn('webhook_id', DeveloperWebhook::withTrashed()->select('id')->whereIn('application_id', $ids)->where('environment', 'production'))
                ->latest('id')->limit(8)->get(),
        ];
    }
}
