<?php

namespace App\Services\UtilityBills;

use App\Models\AdminNotification;
use App\Models\UtilityBillIncident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Groups provider-level problems into incidents so Admin gets ONE actionable
 * alert per issue (plus at most one "still ongoing" reminder per
 * `incident_realert_minutes` while it keeps happening, and one "recovered"
 * notice), never one per affected order.
 *
 * Only provider-wide conditions are grouped. Order-specific problems (payment
 * no longer confirmed, provider rejected that bill, reference conflict, a
 * provider-confirmed failed/refunded bill) keep their own per-order alert, and
 * every order keeps its own status, error and event history regardless.
 *
 * Cross-process safe: a unique index allows one active incident per
 * (provider, category, scope); membership is a composite primary key; and each
 * alert is claimed with a conditional UPDATE so concurrent workers never both
 * send it.
 */
class UtilityBillIncidents
{
    public const PROVIDER = 'kingflexy';

    /**
     * label: what Admin sees; alert_after: occurrences before the first alert (a single
     * timeout is noise, a key rejection is not); outage: counts as a provider outage
     * (as opposed to a capacity backlog) on the pipeline panel.
     */
    public const CATEGORIES = [
        'provider_unreachable' => ['label' => 'KiNG FLEXY not responding (timeouts)', 'alert_after' => 3, 'outage' => true],
        'provider_unavailable' => ['label' => 'KiNG FLEXY unavailable (server errors)', 'alert_after' => 3, 'outage' => true],
        'provider_auth' => ['label' => 'KiNG FLEXY rejected the API key', 'alert_after' => 1, 'outage' => true],
        'provider_rate_limited' => ['label' => 'KiNG FLEXY rate limiting (HTTP 429)', 'alert_after' => 3, 'outage' => false],
        'provider_wallet_low' => ['label' => 'KiNG FLEXY wallet too low', 'alert_after' => 1, 'outage' => true],
        'service_disabled' => ['label' => 'Service or biller disabled by KiNG FLEXY', 'alert_after' => 1, 'outage' => true],
        'status_sync_unavailable' => ['label' => 'KiNG FLEXY status checks failing', 'alert_after' => 3, 'outage' => true],
        'status_unresolved' => ['label' => 'Provider status unresolved', 'alert_after' => 1, 'outage' => false],
        'delayed_orders' => ['label' => 'Paid bills not finished in time', 'alert_after' => 1, 'outage' => false],
    ];

    /** Pay-side incidents a successful provider payment proves are over. */
    public const RECOVERED_BY_PAY_SUCCESS = ['provider_unreachable', 'provider_unavailable', 'provider_rate_limited', 'provider_auth'];

    /** Incidents a successful status answer proves are over. */
    public const RECOVERED_BY_STATUS_SUCCESS = ['status_sync_unavailable', 'provider_auth'];

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category]['label'] ?? Str::headline($category);
    }

    /** Incident category for a provider failure, or null when it is order-specific / not provider-wide. */
    public static function categoryForSubmit(string $errorCode, bool $local = false): ?string
    {
        if ($local) {
            return null;    // our own budget: a capacity backlog, never an incident
        }

        return match ($errorCode) {
            'timeout' => 'provider_unreachable',
            'upstream', 'malformed' => 'provider_unavailable',
            'auth', 'not_configured' => 'provider_auth',
            'rate_limited' => 'provider_rate_limited',
            'insufficient_balance' => 'provider_wallet_low',
            'disabled' => 'service_disabled',
            default => null,
        };
    }

    /**
     * Record one occurrence, attach the order (if any), and alert when this incident first
     * becomes alert-worthy (or a reminder is due).
     *
     * @param  ?string  $firstOrderNote  order-level description used in the FIRST alert only
     */
    public function record(string $category, ?int $utilityBillOrderId = null, ?string $detail = null, string $scope = '', bool $notify = true, ?string $firstOrderNote = null): UtilityBillIncident
    {
        $incident = $this->openOrJoin($category, $scope);
        $now = now();

        UtilityBillIncident::query()->whereKey($incident->id)->update([
            'occurrences' => DB::raw('occurrences + 1'),
            'last_detected_at' => $now,
            'last_detail' => $detail !== null ? Str::limit($detail, 250, '') : DB::raw('last_detail'),
            'updated_at' => $now,
        ]);

        if ($utilityBillOrderId !== null) {
            $added = DB::table('utility_bill_incident_orders')->insertOrIgnore([
                'utility_bill_incident_id' => $incident->id,
                'utility_bill_order_id' => $utilityBillOrderId,
                'first_seen_at' => $now,
            ]);
            if ($added === 1) {
                UtilityBillIncident::query()->whereKey($incident->id)->increment('affected_orders');
            }
        }

        $incident->refresh();
        if ($notify) {
            $this->alertIfDue($incident, $firstOrderNote);
        }

        return $incident;
    }

    /**
     * Close the active incidents of these categories (optionally one scope), sending one
     * "recovered" notice for each that had alerted.
     *
     * @param  list<string>  $categories
     * @return list<int> ids of incidents this call recovered
     */
    public function recover(array $categories, string $how, ?string $scope = null): array
    {
        $active = UtilityBillIncident::query()
            ->where('provider', self::PROVIDER)
            ->whereIn('category', $categories)
            ->where('active_key', UtilityBillIncident::STATE_ACTIVE)
            ->when($scope !== null, fn ($q) => $q->where('scope', $scope))
            ->get();

        $recovered = [];
        foreach ($active as $incident) {
            $closed = UtilityBillIncident::query()->whereKey($incident->id)->where('active_key', UtilityBillIncident::STATE_ACTIVE)->update([
                'active_key' => null,
                'state' => UtilityBillIncident::STATE_RECOVERED,
                'recovered_at' => now(),
                'recovery_note' => Str::limit($how, 250, ''),
                'updated_at' => now(),
            ]) === 1;

            if (! $closed) {
                continue;   // another process recovered it
            }

            $recovered[] = $incident->id;
            Log::info('utility_bills.incident.recovered', ['incident_id' => $incident->id, 'category' => $incident->category, 'how' => $how]);

            if ($incident->last_alerted_at !== null) {
                $minutes = (int) floor($incident->first_detected_at->diffInSeconds(now(), true) / 60);
                $this->notify($incident, 'Recovered: '.$incident->label(),
                    $incident->label().' has recovered ('.$how.') after about '.$minutes.' min. '
                    .$incident->affected_orders.' '.Str::plural('order', $incident->affected_orders).' were affected; each keeps its own status and history in Utility Bill Sales.');
            }
        }

        return $recovered;
    }

    public function active(): Collection
    {
        return UtilityBillIncident::query()->where('active_key', UtilityBillIncident::STATE_ACTIVE)->orderBy('first_detected_at')->get();
    }

    /**
     * The order belongs to an active provider-wide incident that has ALREADY alerted Admin, so
     * that alert explains its delay. (One still below its alert threshold explains nothing.)
     */
    public function explainedByActiveOutage(int $utilityBillOrderId): bool
    {
        return DB::table('utility_bill_incident_orders as m')
            ->join('utility_bill_incidents as i', 'i.id', '=', 'm.utility_bill_incident_id')
            ->where('m.utility_bill_order_id', $utilityBillOrderId)
            ->where('i.active_key', UtilityBillIncident::STATE_ACTIVE)
            ->whereNotNull('i.last_alerted_at')
            ->whereIn('i.category', array_keys(array_filter(self::CATEGORIES, fn ($c) => $c['outage'])))
            ->exists();
    }

    public function hasActiveOutage(): bool
    {
        return UtilityBillIncident::query()
            ->where('active_key', UtilityBillIncident::STATE_ACTIVE)
            ->whereIn('category', array_keys(array_filter(self::CATEGORIES, fn ($c) => $c['outage'])))
            ->exists();
    }

    // ------------------------------------------------------------------

    private function openOrJoin(string $category, string $scope): UtilityBillIncident
    {
        $scope = Str::limit($scope, 60, '');

        for ($try = 0; $try < 3; $try++) {
            $now = now();
            DB::table('utility_bill_incidents')->insertOrIgnore([
                'provider' => self::PROVIDER,
                'category' => $category,
                'scope' => $scope,
                'active_key' => UtilityBillIncident::STATE_ACTIVE,
                'state' => UtilityBillIncident::STATE_ACTIVE,
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $incident = UtilityBillIncident::query()
                ->where('provider', self::PROVIDER)->where('category', $category)->where('scope', $scope)
                ->where('active_key', UtilityBillIncident::STATE_ACTIVE)
                ->first();

            if ($incident) {
                return $incident;
            }
            // Recovered between our insert attempt and read: open a fresh one.
        }

        throw new \RuntimeException('Could not open a utility bill incident.');
    }

    private function alertIfDue(UtilityBillIncident $incident, ?string $firstOrderNote): void
    {
        $alertAfter = (int) (self::CATEGORIES[$incident->category]['alert_after'] ?? 1);

        // First alert: exactly one process wins the claim.
        $first = UtilityBillIncident::query()->whereKey($incident->id)
            ->whereNull('last_alerted_at')
            ->where('occurrences', '>=', $alertAfter)
            ->update(['last_alerted_at' => now(), 'alerts_sent' => DB::raw('alerts_sent + 1')]) === 1;

        if ($first) {
            $this->notify($incident, $this->title($incident), trim(($firstOrderNote ? $firstOrderNote.' ' : '').$this->summary($incident)));

            return;
        }

        // Reminder: still happening, and the last alert is older than the window.
        $window = max(1, (int) config('utility_bills.incident_realert_minutes', 60));
        $reminder = UtilityBillIncident::query()->whereKey($incident->id)
            ->where('active_key', UtilityBillIncident::STATE_ACTIVE)
            ->whereNotNull('last_alerted_at')
            ->where('last_alerted_at', '<=', now()->subMinutes($window))
            ->update(['last_alerted_at' => now(), 'alerts_sent' => DB::raw('alerts_sent + 1')]) === 1;

        if ($reminder) {
            $this->notify($incident, 'Still ongoing: '.$incident->label(), $this->summary($incident->refresh()));
        }
    }

    private function title(UtilityBillIncident $incident): string
    {
        return match ($incident->category) {
            'delayed_orders' => 'Utility Bill still processing',
            'status_unresolved' => 'Utility Bill provider status unresolved',
            default => 'Utility Bills incident: '.$incident->label(),
        };
    }

    private function summary(UtilityBillIncident $incident): string
    {
        $oldest = $incident->orders()->orderBy('utility_bill_orders.id')->first(['utility_bill_orders.id', 'public_ref']);

        return $incident->label().' since '.$incident->first_detected_at->format('d M H:i')
            .' (last seen '.$incident->last_detected_at->format('H:i').', '.$incident->occurrences.' '.Str::plural('occurrence', $incident->occurrences).'). '
            .$incident->affected_orders.' paid '.Str::plural('order', $incident->affected_orders).' affected'
            .($oldest ? ', oldest '.$oldest->public_ref : '').'. Customers have paid; orders keep retrying or waiting with their existing references. '
            .'Further occurrences are grouped into this incident; see Utility Bill Sales > Incidents.';
    }

    private function notify(UtilityBillIncident $incident, string $title, string $message): void
    {
        try {
            AdminNotification::create([
                'type' => 'utility_bill_incident',
                'title' => Str::limit($title, 250, ''),
                'message' => $message,
                'data' => ['incident_id' => $incident->id, 'category' => $incident->category, 'scope' => $incident->scope],
            ]);
        } catch (\Throwable $e) {
            Log::warning('utility_bills.notify.admin_failed', ['error' => class_basename($e)]);
        }
    }
}
