<?php

namespace App\Services\Ussd;

use App\Jobs\SendUssdSubscriptionNotification;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns every state transition of a UssdSubscription.
 *
 * Nothing outside this class may write `status`, `ussd_code`, or
 * `current_for_vendor_id` — those three fields together encode the vendor's
 * single subscription slot, and keeping their mutation in one place is what
 * makes the unique index on current_for_vendor_id enforceable.
 */
class UssdSubscriptionService
{
    public function __construct(private readonly UssdCodeGenerator $codeGenerator) {}

    /**
     * Promote a verified-paid subscription to active.
     *
     * Idempotent: calling this twice for the same subscription (duplicate
     * webhook + redirect callback, gateway retry) activates it exactly once.
     *
     * @return bool true if the subscription is active when this returns
     */
    public function activate(UssdSubscription $subscription, array $gatewayResponse = []): bool
    {
        return DB::transaction(function () use ($subscription, $gatewayResponse) {
            // Serialise every activation for this vendor. Two concurrent
            // callbacks would otherwise both read "no live subscription" and
            // both try to claim current_for_vendor_id.
            Vendor::whereKey($subscription->vendor_id)->lockForUpdate()->first();

            $fresh = UssdSubscription::whereKey($subscription->getKey())->lockForUpdate()->first();

            if (! $fresh) {
                return false;
            }

            if ($fresh->status === UssdSubscription::STATUS_ACTIVE) {
                return true; // Already processed by a concurrent callback.
            }

            if ($fresh->status !== UssdSubscription::STATUS_PENDING_PAYMENT
                && $fresh->status !== UssdSubscription::STATUS_PAID) {
                Log::warning('USSD subscription activation refused: unexpected status', [
                    'subscription_id' => $fresh->id,
                    'status' => $fresh->status,
                ]);

                return false;
            }

            $plan = $fresh->plan()->withTrashed()->firstOrFail();

            // Carry unused sessions over from the subscription being replaced,
            // then release its slot and its code before the new row claims them.
            $superseded = UssdSubscription::query()
                ->where('current_for_vendor_id', $fresh->vendor_id)
                ->where('id', '!=', $fresh->id)
                ->lockForUpdate()
                ->first();

            $carriedOver = 0;

            if ($superseded) {
                $carriedOver = $superseded->remaining_sessions;
                $this->releaseSlot($superseded, UssdSubscription::STATUS_CANCELLED, [
                    'superseded_by_id' => $fresh->id,
                    'cancelled_at' => now(),
                ]);
            }

            $ussdCode = $this->codeGenerator->generateFor($fresh->vendor_id, $fresh->extension_code);

            $fresh->forceFill([
                'status' => UssdSubscription::STATUS_ACTIVE,
                'ussd_code' => $ussdCode,
                'current_for_vendor_id' => $fresh->vendor_id,
                'total_sessions' => $fresh->total_sessions + $carriedOver,
                'carried_over_sessions' => $carriedOver,
                'starts_at' => now(),
                'expires_at' => now()->addDays($plan->duration_days),
                'activated_at' => now(),
                'gateway_response' => $gatewayResponse ?: $fresh->gateway_response,
            ])->save();

            $this->recordEvent($fresh, UssdSubscriptionEvent::SUBSCRIPTION_ACTIVATED, "Subscription activated on plan \"{$plan->name}\".", [
                'carried_over_sessions' => $carriedOver,
                'total_sessions' => $fresh->total_sessions,
                'expires_at' => $fresh->expires_at?->toIso8601String(),
                'superseded_subscription_id' => $superseded?->id,
            ]);

            $this->recordEvent($fresh, UssdSubscriptionEvent::USSD_CODE_GENERATED, "USSD code {$ussdCode} issued.", [
                'ussd_code' => $ussdCode,
            ]);

            if ($superseded) {
                $this->recordEvent($superseded, UssdSubscriptionEvent::SUBSCRIPTION_UPGRADED, 'Subscription superseded by a new purchase.', [
                    'superseded_by_id' => $fresh->id,
                    'sessions_carried_over' => $carriedOver,
                ]);
            }

            // afterCommit: the vendor must never receive "your code is live"
            // for a transaction that then rolls back.
            SendUssdSubscriptionNotification::dispatch($fresh->id, SendUssdSubscriptionNotification::TYPE_ACTIVATED)
                ->afterCommit();

            return true;
        });
    }

    /**
     * Consume one session. Atomic and floor-protected: the conditional update
     * cannot drive used_sessions past total_sessions, so concurrent USSD
     * requests can never over-consume a vendor's allowance.
     *
     * @return bool true if a session was successfully reserved
     */
    public function reserveSession(UssdSubscription $subscription): bool
    {
        $reserved = UssdSubscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', UssdSubscription::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->whereColumn('used_sessions', '<', 'total_sessions')
            ->increment('used_sessions');

        if ($reserved === 0) {
            return false;
        }

        $subscription->refresh();

        if (! $subscription->hasRemainingSessions()) {
            $this->recordEvent($subscription, UssdSubscriptionEvent::SESSIONS_EXHAUSTED, 'Session allowance exhausted.', [
                'total_sessions' => $subscription->total_sessions,
            ]);
        }

        $this->dispatchUsageAlerts($subscription);

        return true;
    }

    /**
     * Fire the 80/90/95/100% alerts, each exactly once per subscription.
     *
     * markThresholdNotified() persists the threshold before the next iteration,
     * so a crash mid-loop cannot re-send an alert already dispatched.
     */
    private function dispatchUsageAlerts(UssdSubscription $subscription): void
    {
        foreach ($subscription->pendingUsageAlerts() as $threshold) {
            $subscription->markThresholdNotified($threshold);

            SendUssdSubscriptionNotification::dispatch(
                $subscription->id,
                SendUssdSubscriptionNotification::TYPE_USAGE_ALERT,
                ['threshold' => $threshold],
            );
        }
    }

    /**
     * Mark every past-due subscription expired, releasing its slot and code.
     *
     * @return int number of subscriptions expired
     */
    public function expireDue(): int
    {
        $expired = 0;

        UssdSubscription::dueForExpiry()->select('id')->chunkById(100, function ($rows) use (&$expired) {
            foreach ($rows as $row) {
                if ($this->expire($row->id)) {
                    $expired++;
                }
            }
        });

        return $expired;
    }

    public function expire(int $subscriptionId): bool
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = UssdSubscription::whereKey($subscriptionId)->lockForUpdate()->first();

            if (! $subscription || ! $subscription->occupiesSlot()) {
                return false;
            }

            $this->releaseSlot($subscription, UssdSubscription::STATUS_EXPIRED);

            $this->recordEvent($subscription, UssdSubscriptionEvent::SUBSCRIPTION_EXPIRED, 'Subscription expired.', [
                'expired_at' => now()->toIso8601String(),
                'unused_sessions' => $subscription->remaining_sessions,
            ]);

            SendUssdSubscriptionNotification::dispatch($subscription->id, SendUssdSubscriptionNotification::TYPE_EXPIRED)
                ->afterCommit();

            return true;
        });
    }

    /**
     * Warn vendors whose subscription lapses within $days. The flag lives in
     * metadata rather than a column because it is scoped to this subscription:
     * a renewal creates a new row and so warns again next cycle.
     *
     * @return int number of vendors warned
     */
    public function notifyExpiringSoon(int $days = 3): int
    {
        $warned = 0;

        UssdSubscription::active()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->chunkById(100, function ($subscriptions) use (&$warned) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->getExpiryWarningSent()) {
                        continue;
                    }

                    $subscription->markExpiryWarningSent();

                    SendUssdSubscriptionNotification::dispatch(
                        $subscription->id,
                        SendUssdSubscriptionNotification::TYPE_EXPIRING,
                    );

                    $warned++;
                }
            });

        return $warned;
    }

    public function cancel(UssdSubscription $subscription, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $fresh = UssdSubscription::whereKey($subscription->getKey())->lockForUpdate()->first();

            if (! $fresh || ! $fresh->occupiesSlot()) {
                return false;
            }

            $this->releaseSlot($fresh, UssdSubscription::STATUS_CANCELLED, ['cancelled_at' => now()]);

            $this->recordEvent($fresh, UssdSubscriptionEvent::SUBSCRIPTION_CANCELLED, $reason ?? 'Subscription cancelled.');

            return true;
        });
    }

    public function suspend(UssdSubscription $subscription, string $reason): bool
    {
        // Suspension keeps the slot and the code: the vendor still "has" the
        // subscription, it simply stops serving traffic until reinstated.
        $subscription->forceFill(['status' => UssdSubscription::STATUS_SUSPENDED])->save();

        $this->recordEvent($subscription, UssdSubscriptionEvent::SUBSCRIPTION_SUSPENDED, $reason);

        return true;
    }

    /**
     * Move a subscription out of the live states: free the vendor's slot and
     * archive its USSD code so the code can be re-issued on a future purchase.
     */
    private function releaseSlot(UssdSubscription $subscription, string $status, array $extra = []): void
    {
        $metadata = $subscription->metadata ?? [];
        if ($subscription->ussd_code) {
            $metadata['released_ussd_code'] = $subscription->ussd_code;
        }

        $subscription->forceFill(array_merge([
            'status' => $status,
            'ussd_code' => null,
            'current_for_vendor_id' => null,
            'metadata' => $metadata,
        ], $extra))->save();
    }

    private function recordEvent(UssdSubscription $subscription, string $event, string $description, array $context = []): void
    {
        UssdSubscriptionEvent::create([
            'ussd_subscription_id' => $subscription->id,
            'vendor_id' => $subscription->vendor_id,
            'ussd_plan_id' => $subscription->ussd_plan_id,
            'event' => $event,
            'description' => $description,
            'context' => $context ?: null,
            'actor_type' => 'system',
        ]);
    }
}
