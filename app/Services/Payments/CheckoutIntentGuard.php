<?php

namespace App\Services\Payments;

use App\Services\PaymentReconciliationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 4 — customer-intent / payment-attempt duplication guard.
 *
 * Phases 1-3 made every INDIVIDUAL payment reference safe: correctly
 * verified, idempotent, and race-safe on its own. What they cannot prevent
 * is a customer's own retry minting a brand-new, individually-legitimate
 * reference (REF-B) while an earlier reference (REF-A) for the exact same
 * checkout attempt is still financially ambiguous — both references can
 * then independently succeed, and the customer is charged twice.
 *
 * This class is the single place every initiation endpoint (Order via both
 * CheckoutController and PurchaseController, AfaRegistration,
 * ResultCheckerOrder, UssdSubscription, WalletTopup) asks the same
 * question before creating a new payable row: "does an existing, still-
 * relevant row already represent this exact retry?" — and, if so, what to
 * do instead of creating a second one.
 *
 * Design:
 *
 * - idempotency_key: an opaque token supplied by the client, identifying
 *   one payment ATTEMPT for one purchase INTENT. It is deliberately NOT
 *   trusted as globally unique or as a security boundary by itself — see
 *   idempotency_scope.
 * - idempotency_scope: a value computed SERVER-SIDE from the requester's
 *   own authenticated/session identity (never client-supplied). A key only
 *   ever resolves to an existing row when both the key AND the scope
 *   match, so guessing or replaying another customer's key can never reuse
 *   their in-flight or completed payable — it can, at most, fail to
 *   deduplicate (falls through to creating a brand new row under the
 *   attacker's OWN scope), never read or mutate someone else's row.
 * - The database UNIQUE index on (idempotency_scope, idempotency_key) is
 *   the actual concurrency guard, not an application-level lock: two
 *   requests racing to create a row for the identical (scope, key) pair
 *   can never both succeed. guardedCreate() below is what turns "an insert
 *   just lost that race" into the exact same reuse decision evaluate()
 *   would have made if it had seen the row first — so a lock is never held
 *   across the outbound gateway HTTP call.
 *
 * "Same intent" is decided ONLY by this key+scope pair — never by a
 * heuristic like "same phone + amount within N minutes". A customer buying
 * the identical product twice, deliberately, gets a fresh key from the
 * client each time and is therefore never blocked (see the "Legitimate
 * repeat purchase" tests) — same customer + same product is not, on its
 * own, evidence of duplication.
 */
class CheckoutIntentGuard
{
    /** An existing row for this (scope, key) has already reached a paid/complete state. Reuse it — no new charge. */
    public const ALREADY_SUCCEEDED = 'already_succeeded';

    /**
     * An existing row for this (scope, key) is still financially ambiguous
     * (pending or unknown) even after a fresh, synchronous re-check against
     * its own gateway. Do NOT create a new payable and do NOT call any
     * gateway to initiate a new charge — surface the existing reference so
     * the caller can keep polling/reconciling it.
     */
    public const STILL_PENDING = 'still_pending';

    /**
     * An existing row for this (scope, key) has been AUTHORITATIVELY
     * confirmed failed by its own gateway (or already was). It is safe to
     * create a brand-new payable + a brand-new attempt — but never by
     * reusing this exact key (the unique index forbids it anyway); see
     * freshKeyAfterFailure().
     */
    public const RETRY_AFTER_FAILURE = 'retry_after_failure';

    /** No existing row stands in the way — safe to create a new payable normally. */
    public const PROCEED = 'proceed';

    /**
     * PaymentReconciliationService is resolved lazily (never constructor-
     * injected) deliberately: it depends on UssdSubscriptionPurchaseService,
     * which itself depends on this class to guard USSD subscription
     * initiation — a straight constructor dependency here would be a
     * circular dependency the container cannot resolve. Resolving it only
     * when classify() actually needs to re-check a pending reference avoids
     * the cycle without weakening anything: this class does nothing at
     * construction time either way.
     */
    private function reconciliation(): PaymentReconciliationService
    {
        return app(PaymentReconciliationService::class);
    }

    public static function scopeForSession(string $sessionId): string
    {
        return 'session:'.$sessionId;
    }

    public static function scopeForVendor(int $vendorId): string
    {
        return 'vendor:'.$vendorId;
    }

    /**
     * Scope for a raw USSD dial session (aggregator-issued `sessionId`, see
     * UssdController/UssdGatewayRequest) — distinct from `session:` (the web
     * checkout's Laravel session id) so the two id spaces can never collide.
     * A single dial session can only ever confirm one purchase, so this is
     * the correct "same intent" identity for UssdMenuService's Order/
     * ResultCheckerOrder creation (NOT the vendor-facing UssdSubscription
     * purchase flow, which is already guarded via scopeForVendor()).
     */
    public static function scopeForUssdDialSession(string $ussdSessionId): string
    {
        return 'ussd-dial:'.$ussdSessionId;
    }

    /**
     * Look up whatever the (scope, key) pair currently points to and decide
     * what the caller may safely do next. Does not create or mutate
     * anything except — via PaymentReconciliationService — completing or
     * failing an existing row exactly as the browser callback / webhook /
     * scheduled reconciler already would.
     *
     * @param  class-string<Model>  $modelClass
     * @param  \Closure(Model):bool  $isSuccess
     * @param  \Closure(Model):bool  $isTerminalFailure
     * @param  \Closure(Model):bool  $hasGateway
     */
    public function evaluate(
        string $modelClass,
        string $scope,
        ?string $idempotencyKey,
        \Closure $isSuccess,
        \Closure $isTerminalFailure,
        \Closure $hasGateway
    ): array {
        if (! $idempotencyKey) {
            // No key supplied — an older client, or a caller that never
            // sends one. There is nothing to deduplicate against; this is
            // exactly the pre-Phase-4 behaviour, never a hard error.
            return ['action' => self::PROCEED, 'payable' => null];
        }

        $existing = $modelClass::query()
            ->where('idempotency_scope', $scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return ['action' => self::PROCEED, 'payable' => null];
        }

        return $this->classify($existing, $isSuccess, $isTerminalFailure, $hasGateway);
    }

    /**
     * Create a brand-new payable for this checkout intent, safely handling
     * the case where a concurrent request (double-click, duplicate POST,
     * two tabs) already won the race and inserted the same (scope, key)
     * pair first — and the rarer case where the row it collided with turns
     * out to already be terminally failed, which is retried once with a
     * fresh key rather than handed back as if it were this request's own
     * new row.
     *
     * @param  class-string<Model>  $modelClass
     * @param  \Closure(?string $key):Model  $createFactory  Persists a row
     *                                                       tagged with the given idempotency key (and $scope); called
     *                                                       with a fresh key on the one-time RETRY_AFTER_FAILURE retry.
     * @param  \Closure(Model):bool  $isSuccess
     * @param  \Closure(Model):bool  $isTerminalFailure
     * @param  \Closure(Model):bool  $hasGateway
     */
    public function createOrReuse(
        string $modelClass,
        string $scope,
        ?string $idempotencyKey,
        \Closure $createFactory,
        \Closure $isSuccess,
        \Closure $isTerminalFailure,
        \Closure $hasGateway
    ): array {
        $key = $idempotencyKey;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $this->guardedCreate($modelClass, $scope, $key, fn () => $createFactory($key), $isSuccess, $isTerminalFailure, $hasGateway);

            if ($result['action'] === self::RETRY_AFTER_FAILURE && $key && $attempt === 0) {
                // Collided with someone else's (or our own prior) already-
                // failed row — retry exactly once with a fresh, never-seen
                // key so this genuinely new attempt gets its own row.
                $key = $this->freshKeyAfterFailure($key);

                continue;
            }

            return $result;
        }

        return $result; // @phpstan-ignore-line unreachable — loop always returns above
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  \Closure():Model  $create
     * @param  \Closure(Model):bool  $isSuccess
     * @param  \Closure(Model):bool  $isTerminalFailure
     * @param  \Closure(Model):bool  $hasGateway
     */
    private function guardedCreate(
        string $modelClass,
        string $scope,
        ?string $idempotencyKey,
        \Closure $create,
        \Closure $isSuccess,
        \Closure $isTerminalFailure,
        \Closure $hasGateway
    ): array {
        try {
            return ['action' => self::PROCEED, 'payable' => $create()];
        } catch (QueryException $e) {
            if (! $idempotencyKey || ! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            Log::info('CheckoutIntentGuard: lost the create race for a checkout intent — reusing the winning row instead of creating a duplicate', [
                'model' => $modelClass,
                'scope' => $scope,
            ]);

            $existing = $modelClass::query()
                ->where('idempotency_scope', $scope)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                // Should be unreachable — a unique-constraint violation proves
                // a row exists — but never mask the original DB error if it
                // somehow does (e.g. a different unique index on the table).
                throw $e;
            }

            return $this->classify($existing, $isSuccess, $isTerminalFailure, $hasGateway);
        }
    }

    /**
     * A terminally-failed idempotency key can never be reused for a new row
     * (the unique index would reject it), which is deliberate: a genuinely
     * new attempt after a confirmed failure always gets its own fresh key
     * and its own row. The failed row itself is left completely untouched —
     * Phase 4 never mutates a terminal reference back into pending and
     * never re-touches its gateway/reconciliation lifecycle.
     */
    public function freshKeyAfterFailure(string $idempotencyKey): string
    {
        return $idempotencyKey.':retry-'.Str::random(8);
    }

    private function classify(Model $existing, \Closure $isSuccess, \Closure $isTerminalFailure, \Closure $hasGateway): array
    {
        if ($isSuccess($existing)) {
            return ['action' => self::ALREADY_SUCCEEDED, 'payable' => $existing];
        }

        if ($isTerminalFailure($existing)) {
            return ['action' => self::RETRY_AFTER_FAILURE, 'payable' => $existing];
        }

        // Unresolved locally. Give the SAME reference one authoritative,
        // synchronous check against ITS OWN gateway before deciding
        // anything: PaymentReconciliationService::reconcile() always reads
        // $existing->payment_gateway (never the current platform default —
        // Section 10), never mutates the row's originally-recorded expected
        // amount (Section 11), takes its DB lock only around the local
        // completion step and never across the outbound HTTP call to the
        // gateway (Section 12), and is a safe no-op if a concurrent
        // webhook or the scheduled reconciler already resolved this exact
        // row first (Section 12/13) — whichever gets the row lock first
        // wins, the other sees it already resolved and does nothing.
        if ($hasGateway($existing)) {
            $this->reconciliation()->reconcile($existing);
            $existing->refresh();
        }

        if ($isSuccess($existing)) {
            return ['action' => self::ALREADY_SUCCEEDED, 'payable' => $existing];
        }

        if ($isTerminalFailure($existing)) {
            return ['action' => self::RETRY_AFTER_FAILURE, 'payable' => $existing];
        }

        return ['action' => self::STILL_PENDING, 'payable' => $existing];
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (integrity constraint violation) covers unique-key
        // conflicts on both MySQL (production) and SQLite (dev/test).
        return $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
