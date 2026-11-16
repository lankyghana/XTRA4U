<?php

namespace App\Services\DeveloperApi;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use App\Support\PaymentFailureTransition;
use App\Support\PaymentVerificationState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Payment initiation and verification for API orders.
 *
 * Nothing here can mark an order paid on a caller's say-so: the ONLY path to
 * "paid" is the existing server-side chain
 *   gateway verification -> PaymentIntegrityGuard -> PaymentService::completeOrder,
 * the same chain webhooks and the reconciler use. This class only asks the
 * gateway, never tells it.
 */
class ApiPaymentService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentIntegrityGuard $integrity,
        private readonly \App\Services\DeveloperApi\Webhooks\DeveloperEventRecorder $events,
    ) {}

    /**
     * @return array{reference:?string,authorization_url:?string,flow_type:?string,checkout_config:?array}
     */
    public function initiate(Order $order): array
    {
        if ($order->payment_status !== 'unpaid' || $order->status !== 'Pending') {
            throw ApiException::conflict(ApiError::ORDER_NOT_PAYABLE, 'This order can no longer be paid.', ['status' => $this->paymentState($order)]);
        }

        // A reference means a charge may already exist at the gateway (even if the
        // initiation response was lost). Never start a second one.
        if ($order->payment_reference) {
            throw ApiException::conflict(ApiError::PAYMENT_ALREADY_INITIATED, 'Payment was already initiated for this order. Poll the order status.', [
                'reference' => $order->payment_reference,
            ]);
        }

        $lock = Cache::lock('api-pay-init:'.$order->id, 60);
        if (! $lock->get()) {
            throw ApiException::conflict(ApiError::PAYMENT_ALREADY_INITIATED, 'Payment initiation is already in progress for this order.');
        }

        try {
            $order->refresh();
            if ($order->payment_reference) {
                throw ApiException::conflict(ApiError::PAYMENT_ALREADY_INITIATED, 'Payment was already initiated for this order. Poll the order status.', [
                    'reference' => $order->payment_reference,
                ]);
            }

            // Same payer-email convention as web checkout: the seller's address.
            $email = Vendor::query()->whereKey($order->vendor_id)->value('email') ?: 'noreply@xtra4u.com';

            $init = $this->payments->initiatePayment($order, $email, (float) $order->expected_amount);
        } finally {
            $lock->release();
        }

        if (! ($init['success'] ?? false)) {
            Log::warning('Developer API payment initiation failed', ['order_id' => $order->id, 'gateway_message' => $init['message'] ?? null]);

            throw new ApiException(ApiError::PAYMENT_INITIATION_FAILED, 'We could not start the payment. Please try again shortly.', 502, [
                'reference' => $init['reference'] ?? null,
            ]);
        }

        try {
            $this->events->record($order->refresh(), 'payment.pending');
        } catch (\Throwable $e) {
            Log::warning('payment.pending event record failed', ['order_id' => $order->id]);
        }

        $isSdkFlow = ! empty($init['checkout_config']);

        return [
            'reference' => $init['reference'] ?? null,
            'authorization_url' => $init['authorization_url'] ?? null,
            'flow_type' => $isSdkFlow ? ($init['flow_type'] ?? null) : null,
            'checkout_config' => $isSdkFlow ? $init['checkout_config'] : null,
        ];
    }

    /**
     * Ask the gateway where this payment stands and let the existing trusted
     * pipeline settle it. Throttled per order; never throws to the caller.
     */
    public function refreshFromGateway(Order $order): void
    {
        if (! $order->payment_reference || in_array($order->payment_status, ['paid', 'completed', 'failed'], true)) {
            return;
        }

        $seconds = max(1, (int) config('developer_api.verify_throttle_seconds', 10));
        if (! Cache::add('api-verify:'.$order->id, 1, $seconds)) {
            return;
        }

        try {
            $verification = $this->payments->checkPaymentStatusForGateway($order->payment_reference, $order->payment_gateway);
            $state = PaymentVerificationState::from($verification);

            if ($state === PaymentVerificationState::FAILED) {
                if (PaymentFailureTransition::apply($order->id) === PaymentFailureTransition::FAILED) {
                    Transaction::where('order_id', $order->id)
                        ->whereNotIn('payment_status', ['completed', 'successful'])
                        ->update(['payment_status' => 'failed']);
                }

                return;
            }

            if ($state !== PaymentVerificationState::SUCCESS) {
                return; // pending / unknown: change nothing, the reconciler keeps watching
            }

            $result = $this->integrity->guard($order, $verification, $order->payment_gateway);
            if (! $result->passed) {
                return; // recorded for admin review by the guard; never settled
            }

            $order->amount_paid = $result->confirmedAmount;
            $this->payments->completeOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Developer API payment refresh failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /** unpaid | pending | paid | failed — derived from existing columns only. */
    public function paymentState(Order $order): string
    {
        return \App\Http\Resources\DeveloperApi\OrderResource::paymentState($order);
    }
}
