<?php

namespace App\Services\UtilityBills;

use App\Jobs\SubmitUtilityBillPayment;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\UtilityBillOrder;
use App\Services\SmsService;
use App\Services\UtilityBills\Data\PayResult;
use App\Services\UtilityBills\Data\StatusResult;
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

    public function dispatchSubmit(int $utilityBillOrderId, int $delaySeconds = 0): void
    {
        $pending = SubmitUtilityBillPayment::dispatch($utilityBillOrderId)->afterCommit();

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    // ------------------------------------------------------------------
    // Submit
    // ------------------------------------------------------------------

    /**
     * @return string one of: skipped:<why> | submitted | requeued | attention | completed | claim_lost
     */
    public function submit(int $utilityBillOrderId, bool $force = false): string
    {
        $claim = $this->claim($utilityBillOrderId, $force);

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
     * @return array{id:int,token:string,reference:string,biller:string,account:string,amount:string,phone:?string}|string
     */
    private function claim(int $id, bool $force): array|string
    {
        return DB::transaction(function () use ($id, $force) {
            $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();

            if (! $u) {
                return 'skipped:missing';
            }

            // Payment proof is re-checked under the lock, from the envelope order.
            $order = Order::query()->whereKey($u->order_id)->first();
            if (! $order
                || ! in_array($order->payment_status, ['paid', 'completed'], true)
                || ! $order->allowsFulfillment()) {
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

        // Ambiguous or transient outcomes keep the SAME reference and go back to
        // the queue: a replay either returns the order the provider already
        // created, or creates it once.
        $transient = $e->isAmbiguous()
            || $e instanceof ProviderRateLimited
            || $e instanceof ProviderMalformedResponse;

        if ($transient) {
            $delay = $e instanceof ProviderRateLimited ? 30 : $this->submitBackoff($claim['id']);

            $this->finishClaim($claim, [
                'fulfillment_status' => FulfillmentStatus::QUEUED,
                'next_submit_at' => now()->addSeconds($delay),
                'last_error_code' => $e->errorCode,
                'last_error_message' => Str::limit($e->getMessage(), 250, ''),
            ], 'submit_retry_scheduled', $e->errorCode.'; retry in '.$delay.'s with the same reference', $e->httpStatus, FulfillmentStatus::QUEUED);

            $this->dispatchSubmit($claim['id'], $delay);

            return 'requeued';
        }

        // Provider wallet empty / biller or service disabled: paid-but-unfulfilled, recoverable.
        $attentionDelay = ($e instanceof ProviderInsufficientBalance || $e instanceof ProviderServiceDisabled) ? 900 : null;

        $this->finishClaim($claim, [
            'fulfillment_status' => FulfillmentStatus::ATTENTION,
            'next_submit_at' => $attentionDelay ? now()->addSeconds($attentionDelay) : null,
            'last_error_code' => $e->errorCode,
            'last_error_message' => Str::limit($e->getMessage(), 250, ''),
        ], 'needs_attention', $e->errorCode, $e->httpStatus, FulfillmentStatus::ATTENTION);

        $u = UtilityBillOrder::query()->find($claim['id']);
        if ($u) {
            $this->alertAdmin($u, 'Utility Bill needs attention', $this->attentionMessage($u, $e->errorCode));
        }

        return 'attention';
    }

    // ------------------------------------------------------------------
    // Status synchronisation
    // ------------------------------------------------------------------

    /**
     * Poll the provider for one in-flight order, by the PROVIDER's reference.
     *
     * @return string skipped | rate_limited | error | not_found | updated
     */
    public function syncStatus(int $utilityBillOrderId): string
    {
        $u = UtilityBillOrder::query()->find($utilityBillOrderId);

        if (! $u || ! in_array($u->fulfillment_status, FulfillmentStatus::POLLABLE, true) || $u->provider_order_reference === null) {
            return 'skipped';
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
        $this->raiseStuckAlertIfDue($u->id);

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

            if (! in_array($u->fulfillment_status, FulfillmentStatus::POLLABLE, true)) {
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

    private function scheduleNextCheck(int $id, int $seconds, bool $countAttempt = true): void
    {
        $attrs = ['last_status_check_at' => now(), 'next_status_check_at' => now()->addSeconds($seconds)];
        $query = UtilityBillOrder::query()->whereKey($id)->whereIn('fulfillment_status', FulfillmentStatus::POLLABLE);

        $query->update($attrs);
        if ($countAttempt) {
            $query->increment('status_check_attempts');
        }
    }

    private function raiseStuckAlertIfDue(int $id): void
    {
        $u = UtilityBillOrder::query()->find($id);

        if (! $u || ! in_array($u->fulfillment_status, FulfillmentStatus::POLLABLE, true) || ! $u->submitted_at) {
            return;
        }

        if ($u->submitted_at->gt(now()->subMinutes((int) config('utility_bills.status_attention_after_minutes')))) {
            return;
        }

        if ($u->events()->where('kind', 'stuck_alert')->exists()) {
            return;
        }

        $this->event($u, 'stuck_alert', $u->fulfillment_status, null, null, 'not terminal after '.config('utility_bills.status_attention_after_minutes').' minutes', 'system');
        // Never auto-failed and never auto-refunded: a human decides.
        $this->alertAdmin($u, 'Utility Bill still processing', $u->biller_label.' bill '.$u->public_ref.' has not reached a final provider status.');
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
            && ! $u->events()->where('kind', 'terminal_alert')->exists()) {
            $this->event($u, 'terminal_alert', null, $u->fulfillment_status, null, null, 'system');
            $this->alertAdmin($u, 'Utility Bill not completed', $this->attentionMessage($u, $u->fulfillment_status));
        }
    }

    private function notifyCustomerOnce(UtilityBillOrder $u): void
    {
        if (! $u->customer_phone || $u->events()->where('kind', 'customer_notified')->exists()) {
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
    public function adminRetry(int $id, array $actor, bool $newAttempt = false): array
    {
        $dispatch = false;

        $result = DB::transaction(function () use ($id, $actor, $newAttempt, &$dispatch) {
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
                case FulfillmentStatus::PROVIDER_REFUNDED:
                    if (! $newAttempt) {
                        return ['ok' => false, 'message' => 'The provider closed this attempt. A NEW provider attempt must be confirmed explicitly.'];
                    }
                    $from = $u->fulfillment_status;
                    $oldRequest = $u->provider_request_reference;
                    $oldOrder = $u->provider_order_reference;
                    $attempt = $u->provider_attempt + 1;
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

    private function attentionMessage(UtilityBillOrder $u, string $code): string
    {
        $reason = match ($code) {
            'insufficient_balance' => 'the KiNG FLEXY provider wallet is too low',
            'disabled' => 'the provider has disabled this service or biller',
            'auth', 'not_configured' => 'the provider API key was rejected or is missing',
            'max_attempts' => 'automatic submission attempts were exhausted',
            FulfillmentStatus::FAILED => 'the provider reported the payment failed',
            FulfillmentStatus::PROVIDER_REFUNDED => 'the provider refunded its wallet',
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

    private function event(UtilityBillOrder $u, string $kind, ?string $from, ?string $to, ?int $http, ?string $detail, ?string $actor): void
    {
        $u->events()->create([
            'kind' => $kind,
            'from_status' => $from,
            'to_status' => $to,
            'http_status' => $http,
            'detail' => $detail !== null ? Str::limit($detail, 250, '') : null,
            'actor' => $actor,
        ]);
    }
}
