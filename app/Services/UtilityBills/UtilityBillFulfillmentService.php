<?php

namespace App\Services\UtilityBills;

use App\Jobs\SubmitUtilityBillPayment;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;
use App\Services\SmsService;
use App\Services\UtilityBills\Data\PayResult;
use App\Services\UtilityBills\Data\StatusResult;
use App\Services\UtilityBills\Exceptions\ProviderDuplicateWindow;
use App\Services\UtilityBills\Exceptions\ProviderInsufficientBalance;
use App\Services\UtilityBills\Exceptions\ProviderMalformedResponse;
use App\Services\UtilityBills\Exceptions\ProviderNotFound;
use App\Services\UtilityBills\Exceptions\ProviderRateLimited;
use App\Services\UtilityBills\Exceptions\ProviderServiceDisabled;
use App\Services\UtilityBills\Exceptions\UtilityProviderException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provider fulfillment of a PAID Utility Bill order.
 *
 * Three phases, so a database lock is never held across an HTTP call and two
 * workers can never both pay the provider:
 *
 *   1. CLAIM    (row lock)  prove payment, persist the stable provider request
 *                           reference, move queued -> submitting, mint a claim.
 *   2. SUBMIT   (no lock)   POST /utilities/pay with that persisted reference.
 *   3. RECORD   (guarded)   conditional UPDATE keyed on the claim token.
 *
 * The provider request reference is generated once, persisted BEFORE the first
 * call, and reused for every retry of the same attempt (timeouts, 5xx, queue
 * retries, admin retries, scheduler retries) — the provider treats it as an
 * idempotency key, so a replay returns the original order instead of charging
 * again. Only an explicit admin "new attempt" after the provider CONFIRMED a
 * definitive failure/refund mints a new reference (attempt N+1).
 *
 * A provider refund is not a customer refund, and nothing here ever alters
 * the customer payment (orders.payment_status) — fulfillment problems are a
 * separate, recoverable axis.
 */
class UtilityBillFulfillmentService
{
    /** Error codes worth retrying automatically (the sweeper re-dispatches these). */
    public const AUTO_RETRY_CODES = ['insufficient_balance', 'disabled', 'timeout', 'upstream', 'rate_limited', 'malformed'];

    public function __construct(
        private KingFlexyUtilityProvider $provider,
        private UtilityBillCommissionService $commission,
    ) {}

    // ------------------------------------------------------------------
    // Payment boundary
    // ------------------------------------------------------------------

    /**
     * Called by PaymentService INSIDE its settlement transaction, after the
     * envelope order has been proven and marked paid. Idempotent: only the
     * first call moves awaiting_payment -> queued.
     */
    public function markPaid(int $utilityBillOrderId): bool
    {
        $u = UtilityBillOrder::query()->whereKey($utilityBillOrderId)->lockForUpdate()->first();

        if (! $u || $u->fulfillment_status !== FulfillmentStatus::AWAITING_PAYMENT) {
            return false;
        }

        $u->forceFill([
            'fulfillment_status' => FulfillmentStatus::QUEUED,
            'next_submit_at' => now(),
        ])->save();

        $this->event($u, 'payment_confirmed', FulfillmentStatus::AWAITING_PAYMENT, FulfillmentStatus::QUEUED, null, null, 'system');

        return true;
    }

    /**
     * Immediate dispatch (payment confirmed, admin retry). Every LATER attempt — budget
     * deferrals, transient retries, lost jobs — is re-dispatched only by UtilityBillSweeper,
     * oldest order first and at most its per-run limit, so a backlog can never multiply
     * jobs and orders are paid to the provider in the order customers paid.
     */
    public function dispatchSubmit(int $utilityBillOrderId, bool $fromSweeper = false): void
    {
        SubmitUtilityBillPayment::dispatch($utilityBillOrderId, $fromSweeper)->afterCommit();
    }

    // ------------------------------------------------------------------
    // Submit
    // ------------------------------------------------------------------

    /**
     * @param  bool  $yieldToBacklog  true for immediate (non-sweeper) dispatches: step aside when an
     *                                older paid order is still waiting, so it is not overtaken
     * @return string one of: skipped:<why> | deferred | submitted | requeued | attention | completed | claim_lost
     */
    public function submit(int $utilityBillOrderId, bool $force = false, bool $yieldToBacklog = false): string
    {
        $claim = $this->claim($utilityBillOrderId, $force, $yieldToBacklog);

        if (is_string($claim)) {
            return $claim;
        }

        try {
            $result = $this->provider->pay(
                $claim['biller'],
                $claim['account'],
                $claim['amount'],
                $claim['reference'],
                $claim['phone'],
            );
        } catch (UtilityProviderException $e) {
            return $this->recordSubmitFailure($claim, $e);
        }

        return $this->recordSubmitSuccess($claim, $result);
    }

    /**
     * submit() for a queue worker: when the shared pay budget is full but a slot frees within
     * `pay_inline_wait_seconds`, wait for it once instead of leaving the order for the next sweep
     * (which, with a per-minute cron worker, would cost a whole minute of provider capacity).
     * Never waits inside a web request.
     */
    public function submitFromWorker(int $utilityBillOrderId, bool $yieldToBacklog): string
    {
        $result = $this->submit($utilityBillOrderId, yieldToBacklog: $yieldToBacklog);
        $maxWait = (int) config('utility_bills.pay_inline_wait_seconds', 15);

        if ($result !== 'deferred' || $maxWait < 1 || ! app()->runningInConsole()) {
            return $result;
        }

        $wait = $this->provider->payBudgetAvailableIn();
        if ($wait < 1 || $wait > $maxWait) {
            return $result;
        }

        sleep($wait);

        return $this->submit($utilityBillOrderId, yieldToBacklog: $yieldToBacklog);
    }

    /**
     * @return array{id:int,token:string,reference:string,biller:string,account:string,amount:string,phone:?string}|string
     */
    private function claim(int $id, bool $force, bool $yieldToBacklog = false): array|string
    {
        return DB::transaction(function () use ($id, $force, $yieldToBacklog) {
            $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();

            if (! $u) {
                return 'skipped:missing';
            }

            // Payment proof is re-checked under the lock, from the envelope order.
            $order = Order::query()->whereKey($u->order_id)->first();
            if (! $order
                || ! in_array($order->payment_status, ['paid', 'completed'], true)
                || ! $order->allowsFulfillment()) {
                // A queued order whose payment is no longer confirmed (e.g. integrity re-stamped)
                // can never be claimed; left queued it would stay "due" and hold one of the
                // sweeper's oldest-first slots forever. Park it for a human instead.
                if ($u->fulfillment_status === FulfillmentStatus::QUEUED) {
                    $this->setAttention($u, 'payment_unconfirmed', 'Customer payment is no longer confirmed for fulfillment.', null);
                }

                return 'skipped:not_paid';
            }

            $staleClaim = $u->fulfillment_status === FulfillmentStatus::SUBMITTING
                && $u->claimed_at !== null
                && $u->claimed_at->lt(now()->subSeconds((int) config('utility_bills.claim_stale_seconds')));

            if (! in_array($u->fulfillment_status, [FulfillmentStatus::QUEUED, FulfillmentStatus::ATTENTION], true) && ! $staleClaim) {
                return 'skipped:state';
            }

            // The provider already holds an order for this attempt: poll it, never pay again.
            if ($u->provider_order_reference !== null) {
                return 'skipped:has_provider_order';
            }

            if (! $force && $u->next_submit_at !== null && $u->next_submit_at->isFuture()) {
                return 'skipped:backoff';
            }

            // An immediate dispatch must not overtake older paid orders still waiting; it stays
            // queued and due, and the sweeper submits it in turn.
            if ($yieldToBacklog && ! $force && $this->olderSubmissionWaiting($u)) {
                return 'skipped:backlog';
            }

            // Check OUR pay budget before an attempt is claimed and counted: being held
            // back locally sends nothing, so it must never use up the order's attempts.
            // The order stays due; the sweeper re-dispatches it (oldest first) next run.
            if ($this->provider->payBudgetAvailableIn() > 0) {
                return 'deferred';
            }

            if (! $force && $u->submit_attempts >= (int) config('utility_bills.max_submit_attempts')) {
                $this->setAttention($u, 'max_attempts', 'Automatic submission attempts exhausted.', null);

                return 'attention';
            }

            // Persist the idempotency key BEFORE the first provider call; never regenerate it.
            $reference = $u->provider_request_reference ?? $this->requestReference($u);
            $token = Str::random(40);
            $fromStatus = $u->fulfillment_status;

            $u->forceFill([
                'provider_request_reference' => $reference,
                'fulfillment_status' => FulfillmentStatus::SUBMITTING,
                'claim_token' => $token,
                'claimed_at' => now(),
                'submit_attempts' => $u->submit_attempts + 1,
            ])->save();

            $this->event($u, 'submit_claimed', $fromStatus, FulfillmentStatus::SUBMITTING, null, 'attempt '.$u->submit_attempts.', ref '.$reference, 'system');

            return [
                'id' => $u->id,
                'token' => $token,
                'reference' => $reference,
                'biller' => $u->biller_key,
                'account' => $u->account_number,
                'amount' => (string) $u->bill_amount,
                'phone' => $u->customer_phone,
                'attempts' => $u->submit_attempts,
                'prev_error' => $u->last_error_code,
            ];
        });
    }

    private function recordSubmitSuccess(array $claim, PayResult $result): string
    {
        $mapped = ProviderStatusMapper::toFulfillmentStatus($result->status) ?? FulfillmentStatus::PROVIDER_PENDING;

        $attrs = [
            'provider_order_reference' => $result->providerReference,
            'provider_status' => $result->status,
            'fulfillment_status' => $mapped,
            'submitted_at' => now(),
            'provider_commission_share_percent' => $result->commissionSharePercent,
            'status_check_attempts' => 0,
            'next_status_check_at' => now()->addSeconds($this->backoff(0)),
            'status_poll_started_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ];

        if ($mapped === FulfillmentStatus::COMPLETED) {
            $attrs['fulfilled_at'] = now();
        }

        try {
            $applied = $this->finishClaim($claim, $attrs, 'provider_accepted', 'provider ref '.$result->providerReference.($result->alreadyProcessed ? ' (idempotent replay)' : ''), 200, $mapped);
        } catch (QueryException $e) {
            // e.g. the provider reference already belongs to a different order. Never guess.
            Log::error('utility_bills.fulfillment.reference_conflict', ['utility_bill_order_id' => $claim['id']]);
            $u = UtilityBillOrder::query()->find($claim['id']);
            if ($u) {
                $this->setAttention($u, 'reference_conflict', 'Provider reference conflict; manual review needed.', null);
            }

            return 'attention';
        }

        if (! $applied) {
            return 'claim_lost';
        }

        // The provider just accepted a payment, so its wallet is funded again.
        if (UtilityBillSettings::providerWalletPausedAt() !== null) {
            $this->resumeAfterProviderWallet('system');
        }

        $this->afterStatusChange($claim['id']);

        return $mapped === FulfillmentStatus::COMPLETED ? 'completed' : 'submitted';
    }

    private function recordSubmitFailure(array $claim, UtilityProviderException $e): string
    {
        Log::warning('utility_bills.fulfillment.submit_failed', [
            'utility_bill_order_id' => $claim['id'],
            'error_code' => $e->errorCode,
            'http_status' => $e->httpStatus,
        ]);

        // Outcomes where the provider certainly did NOT act on this attempt: our own
        // budget refused it before sending, the provider rate-limited it (429), it fell
        // inside the provider's duplicate window (409), or the provider wallet could not
        // cover it. They wait for capacity, so they never count toward max_submit_attempts.
        $notCounted = $e instanceof ProviderRateLimited
            || $e instanceof ProviderDuplicateWindow
            || $e instanceof ProviderInsufficientBalance;
        $attemptAttrs = $notCounted ? ['submit_attempts' => max(0, (int) $claim['attempts'] - 1)] : [];

        // Ambiguous or transient outcomes keep the SAME reference and go back to
        // the queue: a replay either returns the order the provider already
        // created, or creates it once.
        $transient = $e->isAmbiguous()
            || $e instanceof ProviderRateLimited
            || $e instanceof ProviderDuplicateWindow
            || $e instanceof ProviderMalformedResponse;

        if ($transient) {
            // 409 = the provider's 30 s duplicate window (a new reference would not bypass it):
            // nothing was charged, wait it out and retry with the SAME reference.
            // Jitter spreads a burst of retries so they do not all collide again.
            $delay = match (true) {
                // Our own budget refused it (nothing was sent): stay due, keeping its place in the
                // oldest-first line, and let the sweeper resubmit it when capacity frees.
                $e instanceof ProviderRateLimited && $e->local => 0,
                $e instanceof ProviderRateLimited => $this->jitter(30, 30),
                $e instanceof ProviderDuplicateWindow => $this->jitter(40, 15),
                default => $this->jitter($this->submitBackoff($claim['id']), 15),
            };

            $this->finishClaim($claim, $attemptAttrs + [
                'fulfillment_status' => FulfillmentStatus::QUEUED,
                'next_submit_at' => now()->addSeconds($delay),
                'last_error_code' => $e->errorCode,
                'last_error_message' => Str::limit($e->getMessage(), 250, ''),
            ], 'submit_retry_scheduled', $e->errorCode.'; retry in '.$delay.'s with the same reference'.($notCounted ? ' (not counted as an attempt)' : ''), $e->httpStatus, FulfillmentStatus::QUEUED);

            // No delayed self-dispatch: the sweeper picks the order up once next_submit_at is due.
            return 'requeued';
        }

        // Provider wallet empty / biller or service disabled: paid-but-unfulfilled, recoverable.
        $attentionDelay = ($e instanceof ProviderInsufficientBalance || $e instanceof ProviderServiceDisabled) ? 900 : null;

        $this->finishClaim($claim, $attemptAttrs + [
            'fulfillment_status' => FulfillmentStatus::ATTENTION,
            'next_submit_at' => $attentionDelay ? now()->addSeconds($attentionDelay) : null,
            'last_error_code' => $e->errorCode,
            'last_error_message' => Str::limit($e->getMessage(), 250, ''),
        ], 'needs_attention', $e->errorCode, $e->httpStatus, FulfillmentStatus::ATTENTION);

        if ($e instanceof ProviderInsufficientBalance) {
            // A provider-wide condition: pause new sales and alert ONCE per incident, not per order.
            $this->providerWalletEmpty();

            return 'attention';
        }

        // Alert when the order newly enters this problem, not again on every automatic retry.
        $u = UtilityBillOrder::query()->find($claim['id']);
        if ($u && $claim['prev_error'] !== $e->errorCode) {
            $this->alertAdmin($u, 'Utility Bill needs attention', $this->attentionMessage($u, $e->errorCode));
        }

        return 'attention';
    }

    // ------------------------------------------------------------------
    // Provider wallet incident
    // ------------------------------------------------------------------

    private function providerWalletEmpty(): void
    {
        if (! UtilityBillSettings::pauseForProviderWallet()) {
            return;
        }

        $waiting = UtilityBillOrder::query()
            ->where('fulfillment_status', FulfillmentStatus::ATTENTION)
            ->where('last_error_code', 'insufficient_balance')
            ->count();

        Log::error('utility_bills.provider_wallet.paused', ['waiting_orders' => $waiting]);

        try {
            AdminNotification::create([
                'type' => 'utility_bill_attention',
                'title' => 'KiNG FLEXY wallet too low: Utility Bills sales paused',
                'message' => 'KiNG FLEXY reported its wallet cannot cover a bill payment. New Utility Bills sales are paused automatically. '
                    .$waiting.' paid '.Str::plural('order', $waiting).' will keep retrying with their existing references. '
                    .'Top up the KiNG FLEXY wallet; sales resume on the next successful payment, or resume them from Utility Bills Settings.',
                'data' => ['incident' => 'provider_wallet_low', 'waiting_orders' => $waiting],
            ]);
        } catch (\Throwable $e) {
            Log::warning('utility_bills.notify.admin_failed', ['error' => class_basename($e)]);
        }
    }

    /**
     * End a wallet incident (a provider payment succeeded, or an admin resumed): reopen
     * sales and make the orders that were waiting on the wallet due now instead of in
     * up to 15 minutes. Their provider references are unchanged.
     *
     * @return int orders made due now
     */
    public function resumeAfterProviderWallet(?string $actor = null): int
    {
        if (! UtilityBillSettings::resumeProviderWallet()) {
            return 0;
        }

        $woken = UtilityBillOrder::query()
            ->where('fulfillment_status', FulfillmentStatus::ATTENTION)
            ->where('last_error_code', 'insufficient_balance')
            ->whereNull('provider_order_reference')
            ->update(['next_submit_at' => now(), 'updated_at' => now()]);

        Log::info('utility_bills.provider_wallet.resumed', ['actor' => $actor ?? 'system', 'orders_due_now' => $woken]);

        return $woken;
    }

    // ------------------------------------------------------------------
    // Status synchronisation
    // ------------------------------------------------------------------

    /**
     * Poll the provider for one in-flight order, by the PROVIDER's reference (always the
     * EXISTING provider order; nothing is ever re-sent from here). Automatic polling covers
     * pending/processing orders; an admin refresh ($adminActor set) may also query an order
     * whose automatic polling stopped (provider_unresolved). Both draw on the shared status budget.
     *
     * @return string skipped | rate_limited | error | not_found | updated
     */
    public function syncStatus(int $utilityBillOrderId, ?string $adminActor = null): string
    {
        $u = UtilityBillOrder::query()->find($utilityBillOrderId);
        $allowed = $adminActor !== null ? FulfillmentStatus::REFRESHABLE : FulfillmentStatus::POLLABLE;

        if (! $u || ! in_array($u->fulfillment_status, $allowed, true) || $u->provider_order_reference === null) {
            return 'skipped';
        }

        if ($adminActor !== null) {
            $this->event($u, 'admin_status_refresh', null, null, null, 'queried provider order '.$u->provider_order_reference, $adminActor);
        }

        try {
            $status = $this->provider->status($u->provider_order_reference);
        } catch (ProviderRateLimited) {
            $this->scheduleNextCheck($u->id, 45, false);

            return 'rate_limited';
        } catch (ProviderNotFound) {
            $this->scheduleNextCheck($u->id, 600);
            $this->event($u, 'status_not_found', null, null, 404, 'provider has no order for our stored reference', 'system');

            return 'not_found';
        } catch (UtilityProviderException $e) {
            Log::warning('utility_bills.status.sync_failed', ['utility_bill_order_id' => $u->id, 'error_code' => $e->errorCode]);
            $this->scheduleNextCheck($u->id, $this->backoff($u->status_check_attempts));

            return 'error';
        }

        $this->applyProviderStatus($u->id, $status);

        return 'updated';
    }

    /**
     * Apply a provider status to the order under a row lock. Terminal states
     * never regress; unrecognised statuses change nothing.
     */
    public function applyProviderStatus(int $id, StatusResult $status): void
    {
        DB::transaction(function () use ($id, $status) {
            $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();
            if (! $u) {
                return;
            }

            // Defence against a forged/misrouted answer: it must be about OUR provider order.
            if ($u->provider_order_reference !== null && ! hash_equals($u->provider_order_reference, $status->providerReference)) {
                Log::error('utility_bills.status.reference_mismatch', ['utility_bill_order_id' => $u->id]);

                return;
            }

            $mapped = ProviderStatusMapper::toFulfillmentStatus($status->status);

            if ($mapped === null) {
                $this->event($u, 'status_unrecognised', $u->fulfillment_status, null, null, 'ignored unrecognised provider status', 'system');

                return;
            }

            if (FulfillmentStatus::isTerminal($u->fulfillment_status)) {
                if ($mapped !== $u->fulfillment_status) {
                    $this->event($u, 'status_contradiction', $u->fulfillment_status, $mapped, null, 'provider reported '.$status->status.' for an already-terminal order; not applied', 'system');
                    Log::error('utility_bills.status.contradiction', ['utility_bill_order_id' => $u->id, 'local' => $u->fulfillment_status, 'provider' => $status->status]);
                }

                return;
            }

            if (! in_array($u->fulfillment_status, FulfillmentStatus::REFRESHABLE, true)) {
                return;
            }

            // Automatic polling stopped for this order. A non-final answer is recorded but changes
            // nothing else; a final one (below) is applied exactly as it would have been in time.
            if ($u->fulfillment_status === FulfillmentStatus::PROVIDER_UNRESOLVED && ! FulfillmentStatus::isTerminal($mapped)) {
                $u->forceFill([
                    'provider_status' => $status->status,
                    'provider_payment_status' => $status->paymentStatus,
                    'provider_status_reason' => $status->reason,
                    'last_status_check_at' => now(),
                ])->save();
                $this->event($u, 'status_still_unresolved', FulfillmentStatus::PROVIDER_UNRESOLVED, null, null, 'provider status '.$status->status.'; automatic polling stays stopped', 'provider');

                return;
            }

            // pending -> processing is forward; never move backwards.
            if ($u->fulfillment_status === FulfillmentStatus::PROVIDER_PROCESSING && $mapped === FulfillmentStatus::PROVIDER_PENDING) {
                return;
            }

            $from = $u->fulfillment_status;
            $attrs = [
                'fulfillment_status' => $mapped,
                'provider_status' => $status->status,
                'provider_payment_status' => $status->paymentStatus,
                'provider_status_reason' => $status->reason,
                'last_status_check_at' => now(),
                'status_check_attempts' => $u->status_check_attempts + 1,
                'next_status_check_at' => FulfillmentStatus::isTerminal($mapped) ? null : now()->addSeconds($this->backoff($u->status_check_attempts + 1)),
            ];

            if ($status->commissionEarned !== null) {
                $attrs['provider_commission_earned'] = $status->commissionEarned;
            }
            if ($mapped === FulfillmentStatus::COMPLETED) {
                $attrs['fulfilled_at'] = now();
            }

            $u->forceFill($attrs)->save();

            if ($mapped !== $from) {
                $this->event($u, 'provider_status', $from, $mapped, null, 'provider status '.$status->status, 'provider');
            }
        });

        $this->afterStatusChange($id);
    }

    /**
     * Automatic polling horizon reached (status_poll_max_checks or status_poll_max_hours) without a
     * final provider status: stop routine polling and surface the order. Payment, both references,
     * provider status, attempt history, attribution and frozen commission terms are untouched; the
     * order is NOT failed, refunded or re-sent, and an admin refresh still queries the same order.
     *
     * @return bool true when this call moved the order
     */
    public function markStatusUnresolved(int $id): bool
    {
        $moved = DB::transaction(function () use ($id) {
            $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();

            if (! $u || ! in_array($u->fulfillment_status, FulfillmentStatus::POLLABLE, true) || ! $this->pollingExhausted($u)) {
                return null;
            }

            $from = $u->fulfillment_status;
            $since = $u->status_poll_started_at ?? $u->submitted_at;
            $u->forceFill(['fulfillment_status' => FulfillmentStatus::PROVIDER_UNRESOLVED, 'next_status_check_at' => null])->save();
            $this->event($u, 'status_polling_stopped', $from, FulfillmentStatus::PROVIDER_UNRESOLVED, null,
                $u->status_check_attempts.' automatic checks since '.($since?->toDateTimeString() ?? '?').' without a final status (provider last said '
                .($u->provider_status ?? 'nothing').'); polling stopped, not failed; provider order '.$u->provider_order_reference.' kept', 'system');

            return $u;
        });

        if (! $moved) {
            return false;
        }

        $this->alertAdmin($moved, 'Utility Bill provider status unresolved', $this->attentionMessage($moved, FulfillmentStatus::PROVIDER_UNRESOLVED));

        return true;
    }

    /** The automatic polling horizon (count or time, whichever first) has been reached. */
    public function pollingExhausted(UtilityBillOrder $u): bool
    {
        $since = $u->status_poll_started_at ?? $u->submitted_at;

        return $u->status_check_attempts >= max(1, (int) config('utility_bills.status_poll_max_checks', 30))
            || ($since !== null && $since->lte(now()->subHours(max(1, (int) config('utility_bills.status_poll_max_hours', 24)))));
    }

    /**
     * Controlled recovery: give an unresolved order a fresh automatic polling window (same provider
     * order, same references). Nothing is sent to the pay endpoint.
     *
     * @param  array{id:?int,email:?string}  $actor
     * @return array{ok:bool,message:string}
     */
    public function adminResumePolling(int $id, array $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();

            if (! $u || $u->fulfillment_status !== FulfillmentStatus::PROVIDER_UNRESOLVED || $u->provider_order_reference === null) {
                return ['ok' => false, 'message' => 'Automatic status checks can only be resumed for an order whose provider status is unresolved.'];
            }

            $to = ProviderStatusMapper::toFulfillmentStatus($u->provider_status) === FulfillmentStatus::PROVIDER_PROCESSING
                ? FulfillmentStatus::PROVIDER_PROCESSING
                : FulfillmentStatus::PROVIDER_PENDING;

            $u->forceFill([
                'fulfillment_status' => $to,
                'status_check_attempts' => 0,
                'status_poll_started_at' => now(),
                'next_status_check_at' => now(),
            ])->save();
            $this->event($u, 'admin_resume_polling', FulfillmentStatus::PROVIDER_UNRESOLVED, $to, null, 'new automatic polling window for provider order '.$u->provider_order_reference, 'admin:'.($actor['id'] ?? '?'));

            return ['ok' => true, 'message' => 'Automatic status checks resumed for provider order '.$u->provider_order_reference.'.'];
        });
    }

    private function scheduleNextCheck(int $id, int $seconds, bool $countAttempt = true): void
    {
        $attrs = ['last_status_check_at' => now(), 'next_status_check_at' => now()->addSeconds($seconds)];
        $query = UtilityBillOrder::query()->whereKey($id)->whereIn('fulfillment_status', FulfillmentStatus::POLLABLE);

        $query->update($attrs);
        if ($countAttempt) {
            $query->increment('status_check_attempts');
        }
    }

    /**
     * Alert once that a PAID order has not finished in time. Called by the sweeper for every
     * order past the threshold, whatever the provider has (or has not) answered: waiting on
     * our pay budget, retrying, or pending at a provider whose status endpoint keeps failing.
     *
     * @return bool true when this call sent the alert
     */
    public function raiseStuckAlert(int $id): bool
    {
        $u = UtilityBillOrder::query()->find($id);

        if (! $u || ! in_array($u->fulfillment_status, FulfillmentStatus::STUCK_ALERTABLE, true)) {
            return false;
        }

        if (! $this->claimMarker($u, 'stuck_alerted_at')) {
            return false;
        }

        $minutes = (int) config('utility_bills.status_attention_after_minutes');
        $this->event($u, 'stuck_alert', $u->fulfillment_status, null, null, 'not terminal '.$minutes.' minutes after payment', 'system');

        $state = in_array($u->fulfillment_status, FulfillmentStatus::POLLABLE, true)
            ? 'has not reached a final provider status'
            : 'has not been accepted by KiNG FLEXY yet ('.FulfillmentStatus::label($u->fulfillment_status).($u->last_error_code ? ', last error: '.$u->last_error_code : '').')';

        // Never auto-failed and never auto-refunded: a human decides.
        $this->alertAdmin($u, 'Utility Bill still processing', $u->biller_label.' bill '.$u->public_ref.' (GHS '.number_format((float) $u->bill_amount, 2).') was paid over '.$minutes.' minutes ago and '.$state.'. Check it in Utility Bill Sales.');

        return true;
    }

    /**
     * Atomically claim a "send once" marker: only the caller whose conditional UPDATE sets
     * the still-null column may perform the side effect, so concurrent callers never both send.
     */
    private function claimMarker(UtilityBillOrder $u, string $column): bool
    {
        $claimed = UtilityBillOrder::query()->whereKey($u->id)->whereNull($column)->update([$column => now()]) === 1;

        if ($claimed) {
            $u->setAttribute($column, now());
        }

        return $claimed;
    }

    // ------------------------------------------------------------------
    // After a (possibly terminal) change
    // ------------------------------------------------------------------

    /** Idempotent side effects of the order's CURRENT state. Safe to call repeatedly. */
    public function afterStatusChange(int $id): void
    {
        $u = UtilityBillOrder::query()->find($id);

        if (! $u) {
            return;
        }

        if ($u->fulfillment_status === FulfillmentStatus::COMPLETED) {
            Order::query()->whereKey($u->order_id)->where('status', '!=', 'Completed')->update(['status' => 'Completed']);

            $this->commission->settle($u->id);
            $this->notifyCustomerOnce($u);

            return;
        }

        if (in_array($u->fulfillment_status, [FulfillmentStatus::FAILED, FulfillmentStatus::PROVIDER_REFUNDED], true)
            && $this->claimMarker($u, 'terminal_alerted_at')) {
            $this->event($u, 'terminal_alert', null, $u->fulfillment_status, null, null, 'system');
            $this->alertAdmin($u, 'Utility Bill not completed', $this->attentionMessage($u, $u->fulfillment_status));
        }
    }

    private function notifyCustomerOnce(UtilityBillOrder $u): void
    {
        if (! $u->customer_phone || ! $this->claimMarker($u, 'customer_notified_at')) {
            return;
        }

        $this->event($u, 'customer_notified', null, null, null, 'sms', 'system');

        try {
            $sms = app(SmsService::class);
            if ($sms->isConfigured()) {
                $sms->send($u->customer_phone, 'XTRA4U: your '.$u->biller_label.' payment of GHS '.number_format((float) $u->bill_amount, 2).' for '.$u->maskedAccount().' is complete. Ref '.$u->public_ref.'.');
            }
        } catch (\Throwable $e) {
            Log::warning('utility_bills.notify.sms_failed', ['utility_bill_order_id' => $u->id, 'error' => class_basename($e)]);
        }
    }

    // ------------------------------------------------------------------
    // Admin recovery
    // ------------------------------------------------------------------

    /**
     * @param  array{id:?int,email:?string}  $actor
     * @return array{ok:bool,message:string}
     */
    public function adminRetry(int $id, array $actor, bool $newAttempt = false, ?string $reason = null): array
    {
        $dispatch = false;

        // A NEW provider reference is the one action that can cause a second bill payment, so it is
        // gated BEFORE anything is locked: explicit reason, and a LIVE confirmation from the provider
        // that the previous attempt is closed (refunded) and can never complete.
        if ($newAttempt) {
            $reason = trim((string) $reason);
            if (mb_strlen($reason) < 5) {
                return ['ok' => false, 'message' => 'A reason (at least 5 characters) is required to start a new provider attempt.'];
            }

            if (($refusal = $this->confirmAttemptClosedWithProvider($id)) !== null) {
                return ['ok' => false, 'message' => $refusal];
            }
        }

        $result = DB::transaction(function () use ($id, $actor, $newAttempt, $reason, &$dispatch) {
            $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();
            if (! $u) {
                return ['ok' => false, 'message' => 'Order not found.'];
            }

            $order = Order::query()->whereKey($u->order_id)->first();
            if (! $order || ! in_array($order->payment_status, ['paid', 'completed'], true) || ! $order->allowsFulfillment()) {
                return ['ok' => false, 'message' => 'The customer payment is not confirmed; nothing can be submitted.'];
            }

            $who = 'admin:'.($actor['id'] ?? '?');

            switch ($u->fulfillment_status) {
                case FulfillmentStatus::COMPLETED:
                    return ['ok' => false, 'message' => 'This bill is already completed.'];

                case FulfillmentStatus::AWAITING_PAYMENT:
                    return ['ok' => false, 'message' => 'Awaiting customer payment.'];

                case FulfillmentStatus::PROVIDER_PENDING:
                case FulfillmentStatus::PROVIDER_PROCESSING:
                    return ['ok' => false, 'message' => 'The provider is still processing this bill. Refresh its status instead.'];

                case FulfillmentStatus::PROVIDER_UNRESOLVED:
                    return ['ok' => false, 'message' => 'KiNG FLEXY already holds this order; its final status is unresolved. Refresh its status (or resume automatic checks) instead. It is never re-sent.'];

                case FulfillmentStatus::SUBMITTING:
                    if ($u->claimed_at && $u->claimed_at->gt(now()->subSeconds((int) config('utility_bills.claim_stale_seconds')))) {
                        return ['ok' => false, 'message' => 'A submission is in progress.'];
                    }
                    // fall through: a stale claim is recoverable with the SAME reference.

                case FulfillmentStatus::QUEUED:
                case FulfillmentStatus::ATTENTION:
                    if ($u->provider_order_reference !== null) {
                        return ['ok' => false, 'message' => 'The provider already holds an order; refresh its status instead.'];
                    }
                    $from = $u->fulfillment_status;
                    $u->forceFill([
                        'fulfillment_status' => FulfillmentStatus::QUEUED,
                        'next_submit_at' => now(),
                        'claim_token' => null,
                        'claimed_at' => null,
                        'submit_attempts' => 0,
                    ])->save();
                    $this->event($u, 'admin_retry', $from, FulfillmentStatus::QUEUED, null, 'same provider reference '.($u->provider_request_reference ?? '(not yet generated)'), $who);
                    $dispatch = true;

                    return ['ok' => true, 'message' => 'Re-queued with the same provider reference.'];

                case FulfillmentStatus::FAILED:
                    // The provider docs list "failed" in the status flow but do not define it for utility
                    // orders (only "refunded" is documented as the definitive failure that refunds the
                    // wallet). Until KiNG FLEXY confirms it is terminal and unbilled, no new reference.
                    return ['ok' => false, 'message' => 'The provider reported "failed", which its documentation does not define as final-and-refunded for utility bills. Confirm the outcome with KiNG FLEXY support before any further payment; a new provider attempt is not permitted from this state.'];

                case FulfillmentStatus::PROVIDER_REFUNDED:
                    if (! $newAttempt) {
                        return ['ok' => false, 'message' => 'The provider refunded this attempt. Retry cannot reuse it. If the customer still needs this bill, start a NEW provider attempt (new reference), which requires a reason and explicit confirmation.'];
                    }
                    if ($u->provider_status !== 'refunded' || $u->provider_order_reference === null) {
                        return ['ok' => false, 'message' => 'The previous attempt is not confirmed as refunded by the provider.'];
                    }
                    $from = $u->fulfillment_status;
                    $oldRequest = $u->provider_request_reference;
                    $oldOrder = $u->provider_order_reference;
                    $attempt = $u->provider_attempt + 1;
                    // Preserve the closed attempt as a structured, append-only record BEFORE the columns are reused.
                    $this->event($u, UtilityBillEvent::KIND_ATTEMPT_CLOSED, $from, null, null,
                        'attempt '.$u->provider_attempt.' closed (refunded); new attempt '.$attempt, $who, [
                            'attempt' => $u->provider_attempt,
                            'request_reference' => $oldRequest,
                            'provider_order_reference' => $oldOrder,
                            'provider_status' => $u->provider_status,
                            'provider_status_reason' => $u->provider_status_reason,
                            'submitted_at' => $u->submitted_at?->toIso8601String(),
                            'closed_at' => now()->toIso8601String(),
                            'submit_attempts' => $u->submit_attempts,
                            'new_attempt' => $attempt,
                            'started_by' => $who,
                            'reason' => $reason,
                        ]);
                    $u->forceFill([
                        'provider_attempt' => $attempt,
                        'provider_request_reference' => $this->requestReference($u, $attempt),
                        'provider_order_reference' => null,
                        'provider_status' => null,
                        'provider_status_reason' => null,
                        'fulfillment_status' => FulfillmentStatus::QUEUED,
                        'next_submit_at' => now(),
                        'submit_attempts' => 0,
                        'status_check_attempts' => 0,
                        'next_status_check_at' => null,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'claim_token' => null,
                        'claimed_at' => null,
                        // A new attempt may be stuck or end unsuccessfully on its own: alert for it too.
                        'terminal_alerted_at' => null,
                        'stuck_alerted_at' => null,
                    ])->save();
                    $this->event($u, 'admin_new_attempt', $from, FulfillmentStatus::QUEUED, null, 'attempt '.$attempt.'; previous request '.$oldRequest.' / provider order '.$oldOrder, $who);
                    $dispatch = true;

                    return ['ok' => true, 'message' => 'New provider attempt queued (attempt '.$attempt.').'];
            }

            return ['ok' => false, 'message' => 'Not retryable in its current state.'];
        });

        if ($dispatch) {
            $this->dispatchSubmit($id);
        }

        return $result;
    }

    /**
     * Ask the PROVIDER, now, whether the previous attempt is closed. Returns a refusal message, or null
     * when it is authoritatively `refunded` (the documented definitive failure; the wallet is refunded).
     * Timeouts, unknown statuses and anything else refuse: they are never grounds for a new reference.
     */
    private function confirmAttemptClosedWithProvider(int $id): ?string
    {
        $u = UtilityBillOrder::query()->find($id);

        if (! $u) {
            return 'Order not found.';
        }

        if ($u->fulfillment_status !== FulfillmentStatus::PROVIDER_REFUNDED || $u->provider_order_reference === null) {
            return 'A new provider attempt is only possible after the provider has confirmed the previous attempt as refunded.';
        }

        try {
            $live = $this->provider->status($u->provider_order_reference);
        } catch (UtilityProviderException $e) {
            return 'Could not confirm the previous attempt with the provider ('.$e->errorCode.'). Nothing was changed; try again shortly. A new reference is never created on an unknown result.';
        }

        if ($live->status !== 'refunded') {
            // Record what the provider really says (e.g. it moved to completed), but never start N+1.
            $this->applyProviderStatus($u->id, $live);

            return 'The provider no longer reports this attempt as refunded (it says "'.($live->status ?? 'unknown').'"). No new attempt was started.';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function requestReference(UtilityBillOrder $u, ?int $attempt = null): string
    {
        return 'XU-'.$u->public_ref.'-A'.($attempt ?? $u->provider_attempt);
    }

    private function backoff(int $checksSoFar): int
    {
        $steps = array_values((array) config('utility_bills.status_backoff'));

        return (int) ($steps[min($checksSoFar, count($steps) - 1)] ?? 300);
    }

    /** $base plus up to $spread random seconds, so a burst of retries does not fire in lockstep. */
    private function jitter(int $base, int $spread): int
    {
        return max(1, $base) + random_int(0, max(0, $spread));
    }

    /** An older paid order is queued and due (waiting on budget, a retry, or a lost job). */
    private function olderSubmissionWaiting(UtilityBillOrder $u): bool
    {
        return UtilityBillOrder::query()
            ->where('fulfillment_status', FulfillmentStatus::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_submit_at')->orWhere('next_submit_at', '<=', now()))
            ->whereNull('provider_order_reference')
            ->where('id', '<', $u->id)
            ->exists();
    }

    private function submitBackoff(int $id): int
    {
        $attempts = (int) UtilityBillOrder::query()->whereKey($id)->value('submit_attempts');

        return min(900, 20 * (2 ** max(0, $attempts - 1)));
    }

    /**
     * Conditional write keyed on the claim token: only the worker that still owns the claim may record the result.
     */
    private function finishClaim(array $claim, array $attrs, string $kind, ?string $detail, ?int $http, string $to): bool
    {
        $affected = UtilityBillOrder::query()
            ->whereKey($claim['id'])
            ->where('claim_token', $claim['token'])
            ->where('fulfillment_status', FulfillmentStatus::SUBMITTING)
            ->update($attrs + ['claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);

        if ($affected !== 1) {
            Log::warning('utility_bills.fulfillment.claim_lost', ['utility_bill_order_id' => $claim['id']]);

            return false;
        }

        $u = UtilityBillOrder::query()->find($claim['id']);
        if ($u) {
            $this->event($u, $kind, FulfillmentStatus::SUBMITTING, $to, $http, $detail, 'system');
        }

        return true;
    }

    private function setAttention(UtilityBillOrder $u, string $code, string $message, ?int $http): void
    {
        $from = $u->fulfillment_status;
        $u->forceFill([
            'fulfillment_status' => FulfillmentStatus::ATTENTION,
            'claim_token' => null,
            'claimed_at' => null,
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();

        $this->event($u, 'needs_attention', $from, FulfillmentStatus::ATTENTION, $http, $code, 'system');
        $this->alertAdmin($u, 'Utility Bill needs attention', $this->attentionMessage($u, $code));
    }

    /** Short admin label for why an order needs attention (never shown to customers). */
    public static function reasonLabel(?string $code): string
    {
        return match ($code) {
            'insufficient_balance' => 'Provider wallet low',
            'disabled' => 'Provider/biller disabled',
            'auth', 'not_configured' => 'Provider key rejected',
            'timeout' => 'Provider timeouts',
            'upstream', 'malformed' => 'Provider unavailable',
            'rate_limited' => 'Provider rate limit',
            'max_attempts' => 'Retries exhausted',
            'payment_unconfirmed' => 'Payment unconfirmed',
            'reference_conflict' => 'Reference conflict',
            'rejected' => 'Rejected by provider',
            null, '' => 'Needs review',
            default => ucfirst(str_replace('_', ' ', $code)),
        };
    }

    private function attentionMessage(UtilityBillOrder $u, string $code): string
    {
        if ($code === 'payment_unconfirmed') {
            return $u->biller_label.' bill '.$u->public_ref.' (GHS '.number_format((float) $u->bill_amount, 2).') was queued for the provider, but its customer payment is no longer confirmed for fulfillment. Nothing was sent to KiNG FLEXY. Review the payment before retrying from Utility Bill Sales.';
        }

        $reason = match ($code) {
            'insufficient_balance' => 'the KiNG FLEXY provider wallet is too low',
            'disabled' => 'the provider has disabled this service or biller',
            'auth', 'not_configured' => 'the provider API key was rejected or is missing',
            'max_attempts' => 'automatic submission attempts were exhausted',
            FulfillmentStatus::FAILED => 'the provider reported the payment failed',
            FulfillmentStatus::PROVIDER_REFUNDED => 'the provider refunded its wallet',
            FulfillmentStatus::PROVIDER_UNRESOLVED => 'KiNG FLEXY gave no final status within the automatic polling window (provider order '.$u->provider_order_reference.'), so automatic checks stopped. It is not failed: refresh its status',
            default => 'the provider reported: '.$code,
        };

        return 'Customer payment received for '.$u->biller_label.' bill '.$u->public_ref.' (GHS '.number_format((float) $u->bill_amount, 2).') but '.$reason.'. The customer has paid; recover this order from Utility Bill Sales.';
    }

    private function alertAdmin(UtilityBillOrder $u, string $title, string $message): void
    {
        try {
            AdminNotification::create([
                'type' => 'utility_bill_attention',
                'title' => $title,
                'message' => $message,
                'data' => ['utility_bill_order_id' => $u->id, 'public_ref' => $u->public_ref],
            ]);
        } catch (\Throwable $e) {
            Log::warning('utility_bills.notify.admin_failed', ['error' => class_basename($e)]);
        }
    }

    private function event(UtilityBillOrder $u, string $kind, ?string $from, ?string $to, ?int $http, ?string $detail, ?string $actor, ?array $meta = null): void
    {
        $u->events()->create([
            'kind' => $kind,
            'from_status' => $from,
            'to_status' => $to,
            'http_status' => $http,
            'detail' => $detail !== null ? Str::limit($detail, 250, '') : null,
            'meta' => $meta,
            'actor' => $actor,
        ]);
    }
}
