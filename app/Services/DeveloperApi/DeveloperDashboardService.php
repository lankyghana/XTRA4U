<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\Developer;
use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApiRequest;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Real numbers only, every query constrained to the developer's OWN application ids.
 * Nothing is estimated or fabricated: an empty account shows zeros and empty lists.
 */
class DeveloperDashboardService
{
    public function forDeveloper(Developer $developer): array
    {
        $apps = $developer->applications()->with('plan')->orderBy('name')->get();
        $ids = $apps->pluck('id')->all();

        return [
            'applications' => $apps,
            'application_count' => $apps->count(),
            'active_keys' => $this->activeKeys($ids),
            'usage' => $this->usage($ids),
            'recent_requests' => $this->requests($ids)->latest('id')->limit(15)->get(),
            'recent_errors' => $this->requests($ids)->where('status_code', '>=', 400)->latest('id')->limit(10)->get(),
            'rate_limits' => $apps->map(fn (DeveloperApplication $a) => [
                'application' => $a,
                'per_minute' => $a->effectiveRateLimitPerMinute(),
                'source' => $a->rate_limit_per_minute ? 'application override' : ($a->plan && $a->plan->is_active ? 'plan: '.$a->plan->name : 'platform default'),
            ]),
            'webhooks' => $this->webhooks($ids),
        ];
    }

    /** @param list<int> $ids */
    private function activeKeys(array $ids): int
    {
        return DeveloperApiKey::query()
            ->whereIn('application_id', $ids)
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
            ->whereIn('webhook_id', DeveloperWebhook::withTrashed()->select('id')->whereIn('application_id', $ids))
            ->where('created_at', '>=', $since);

        return [
            'endpoints' => DeveloperWebhook::query()->whereIn('application_id', $ids)->count(),
            'active_endpoints' => DeveloperWebhook::query()->whereIn('application_id', $ids)->where('is_active', true)->count(),
            'deliveries_7d' => [
                'succeeded' => (clone $deliveries)->where('status', DeveloperWebhookDelivery::SUCCEEDED)->count(),
                'pending' => (clone $deliveries)->whereIn('status', [DeveloperWebhookDelivery::PENDING, DeveloperWebhookDelivery::DELIVERING])->count(),
                'failed' => (clone $deliveries)->where('status', DeveloperWebhookDelivery::FAILED)->count(),
            ],
            'recent' => DeveloperWebhookDelivery::query()
                ->with(['event:id,type,event_id', 'webhook'])
                ->whereIn('webhook_id', DeveloperWebhook::withTrashed()->select('id')->whereIn('application_id', $ids))
                ->latest('id')->limit(8)->get(),
        ];
    }
}
