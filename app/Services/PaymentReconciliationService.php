<?php

namespace App\Services;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\ResultCheckerOrder;
use App\Models\UssdSubscription;
use App\Models\WalletTopup;
use App\Services\Ussd\UssdSubscriptionPurchaseService;
use App\Support\PaymentIntegrity;
use App\Support\PaymentVerificationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single centralized entry point for discovering and safely completing
 * payments that a customer's browser and the gateway's webhook both failed
 * to resolve — Phase 3 of the payment-reconciliation hardening.
 *
 * This service does not talk to any gateway API directly and does not
 * contain any provider-specific logic — it only ever calls
 * GatewayManager::verifyCollectionWithGateway() (Phase 1) against the
 * payable's OWN stored payment_gateway, classifies the result via
 * PaymentVerificationState (Phase 1), and — for a confirmed outcome —
 * invokes the exact same completion pipeline the browser callback and
 * gateway webhook already use for that payable type. It never initializes
 * a new charge, never generates a new reference, and never holds a database
 * lock across the network call to the gateway.
 *
 * Supported payables (see the Phase 3 audit for how this list was derived):
 * Order, AfaRegistration, ResultCheckerOrder, UssdSubscription, WalletTopup.
 */
class PaymentReconciliationService
{
    // Outcomes reconcile() can return. Every caller (payments:reconcile,
    // payments:cleanup) tallies on these exact strings.
    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_LEFT_PENDING = 'left_pending';

    public const OUTCOME_NO_GATEWAY = 'no_gateway';

    public const OUTCOME_INTEGRITY_MISMATCH = 'integrity_mismatch';

    public const OUTCOME_MAX_WINDOW_EXCEEDED = 'manual_review';

    /**
     * Minimum age (minutes) before a record is even eligible for its FIRST
     * automatic reconciliation attempt. Gives the immediate browser-return
     * verification and the gateway's own webhook a fair chance to resolve it
     * first — reconciliation is the fallback, not the primary path. Shorter
     * than the analogous 10-minute grace window `withdrawals:retry-stuck`
     * already uses in this codebase (routes/console.php) because a stuck
     * *customer* payment should recover much faster than a stuck payout;
     * there is no equivalent existing constant to reuse here, so this value
     * is deliberately documented rather than copied.
     */
    public const MIN_AGE_MINUTES = 5;

    /**
     * Backoff schedule (minutes) indexed by number of attempts ALREADY made.
     * attemptsMade=0 (never checked) uses index 0 (5 min after MIN_AGE_MINUTES
     * has passed, i.e. effectively "as soon as eligible"); each subsequent
     * attempt waits longer, tapering to a steady state so a stubbornly
     * unresolved record doesn't get hammered forever. Rationale: fast enough
     * that a real success is discovered within the hour for most cases,
     * slow enough by the later attempts that a genuinely dead reference
     * doesn't cost a gateway call every few minutes for days.
     */
    private const BACKOFF_MINUTES = [5, 10, 30, 60, 120];

    /** Once past the explicit schedule above, check every 4 hours. */
    private const STEADY_STATE_MINUTES = 240;

    /**
     * Maximum time since creation that a record remains eligible for
     * AUTOMATIC reconciliation. Deliberately generous — 72 hours, three
     * times the old (unsafe) blind-cancel window Phase 2 replaced — because
     * the cost of waiting longer is trivial (one more gateway query) while
     * the cost of giving up too early is a payment that silently never
     * self-heals. Past this window the record is "parked" (never
     * cancelled/failed on this basis alone — see OUTCOME_MAX_WINDOW_EXCEEDED)
     * and only reachable again via `payments:reconcile --reference=`.
     */
    public const MAX_AUTOMATIC_WINDOW_HOURS = 72;

    public function __construct(
        private GatewayManager $gatewayManager,
        private PaymentService $paymentService,
        private \App\Services\Payments\PaymentIntegrityGuard $integrityGuard,
        private AfaPaymentService $afaPaymentService,
        private ResultCheckerService $resultCheckerService,
        private UssdSubscriptionPurchaseService $ussdSubscriptionPurchaseService,
        private WalletService $walletService,
    ) {}

    public function nextAttemptDelayMinutes(int $attemptsMade): int
    {
        return self::BACKOFF_MINUTES[$attemptsMade] ?? self::STEADY_STATE_MINUTES;
    }

    /**
     * Reconcile one payable. Dispatches by model class — every branch here
     * follows the identical verify -> classify -> (lock -> re-check -> act)
     * shape; only which existing completion pipeline gets invoked differs.
     */
    public function reconcile(Model $payable): string
    {
        return match (true) {
            $payable instanceof Order => $this->reconcileOrder($payable),
            $payable instanceof AfaRegistration => $this->reconcileAfaRegistration($payable),
            $payable instanceof ResultCheckerOrder => $this->reconcileResultCheckerOrder($payable),
            $payable instanceof UssdSubscription => $this->reconcileUssdSubscription($payable),
            $payable instanceof WalletTopup => $this->reconcileWalletTopup($payable),
            default => throw new \InvalidArgumentException('Unsupported payable type: '.get_class($payable)),
        };
    }

    // -----------------------------------------------------------------
    // Shared bookkeeping
    // -----------------------------------------------------------------

    /**
     * Record that an attempt happened and schedule (or park) the next one.
     * Called for every outcome except a fresh COMPLETED (and, for USSD, an
     * already-ACTIVE result) — those leave the payable table's normal state
     * with nothing left to reconcile. Never touches payment_status/status —
     * only the reconciliation_* bookkeeping columns.
     *
     * Records the attempt and returns the EFFECTIVE outcome — which may be
     * upgraded from the raw outcome to OUTCOME_MAX_WINDOW_EXCEEDED
     * ('manual_review') when this record is being parked, so callers report
     * accurately rather than showing a parked record as merely "left
     * pending" forever.
     */
    private function recordAttempt(Model $payable, string $outcome, ?string $note = null): string
    {
        $attempts = ((int) $payable->reconciliation_attempts) + 1;
        $ageHours = $payable->created_at?->diffInHours(now()) ?? 0;

        // NO_GATEWAY and INTEGRITY_MISMATCH are already specific, actionable
        // outcomes on their own — never retrying them automatically again is
        // correct, but the reported outcome should stay exactly that, not be
        // collapsed into the generic "manual_review" label. Only a record
        // that has simply been PENDING/UNKNOWN for too long — where there is
        // no other reason to name — gets relabelled manual_review once it
        // ages out.
        // OUTCOME_MAX_WINDOW_EXCEEDED is included because payment integrity
        // failing as "unprovable" (a record whose financial terms cannot be
        // established from data we still hold) is a permanent condition — no
        // number of additional gateway queries will ever produce the missing
        // expected amount. Park it for a human immediately instead of
        // re-querying it on the backoff schedule for three days.
        $neverRetryAutomatically = in_array($outcome, [
            self::OUTCOME_NO_GATEWAY,
            self::OUTCOME_INTEGRITY_MISMATCH,
            self::OUTCOME_MAX_WINDOW_EXCEEDED,
        ], true);
        $agedOut = $outcome === self::OUTCOME_LEFT_PENDING && $ageHours >= self::MAX_AUTOMATIC_WINDOW_HOURS;

        $updates = [
            'reconciliation_attempts' => $attempts,
            'last_reconciliation_at' => now(),
            'reconciliation_note' => $note,
        ];

        if ($neverRetryAutomatically || $agedOut) {
            // Park it: far-future next_reconciliation_at means it will never
            // be picked up by the automatic due-query again, but remains
            // fully reachable via `payments:reconcile --reference=`.
            $updates['next_reconciliation_at'] = now()->addYears(10);
            $updates['reconciliation_note'] = $note ?? ('manual_review: '.($agedOut
                ? 'max automatic reconciliation window exceeded'
                : $outcome));
        } else {
            $updates['next_reconciliation_at'] = now()->addMinutes($this->nextAttemptDelayMinutes($attempts - 1));
        }

        $payable->forceFill($updates)->save();

        return $agedOut ? self::OUTCOME_MAX_WINDOW_EXCEEDED : $outcome;
    }

    private function logEvent(string $level, string $message, array $context): void
    {
        // Structured, non-secret logging only — never the full verification
        // payload (may carry provider-specific fields the codebase can't
        // fully audit for secrets across five gateways), only what's
        // explicitly listed by the caller.
        Log::{$level}($message, $context);
    }

    // -----------------------------------------------------------------
    // Order
    // -----------------------------------------------------------------

    private function reconcileOrder(Order $order): string
    {
        $this->logEvent('info', 'PaymentReconciliationService: reconciling order', [
            'payable_type' => 'order',
            'payable_id' => $order->id,
            'attempt' => ((int) $order->reconciliation_attempts) + 1,
        ]);

        if (! $order->payment_gateway) {
            $this->logEvent('warning', 'PaymentReconciliationService: order has no recorded gateway', [
                'payable_type' => 'order',
                'payable_id' => $order->id,
            ]);

            return $this->recordAttempt($order, self::OUTCOME_NO_GATEWAY);
        }

        // Read-only. Never initializes a new charge, never generates a new
        // reference — only queries status for the reference already on file.
        $verification = $this->gatewayManager->verifyCollectionWithGateway($order->payment_gateway, $order->payment_reference);
        $state = PaymentVerificationState::from($verification);

        $this->logEvent('info', 'PaymentReconciliationService: order verification result', [
            'payable_type' => 'order',
            'payable_id' => $order->id,
            'gateway' => $order->payment_gateway,
            'state' => $state,
        ]);

        if ($state === PaymentVerificationState::SUCCESS) {
            $outcome = DB::transaction(function () use ($order, $verification) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();

                if (! $locked || ! in_array($locked->payment_status, ['pending', 'unpaid'], true)) {
                    // Already resolved by something else since we read it —
                    // that resolution is authoritative.
                    return self::OUTCOME_LEFT_PENDING;
                }

                // "The gateway says this reference succeeded" is NOT sufficient
                // to complete an order, and this is the surface where that
                // matters most: reconciliation can reach back to records
                // created long ago, so it is the path by which a historical
                // malformed order could suddenly settle and deliver real goods
                // months later.
                //
                // The shared guard refuses unless the confirmed payment matches
                // this exact order's frozen terms. Critically, an order created
                // before server-authoritative pricing has no provable expected
                // amount at all — the guard returns MANUAL_REVIEW for it rather
                // than comparing amount_paid against itself (which is what let
                // a GHS 0.10 payment "match" an ~GHS 89 order: the recorded
                // expectation was the corrupted value).
                $integrity = $this->integrityGuard->guard($locked, $verification, $locked->payment_gateway);

                if (! $integrity->passed) {
                    $this->logEvent('error', 'PaymentReconciliationService: order failed payment integrity — refusing to complete', [
                        'payable_type' => 'order',
                        'payable_id' => $locked->id,
                        'reason' => $integrity->reason,
                        'integrity_status' => $integrity->status,
                        'expected_amount' => $integrity->expectedAmount,
                        'confirmed_amount' => $integrity->confirmedAmount,
                    ]);

                    return $integrity->status === PaymentIntegrity::MANUAL_REVIEW
                        ? self::OUTCOME_MAX_WINDOW_EXCEEDED
                        : self::OUTCOME_INTEGRITY_MISMATCH;
                }

                $locked->amount_paid = $integrity->confirmedAmount;
                $locked->save();

                $this->paymentService->completeOrder($locked);

                return self::OUTCOME_COMPLETED;
            });

            return $outcome === self::OUTCOME_COMPLETED ? $outcome : $this->recordAttempt($order, $outcome);
        }

        if ($state === PaymentVerificationState::FAILED) {
            $outcome = DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();

                if (! $locked || ! in_array($locked->payment_status, ['pending', 'unpaid'], true)) {
                    return self::OUTCOME_LEFT_PENDING;
                }

                $locked->update(['payment_status' => 'failed', 'status' => 'Failed']);

                return self::OUTCOME_CANCELLED;
            });

            return $this->recordAttempt($order, $outcome);
        }

        // PENDING or UNKNOWN.
        return $this->recordAttempt($order, self::OUTCOME_LEFT_PENDING);
    }

    // -----------------------------------------------------------------
    // AFA registration
    // -----------------------------------------------------------------

    private function reconcileAfaRegistration(AfaRegistration $registration): string
    {
        $this->logEvent('info', 'PaymentReconciliationService: reconciling AFA registration', [
            'payable_type' => 'afa_registration',
            'payable_id' => $registration->id,
            'attempt' => ((int) $registration->reconciliation_attempts) + 1,
        ]);

        if (! $registration->payment_gateway) {
            $this->logEvent('warning', 'PaymentReconciliationService: AFA registration has no recorded gateway', [
                'payable_type' => 'afa_registration',
                'payable_id' => $registration->id,
            ]);

            return $this->recordAttempt($registration, self::OUTCOME_NO_GATEWAY);
        }

        $verification = $this->gatewayManager->verifyCollectionWithGateway($registration->payment_gateway, $registration->payment_reference);
        $state = PaymentVerificationState::from($verification);

        $this->logEvent('info', 'PaymentReconciliationService: AFA registration verification result', [
            'payable_type' => 'afa_registration',
            'payable_id' => $registration->id,
            'gateway' => $registration->payment_gateway,
            'state' => $state,
        ]);

        if ($state === PaymentVerificationState::SUCCESS) {
            $outcome = DB::transaction(function () use ($registration, $verification) {
                $locked = AfaRegistration::whereKey($registration->id)->lockForUpdate()->first();

                if (! $locked || $locked->payment_status !== 'pending') {
                    return self::OUTCOME_LEFT_PENDING;
                }

                $amount = data_get($verification, 'data.amount');
                $expected = (float) $locked->amount;
                if ($amount !== null && $expected > 0 && round((float) $amount, 2) < round($expected, 2)) {
                    $this->logEvent('error', 'PaymentReconciliationService: AFA registration amount mismatch — refusing to fulfil', [
                        'payable_type' => 'afa_registration',
                        'payable_id' => $locked->id,
                        'expected_amount' => $expected,
                        'verified_amount' => $amount,
                    ]);

                    return self::OUTCOME_INTEGRITY_MISMATCH;
                }

                $this->afaPaymentService->completeRegistration($locked);

                return self::OUTCOME_COMPLETED;
            });

            return $outcome === self::OUTCOME_COMPLETED ? $outcome : $this->recordAttempt($registration, $outcome);
        }

        if ($state === PaymentVerificationState::FAILED) {
            $outcome = DB::transaction(function () use ($registration) {
                $locked = AfaRegistration::whereKey($registration->id)->lockForUpdate()->first();

                if (! $locked || $locked->payment_status !== 'pending') {
                    return self::OUTCOME_LEFT_PENDING;
                }

                $locked->update([
                    'status' => AfaRegistration::STATUS_CANCELLED,
                    'payment_status' => AfaRegistration::PAYMENT_FAILED,
                    'admin_notes' => 'Auto-cancelled: gateway confirmed payment failed during reconciliation.',
                ]);

                return self::OUTCOME_CANCELLED;
            });

            return $this->recordAttempt($registration, $outcome);
        }

        return $this->recordAttempt($registration, self::OUTCOME_LEFT_PENDING);
    }

    // -----------------------------------------------------------------
    // Result checker order
    // -----------------------------------------------------------------

    private function reconcileResultCheckerOrder(ResultCheckerOrder $order): string
    {
        $this->logEvent('info', 'PaymentReconciliationService: reconciling result checker order', [
            'payable_type' => 'result_checker_order',
            'payable_id' => $order->id,
            'attempt' => ((int) $order->reconciliation_attempts) + 1,
        ]);

        if (! $order->payment_gateway) {
            $this->logEvent('warning', 'PaymentReconciliationService: result checker order has no recorded gateway', [
                'payable_type' => 'result_checker_order',
                'payable_id' => $order->id,
            ]);

            return $this->recordAttempt($order, self::OUTCOME_NO_GATEWAY);
        }

        $verification = $this->gatewayManager->verifyCollectionWithGateway($order->payment_gateway, $order->payment_reference);
        $state = PaymentVerificationState::from($verification);

        $this->logEvent('info', 'PaymentReconciliationService: result checker order verification result', [
            'payable_type' => 'result_checker_order',
            'payable_id' => $order->id,
            'gateway' => $order->payment_gateway,
            'state' => $state,
        ]);

        if ($state === PaymentVerificationState::SUCCESS) {
            $outcome = DB::transaction(function () use ($order, $verification) {
                $locked = ResultCheckerOrder::whereKey($order->id)->lockForUpdate()->first();

                if (! $locked || $locked->status === 'completed' || $locked->paid_at) {
                    return self::OUTCOME_LEFT_PENDING;
                }

                $amount = data_get($verification, 'data.amount');
                $expected = (float) $locked->total_price;
                if ($amount !== null && $expected > 0 && round((float) $amount, 2) < round($expected, 2)) {
                    $this->logEvent('error', 'PaymentReconciliationService: result checker order amount mismatch — refusing to fulfil', [
                        'payable_type' => 'result_checker_order',
                        'payable_id' => $locked->id,
                        'expected_amount' => $expected,
                        'verified_amount' => $amount,
                    ]);

                    return self::OUTCOME_INTEGRITY_MISMATCH;
                }

                // handlePaymentCallback() takes its own lock internally too;
                // Laravel/PDO nest this as a savepoint on the same connection,
                // which is safe — see ResultCheckerService for the guard this
                // relies on.
                $this->resultCheckerService->handlePaymentCallback($locked, $locked->payment_reference, $locked->payment_gateway);

                return self::OUTCOME_COMPLETED;
            });

            return $outcome === self::OUTCOME_COMPLETED ? $outcome : $this->recordAttempt($order, $outcome);
        }

        if ($state === PaymentVerificationState::FAILED) {
            $outcome = DB::transaction(function () use ($order) {
                $locked = ResultCheckerOrder::whereKey($order->id)->lockForUpdate()->first();

                if (! $locked || $locked->status === 'completed' || $locked->paid_at) {
                    return self::OUTCOME_LEFT_PENDING;
                }

                $locked->update(['status' => 'failed']);

                return self::OUTCOME_CANCELLED;
            });

            return $this->recordAttempt($order, $outcome);
        }

        return $this->recordAttempt($order, self::OUTCOME_LEFT_PENDING);
    }

    // -----------------------------------------------------------------
    // USSD subscription
    // -----------------------------------------------------------------

    private function reconcileUssdSubscription(UssdSubscription $subscription): string
    {
        $this->logEvent('info', 'PaymentReconciliationService: reconciling USSD subscription', [
            'payable_type' => 'ussd_subscription',
            'payable_id' => $subscription->id,
            'attempt' => ((int) $subscription->reconciliation_attempts) + 1,
        ]);

        if (! $subscription->payment_gateway) {
            $this->logEvent('warning', 'PaymentReconciliationService: USSD subscription has no recorded gateway', [
                'payable_type' => 'ussd_subscription',
                'payable_id' => $subscription->id,
            ]);

            return $this->recordAttempt($subscription, self::OUTCOME_NO_GATEWAY);
        }

        if (! $subscription->payment_reference) {
            return $this->recordAttempt($subscription, self::OUTCOME_NO_GATEWAY, 'manual_review: no payment_reference recorded');
        }

        // UssdSubscriptionPurchaseService::verifyAndActivate() already does
        // the full verify -> PaymentVerificationState -> amount-guard ->
        // lock-protected activate pipeline (Phase 1) — this is the one
        // payable where delegating to the existing higher-level method,
        // rather than reimplementing verify+lock+complete here, is the
        // correct reuse: duplicating its amount-guard and settlement checks
        // here would be exactly the "separate reconciliation fulfillment
        // logic" this phase must avoid.
        $result = $this->ussdSubscriptionPurchaseService->verifyAndActivate($subscription->payment_reference);

        $subscription->refresh();

        $this->logEvent('info', 'PaymentReconciliationService: USSD subscription reconciliation result', [
            'payable_type' => 'ussd_subscription',
            'payable_id' => $subscription->id,
            'gateway' => $subscription->payment_gateway,
            'status' => $subscription->status,
            'result_success' => (bool) ($result['success'] ?? false),
        ]);

        if ($subscription->status === UssdSubscription::STATUS_ACTIVE) {
            // Already-active is reported as completed regardless of whether
            // this call did it or a race did — recordAttempt() is skipped
            // either way since there's nothing left to schedule.
            return self::OUTCOME_COMPLETED;
        }

        // Phase 5: STATUS_PAYMENT_FAILED is the (new) explicit terminal-failure
        // status verifyAndActivate()/failPayment() now sets — see
        // UssdSubscription's own docblock for why it's distinct from
        // STATUS_CANCELLED. Before this status existed, a confirmed-failed
        // USSD payment stayed STATUS_PENDING_PAYMENT forever and fell through
        // to OUTCOME_LEFT_PENDING below, so the scheduler kept re-querying an
        // already-dead reference on its backoff schedule for up to the full
        // 72-hour automatic window.
        if (in_array($subscription->status, [UssdSubscription::STATUS_CANCELLED, UssdSubscription::STATUS_PAYMENT_FAILED], true)) {
            return $this->recordAttempt($subscription, self::OUTCOME_CANCELLED);
        }

        return $this->recordAttempt($subscription, self::OUTCOME_LEFT_PENDING);
    }

    // -----------------------------------------------------------------
    // Wallet top-up
    // -----------------------------------------------------------------

    private function reconcileWalletTopup(WalletTopup $topup): string
    {
        $this->logEvent('info', 'PaymentReconciliationService: reconciling wallet topup', [
            'payable_type' => 'wallet_topup',
            'payable_id' => $topup->id,
            'attempt' => ((int) $topup->reconciliation_attempts) + 1,
        ]);

        if (! $topup->payment_gateway) {
            // Legacy row predating the payment_gateway column (or, in theory,
            // a row that somehow never got one stamped). Never guessed
            // against the current default — parked for manual review on
            // first sight, exactly like every other no-gateway payable.
            $this->logEvent('warning', 'PaymentReconciliationService: wallet topup has no recorded gateway (legacy row) — manual review required', [
                'payable_type' => 'wallet_topup',
                'payable_id' => $topup->id,
            ]);

            return $this->recordAttempt($topup, self::OUTCOME_NO_GATEWAY, 'manual_review: legacy top-up predates payment_gateway column');
        }

        $verification = $this->gatewayManager->verifyCollectionWithGateway($topup->payment_gateway, $topup->reference);
        $state = PaymentVerificationState::from($verification);

        $this->logEvent('info', 'PaymentReconciliationService: wallet topup verification result', [
            'payable_type' => 'wallet_topup',
            'payable_id' => $topup->id,
            'gateway' => $topup->payment_gateway,
            'state' => $state,
        ]);

        if ($state === PaymentVerificationState::SUCCESS) {
            // WalletService::completeTopup() is the single authoritative
            // completion pipeline (also used by the browser callback and the
            // status poll) — it already locks, re-checks, and applies the
            // same amount-integrity guard, so nothing further is duplicated
            // here.
            $credited = $this->walletService->completeTopup($topup, $verification);

            if (! $credited) {
                // completeTopup() refused — either an amount mismatch or an
                // invalid record; either way this needs a human, not another
                // automatic attempt.
                return $this->recordAttempt($topup, self::OUTCOME_INTEGRITY_MISMATCH);
            }

            return self::OUTCOME_COMPLETED;
        }

        if ($state === PaymentVerificationState::FAILED) {
            $outcome = DB::transaction(function () use ($topup, $verification) {
                $locked = WalletTopup::whereKey($topup->id)->lockForUpdate()->first();

                if (! $locked || $locked->status === 'completed') {
                    return self::OUTCOME_LEFT_PENDING;
                }

                $locked->update(['status' => 'failed', 'gateway_response' => $verification]);

                return self::OUTCOME_CANCELLED;
            });

            return $this->recordAttempt($topup, $outcome);
        }

        return $this->recordAttempt($topup, self::OUTCOME_LEFT_PENDING);
    }
}
