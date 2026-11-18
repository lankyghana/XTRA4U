<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * The single place that answers "may this vendor use the Developer API?" and that withdraws
 * access when the answer becomes "no".
 *
 * The eligibility rule is the platform's existing vendor approval (`vendors.is_approved`) and
 * nothing else: no second approval, no separate developer account. It is evaluated LIVE on every
 * API request and every protected portal action, so a suspension takes effect immediately even if
 * nothing else has run yet. The cleanup below (revoke keys, disable webhooks) is the durable record
 * of that decision, applied by a model observer and re-applied by a scheduled reconciliation for any
 * approval change that bypassed Eloquent events (e.g. a raw query-builder update).
 *
 * Re-approving a vendor does NOT resurrect revoked keys or re-enable webhooks: those are deliberately
 * permanent, so a vendor that was cut off has to issue fresh credentials and re-enable endpoints
 * (which are re-validated) after it is approved again.
 */
class VendorApiAccess
{
    public function __construct(private readonly DeveloperAuditLogger $audit) {}

    public static function isEligible(?Vendor $vendor): bool
    {
        return $vendor !== null && $vendor->is_approved === true;
    }

    /** Null when the application may be managed/used; otherwise a developer-safe reason. */
    public function denialReason(DeveloperApplication $app): ?string
    {
        if (! $app->ownerIsApproved()) {
            return 'Your vendor account is not approved for Developer API access.';
        }

        if ($app->status !== DeveloperApplication::STATUS_ACTIVE) {
            return 'This application is not active.';
        }

        return null;
    }

    /**
     * Withdraw every form of API access held through a vendor's applications. Idempotent: running it
     * again changes nothing and writes no further audit rows.
     *
     * @return array{keys:int,webhooks:int,deliveries:int} totals actually changed
     */
    public function withdraw(int $vendorId, string $reason, string $actor = 'system'): array
    {
        $totals = ['keys' => 0, 'webhooks' => 0, 'deliveries' => 0];

        DB::transaction(function () use ($vendorId, $reason, $actor, &$totals) {
            $appIds = DeveloperApplication::withTrashed()->where('vendor_id', $vendorId)->pluck('id');

            foreach ($appIds as $appId) {
                $keys = DeveloperApiKey::query()->where('application_id', $appId)->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);

                $webhookIds = DeveloperWebhook::withTrashed()->where('application_id', $appId)->pluck('id');
                $webhooks = DeveloperWebhook::withTrashed()->where('application_id', $appId)->where('is_active', true)
                    ->update(['is_active' => false]);

                // Anything still waiting to be sent is cancelled; history (succeeded/failed) is kept.
                $deliveries = $webhookIds->isEmpty() ? 0 : DeveloperWebhookDelivery::query()
                    ->whereIn('webhook_id', $webhookIds)
                    ->whereIn('status', [DeveloperWebhookDelivery::PENDING, DeveloperWebhookDelivery::DELIVERING])
                    ->update(['status' => DeveloperWebhookDelivery::CANCELLED, 'claimed_at' => null]);

                if ($keys || $webhooks || $deliveries) {
                    $this->audit->log($actor, 'vendor.api_access_withdrawn', (int) $appId, [
                        'vendor_id' => $vendorId,
                        'reason' => $reason,
                        'credentials_revoked' => $keys,
                        'webhooks_disabled' => $webhooks,
                        'deliveries_cancelled' => $deliveries,
                    ]);
                }

                $totals['keys'] += $keys;
                $totals['webhooks'] += $webhooks;
                $totals['deliveries'] += $deliveries;
            }
        });

        return $totals;
    }

    /**
     * Safety net for approval changes that did not fire model events. Finds vendors that are
     * unapproved (or deleted) yet still own live keys or active webhooks, and withdraws their access.
     *
     * @return int number of vendors whose access was withdrawn
     */
    public function reconcile(): int
    {
        $vendorIds = DeveloperApplication::query()
            ->whereNotNull('vendor_id')
            ->where(fn ($q) => $q
                ->whereDoesntHave('vendor')
                ->orWhereHas('vendor', fn ($v) => $v->where('is_approved', false)))
            ->where(fn ($q) => $q
                ->whereHas('apiKeys', fn ($k) => $k->whereNull('revoked_at'))
                ->orWhereHas('webhooks', fn ($w) => $w->where('is_active', true)))
            ->pluck('vendor_id')
            ->unique();

        foreach ($vendorIds as $id) {
            $this->withdraw((int) $id, 'reconciled: vendor not approved');
        }

        return $vendorIds->count();
    }
}
