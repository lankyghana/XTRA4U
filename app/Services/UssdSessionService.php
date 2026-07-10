<?php

namespace App\Services;

use App\Models\UssdLog;
use App\Models\UssdSession;
use App\Models\UssdSubscriptionEvent;
use App\Services\Ussd\UssdConfig;
use App\Services\Ussd\UssdRouting;
use App\Services\Ussd\UssdSubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UssdSessionService
{
    /**
     * @deprecated Superseded by the admin-configurable `ussd_session_timeout_seconds`
     *             setting, read through UssdConfig::sessionTimeoutSeconds().
     *             Retained so any external caller keeps resolving.
     */
    const SESSION_TIMEOUT_MINUTES = 4;

    public function __construct(
        private readonly UssdConfig $config,
        private readonly UssdSubscriptionService $subscriptions,
    ) {}

    public function find(string $sessionId): ?UssdSession
    {
        return UssdSession::where('session_id', $sessionId)->first();
    }

    /**
     * Idle longer than the configured timeout.
     */
    public function isTimedOut(UssdSession $session): bool
    {
        $deadline = $session->updated_at?->addSeconds($this->config->sessionTimeoutSeconds());

        return $deadline !== null && $deadline->isPast();
    }

    /**
     * Open a session and reserve one of the vendor's paid sessions for it.
     *
     * The reservation happens up front, not on completion: the USSD endpoint is
     * reachable by anyone the gateway lets through, and billing only completed
     * flows would let abandoned sessions run a vendor's allowance down for free.
     *
     * Both writes share a transaction, so a failed insert never burns a session.
     *
     * @return UssdSession|null null when no session could be reserved
     */
    public function start(string $sessionId, string $phoneNumber, string $network, UssdRouting $routing): ?UssdSession
    {
        $subscription = $routing->subscription;

        if (! $subscription || ! $routing->vendor) {
            return null;
        }

        return DB::transaction(function () use ($sessionId, $phoneNumber, $network, $routing, $subscription) {
            if (! $this->subscriptions->reserveSession($subscription)) {
                return null;
            }

            return UssdSession::create([
                'session_id' => $sessionId,
                'vendor_id' => $routing->vendor->id,
                'ussd_subscription_id' => $subscription->id,
                'phone_number' => $phoneNumber,
                'network' => $network,
                'current_step' => 'MAIN_MENU',
                'status' => UssdSession::STATUS_ACTIVE,
                'request_count' => 1,
                'data' => [
                    // How many leading segments of `text` belonged to the dialled
                    // code. Recorded once: menu keypresses are numeric too, so it
                    // cannot be re-derived on later requests.
                    'prefix_length' => $routing->prefixLength,
                ],
            ]);
        });
    }

    /**
     * Count this request against the session's per-session request cap.
     *
     * @return bool false when the cap has been exceeded
     */
    public function registerRequest(UssdSession $session): bool
    {
        $session->increment('request_count');

        return $session->request_count <= $this->config->maxRequestsPerSession();
    }

    /**
     * Count an invalid entry.
     *
     * @return bool false when the retry cap has been exceeded
     */
    public function registerRetry(UssdSession $session): bool
    {
        $session->increment('retry_count');

        return $session->retry_count < $this->config->maxRetryAttempts();
    }

    /**
     * Close a session. The row is retained rather than deleted: a deleted row is
     * indistinguishable from a session that never existed, which is precisely
     * what makes a replayed session id look like a fresh dial.
     */
    public function endSession(UssdSession $session, string $status = UssdSession::STATUS_ENDED): void
    {
        if (! $session->isActive()) {
            return;
        }

        $session->forceFill([
            'status' => $status,
            'ended_at' => Carbon::now(),
        ])->save();
    }

    public function expireSession(UssdSession $session): void
    {
        $this->endSession($session, UssdSession::STATUS_EXPIRED);
    }

    /**
     * Reset a session back to the main menu, preserving its routing metadata.
     */
    public function resetSession(UssdSession $session, ?string $network = null): void
    {
        $session->forceFill([
            'current_step' => 'MAIN_MENU',
            'network' => $network ?? $session->network,
            'retry_count' => 0,
            'data' => ['prefix_length' => $session->getData('prefix_length', 0)],
        ])->save();
    }

    /**
     * Log the USSD interaction for auditing/debugging.
     */
    public function logInteraction(string $sessionId, array $requestData, string $responseData): void
    {
        UssdLog::create([
            'session_id' => $sessionId,
            'request_data' => $requestData,
            'response_data' => $responseData,
        ]);
    }

    /**
     * Record a rejected request against the audit trail.
     */
    public function logSecurityEvent(string $description, array $context = [], ?int $vendorId = null, ?string $ip = null): void
    {
        UssdSubscriptionEvent::create([
            'vendor_id' => $vendorId,
            'event' => UssdSubscriptionEvent::INVALID_USSD_REQUEST,
            'description' => $description,
            'context' => $context ?: null,
            'actor_type' => 'gateway',
            'ip_address' => $ip,
        ]);
    }
}
