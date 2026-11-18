<?php

namespace App\Services\DeveloperApi\Sandbox;

use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\SandboxOrder;
use App\Models\Developer\SandboxOrderEvent;
use App\Models\Developer\SandboxPayment;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE only code that changes the state of a sandbox order, and it only ever touches developer_sandbox_*
 * and developer_events (environment = 'sandbox'). Every change:
 *   - runs under a row lock on the sandbox order,
 *   - is validated against an explicit transition table (no arbitrary status can be injected: callers pass a
 *     step NAME from a fixed list, never a status value),
 *   - appends a history row and persists the matching webhook event(s) in the same transaction.
 *
 * Deliberately has NO dependency on payment gateways, wallets, commissions, inventory, SMS/email,
 * fulfilment providers or production order services. An architecture test pins that.
 */
class SandboxOrderTransitions
{
    public const STEP_PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const STEP_PAYMENT_FAILED = 'payment.failed';

    public const STEP_FULFILLMENT_PROCESSING = 'fulfillment.processing';

    public const STEP_FULFILLMENT_COMPLETED = 'fulfillment.completed';

    public const STEP_FULFILLMENT_FAILED = 'fulfillment.failed';

    public const STEPS = [
        self::STEP_PAYMENT_SUCCEEDED,
        self::STEP_PAYMENT_FAILED,
        self::STEP_FULFILLMENT_PROCESSING,
        self::STEP_FULFILLMENT_COMPLETED,
        self::STEP_FULFILLMENT_FAILED,
    ];

    /** Named, deterministic end-to-end scenarios. */
    public const SCENARIOS = [
        'success' => [self::STEP_PAYMENT_SUCCEEDED, self::STEP_FULFILLMENT_PROCESSING, self::STEP_FULFILLMENT_COMPLETED],
        'payment_failed' => [self::STEP_PAYMENT_FAILED],
        'fulfillment_failed' => [self::STEP_PAYMENT_SUCCEEDED, self::STEP_FULFILLMENT_PROCESSING, self::STEP_FULFILLMENT_FAILED],
    ];

    public function __construct(private readonly SandboxEventRecorder $events) {}

    /**
     * Records the creation of a freshly inserted order. Call inside the creating transaction.
     *
     * @return list<DeveloperEvent>
     */
    public function recordCreated(SandboxOrder $order): array
    {
        $events = $this->emit($order, ['order.created']);
        $this->history($order, 'order.created', null, $events);

        return $events;
    }

    /**
     * Start a simulated payment. The payment stays `pending` until the developer simulates an outcome.
     *
     * @return array{order:SandboxOrder, payment:SandboxPayment, events:list<DeveloperEvent>}
     */
    public function initiatePayment(int $orderId): array
    {
        $events = [];

        [$order, $payment] = DB::transaction(function () use ($orderId, &$events) {
            $order = SandboxOrder::query()->lockForUpdate()->findOrFail($orderId);

            // Same answers as production: a started payment is never started twice.
            if ($order->payment_reference) {
                throw ApiException::conflict(ApiError::PAYMENT_ALREADY_INITIATED, 'Payment was already initiated for this order. Poll the order status.', [
                    'reference' => $order->payment_reference,
                ]);
            }
            if ($order->payment_status !== 'unpaid' || $order->status !== 'awaiting_payment') {
                throw ApiException::conflict(ApiError::ORDER_NOT_PAYABLE, 'This order can no longer be paid.', ['status' => $order->payment_status]);
            }

            $from = $order->status;
            $payment = SandboxPayment::create([
                'payment_ref' => 'sbx_pay_'.Str::lower((string) Str::ulid()),
                'sandbox_order_id' => $order->id,
                'application_id' => $order->application_id,
                'vendor_id' => $order->vendor_id,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => 'pending',
            ]);

            $order->forceFill(['payment_reference' => $payment->payment_ref, 'payment_status' => 'pending']);
            $order->forceFill(['status' => $order->computeStatus()])->save();

            $events = $this->emit($order, ['payment.pending']);
            $this->history($order, 'payment.pending', $from, $events);

            return [$order, $payment];
        });

        $this->events->publish($events);

        return ['order' => $order->refresh(), 'payment' => $payment->refresh(), 'events' => $events];
    }

    /**
     * Apply predefined simulation steps in order, atomically (all or nothing).
     *
     * @param  list<string>  $steps  names from self::STEPS
     * @param  bool  $lenient  scenarios tolerate steps the order has already passed; single events do not
     * @return array{order:SandboxOrder, applied:list<array{step:string,changed:bool}>, events:list<DeveloperEvent>}
     */
    public function simulate(int $orderId, array $steps, bool $lenient = false): array
    {
        foreach ($steps as $step) {
            if (! in_array($step, self::STEPS, true)) {
                // Defence in depth: the request layer already restricts this to the list.
                throw ApiException::unprocessable(ApiError::VALIDATION_FAILED, 'Unknown simulation step.');
            }
        }

        $events = [];
        $applied = [];

        $order = DB::transaction(function () use ($orderId, $steps, $lenient, &$events, &$applied) {
            $order = SandboxOrder::query()->lockForUpdate()->findOrFail($orderId);

            foreach ($steps as $step) {
                $applied[] = ['step' => $step, 'changed' => $this->apply($order, $step, $lenient, $events)];
            }

            return $order;
        });

        $this->events->publish($events);

        return ['order' => $order->refresh(), 'applied' => $applied, 'events' => $events];
    }

    // ---------------------------------------------------------------- steps

    /** @param  list<DeveloperEvent>  $events  collected by reference */
    private function apply(SandboxOrder $order, string $step, bool $lenient, array &$events): bool
    {
        return match ($step) {
            self::STEP_PAYMENT_SUCCEEDED => $this->paymentSucceeded($order, $events),
            self::STEP_PAYMENT_FAILED => $this->paymentFailed($order, $events),
            self::STEP_FULFILLMENT_PROCESSING => $this->fulfillmentProcessing($order, $lenient, $events),
            self::STEP_FULFILLMENT_COMPLETED => $this->fulfillmentCompleted($order, $events),
            self::STEP_FULFILLMENT_FAILED => $this->fulfillmentFailed($order, $events),
        };
    }

    private function paymentSucceeded(SandboxOrder $order, array &$events): bool
    {
        $this->requirePaymentStarted($order);

        if ($order->payment_status === 'paid') {
            return false; // replay: already in the requested state
        }
        if ($order->payment_status !== 'pending') {
            throw $this->notAllowed($order, self::STEP_PAYMENT_SUCCEEDED);
        }

        $from = $order->status;
        SandboxPayment::query()->where('sandbox_order_id', $order->id)->update([
            'status' => 'paid', 'outcome' => 'succeeded', 'simulated_at' => now(), 'updated_at' => now(),
        ]);
        $order->forceFill(['payment_status' => 'paid', 'fulfillment_status' => 'pending']);
        $order->forceFill(['status' => $order->computeStatus()])->save();

        $new = $this->emit($order, ['payment.succeeded']);
        $this->history($order, self::STEP_PAYMENT_SUCCEEDED, $from, $new);
        array_push($events, ...$new);

        return true;
    }

    private function paymentFailed(SandboxOrder $order, array &$events): bool
    {
        $this->requirePaymentStarted($order);

        if ($order->payment_status === 'failed') {
            return false;
        }
        if ($order->payment_status !== 'pending') {
            throw $this->notAllowed($order, self::STEP_PAYMENT_FAILED);
        }

        $from = $order->status;
        SandboxPayment::query()->where('sandbox_order_id', $order->id)->update([
            'status' => 'failed', 'outcome' => 'failed', 'simulated_at' => now(), 'updated_at' => now(),
        ]);
        $order->forceFill(['payment_status' => 'failed']);
        $order->forceFill(['status' => $order->computeStatus()])->save();

        // Same pair of events production derives for a failed payment: the payment, and the order it fails.
        $new = $this->emit($order, ['payment.failed', 'order.failed']);
        $this->history($order, self::STEP_PAYMENT_FAILED, $from, $new);
        array_push($events, ...$new);

        return true;
    }

    private function fulfillmentProcessing(SandboxOrder $order, bool $lenient, array &$events): bool
    {
        $this->requirePaid($order, self::STEP_FULFILLMENT_PROCESSING);

        if ($order->fulfillment_status === 'processing') {
            return false;
        }
        if ($lenient && $order->fulfillment_status === 'completed') {
            return false; // a scenario replayed over an order that already finished
        }
        if ($order->fulfillment_status !== 'pending') {
            throw $this->notAllowed($order, self::STEP_FULFILLMENT_PROCESSING);
        }

        $from = $order->status;
        $order->forceFill([
            'fulfillment_status' => 'processing',
            'fulfillment_result' => $this->result($order, ['status' => 'processing', 'message' => 'Simulated fulfillment is processing.', 'processing_at' => now()->toIso8601String()]),
        ]);
        $order->forceFill(['status' => $order->computeStatus()])->save();

        $new = $this->emit($order, ['order.processing']);
        $this->history($order, self::STEP_FULFILLMENT_PROCESSING, $from, $new);
        array_push($events, ...$new);

        return true;
    }

    private function fulfillmentCompleted(SandboxOrder $order, array &$events): bool
    {
        $this->requirePaid($order, self::STEP_FULFILLMENT_COMPLETED);

        if ($order->fulfillment_status === 'completed') {
            return false;
        }
        if (! in_array($order->fulfillment_status, ['pending', 'processing'], true)) {
            throw $this->notAllowed($order, self::STEP_FULFILLMENT_COMPLETED);
        }

        $from = $order->status;
        $order->forceFill([
            'fulfillment_status' => 'completed',
            'fulfillment_result' => $this->result($order, ['status' => 'completed', 'message' => 'Simulated delivery completed. Nothing was sent to any network.', 'completed_at' => now()->toIso8601String()]),
        ]);
        $order->forceFill(['status' => $order->computeStatus()])->save();

        $new = $this->emit($order, ['order.completed']);
        $this->history($order, self::STEP_FULFILLMENT_COMPLETED, $from, $new);
        array_push($events, ...$new);

        return true;
    }

    private function fulfillmentFailed(SandboxOrder $order, array &$events): bool
    {
        $this->requirePaid($order, self::STEP_FULFILLMENT_FAILED);

        if ($order->fulfillment_status === 'failed') {
            return false;
        }
        if (! in_array($order->fulfillment_status, ['pending', 'processing'], true)) {
            throw $this->notAllowed($order, self::STEP_FULFILLMENT_FAILED);
        }

        $from = $order->status;
        $order->forceFill([
            'fulfillment_status' => 'failed',
            'fulfillment_result' => $this->result($order, ['status' => 'failed', 'message' => 'Simulated fulfillment failure.', 'failure_reason' => 'SIMULATED_FULFILLMENT_FAILURE', 'failed_at' => now()->toIso8601String()]),
        ]);
        $order->forceFill(['status' => $order->computeStatus()])->save();

        $new = $this->emit($order, ['order.failed']);
        $this->history($order, self::STEP_FULFILLMENT_FAILED, $from, $new);
        array_push($events, ...$new);

        return true;
    }

    // -------------------------------------------------------------- helpers

    private function requirePaymentStarted(SandboxOrder $order): void
    {
        if ($order->payment_status === 'unpaid' || ! $order->payment_reference) {
            throw ApiException::conflict(ApiError::PAYMENT_NOT_INITIATED, 'Start a payment for this order (POST /orders/{id}/payments) before simulating its outcome.', [
                'status' => $order->status,
            ]);
        }
    }

    private function requirePaid(SandboxOrder $order, string $step): void
    {
        if ($order->payment_status !== 'paid') {
            throw $this->notAllowed($order, $step);
        }
    }

    private function notAllowed(SandboxOrder $order, string $step): ApiException
    {
        return ApiException::conflict(ApiError::SIMULATION_NOT_ALLOWED, "The step '{$step}' is not allowed in the order's current state.", [
            'step' => $step,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
        ]);
    }

    /** One stable simulated provider reference per order, created the first time fulfilment moves. */
    private function result(SandboxOrder $order, array $patch): array
    {
        $current = (array) ($order->fulfillment_result ?? []);

        return array_merge([
            'simulated' => true,
            'provider' => 'xtra4u_sandbox',
            'reference' => 'sbx_ful_'.Str::lower((string) Str::ulid()),
        ], $current, $patch);
    }

    /**
     * Persist the sandbox webhook events for a transition.
     *
     * @param  list<string>  $types
     * @return list<DeveloperEvent>
     */
    private function emit(SandboxOrder $order, array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            if ($event = $this->events->record($order, $type)) {
                $out[] = $event;
            }
        }

        return $out;
    }

    /** @param  list<DeveloperEvent>  $events */
    private function history(SandboxOrder $order, string $type, ?string $from, array $events): void
    {
        SandboxOrderEvent::create([
            'event_ref' => 'sbe_'.Str::lower((string) Str::ulid()),
            'sandbox_order_id' => $order->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $order->status,
            'payload' => [
                'simulated' => true,
                'payment_status' => $order->payment_status,
                'fulfillment_status' => $order->fulfillment_status,
                'webhook_events' => array_map(fn (DeveloperEvent $e) => $e->event_id, $events),
            ],
        ]);
    }
}
