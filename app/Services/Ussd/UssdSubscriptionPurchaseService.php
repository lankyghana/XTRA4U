<?php

namespace App\Services\Ussd;

use App\Jobs\SendUssdSubscriptionNotification;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use App\Services\PaymentService;
use App\Services\PaystackPaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Buys a USSD plan through the platform's existing payment stack.
 *
 * This deliberately reuses PaymentService::initiateGenericPayment() and the
 * dedicated-callback-route pattern already proven by vendor wallet top-ups.
 * It does NOT go through PaymentCallbackController, which resolves references
 * against the `orders` table and would 404 on a subscription reference.
 *
 * No payment status is ever written by hand — a subscription only becomes
 * active after checkPaymentStatus() confirms the gateway settled the payment.
 */
class UssdSubscriptionPurchaseService
{
    public const PURPOSE = 'ussd_subscription';

    private const CACHE_TTL_HOURS = 6;

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly UssdSubscriptionService $subscriptions,
    ) {}

    /**
     * Create a pending subscription and hand the vendor off to the gateway.
     *
     * @return array{success: bool, message?: string, reference?: string, authorization_url?: ?string, flow_type?: string, gateway_name?: ?string, subscription?: UssdSubscription}
     */
    public function initiate(Vendor $vendor, UssdPlan $plan, array $options = []): array
    {
        if (! $plan->isPurchasable()) {
            return ['success' => false, 'message' => 'This plan is not available for purchase.'];
        }

        $reference = (string) Str::uuid();

        // Snapshot price and allowance now. Editing the plan later must never
        // change what this vendor paid for or how many sessions they received.
        $subscription = UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => $reference,
            'metadata' => ['purpose' => self::PURPOSE],
        ]);

        // Some gateways do not echo custom metadata back on the callback.
        $this->cacheReference($reference, $vendor->id, $subscription->id, (float) $plan->price);

        $metadata = array_filter([
            'purpose' => self::PURPOSE,
            'vendor_id' => $vendor->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'phone_number' => $this->resolvePayerPhone($vendor, $options),
            'network' => isset($options['network']) ? strtoupper(trim((string) $options['network'])) : null,
        ]);

        $result = $this->paymentService->initiateGenericPayment(
            $vendor->email ?: 'noreply@xtra4u.com',
            (float) $plan->price,
            route('vendor.ussd.subscription.callback', ['reference' => $reference]),
            $reference,
            $metadata,
        );

        if (! ($result['success'] ?? false)) {
            // Nothing was charged; drop the placeholder so it never activates.
            $subscription->forceDelete();
            Cache::forget($this->cacheKey($reference));

            $this->recordEvent(null, $vendor->id, $plan->id, UssdSubscriptionEvent::PAYMENT_FAILED,
                'Payment initiation failed.', ['gateway_response' => $result]);

            return ['success' => false, 'message' => $result['message'] ?? 'Failed to initiate payment.'];
        }

        // Gateways such as BulkClix mint their own transaction id from our UUID.
        // Re-key the subscription and cache onto it so the callback resolves.
        $gatewayReference = $result['reference'] ?? null;
        if ($gatewayReference && $gatewayReference !== $reference) {
            $subscription->forceFill(['payment_reference' => $gatewayReference])->save();
            Cache::forget($this->cacheKey($reference));
            $this->cacheReference($gatewayReference, $vendor->id, $subscription->id, (float) $plan->price);
            $reference = $gatewayReference;
        }

        $subscription->forceFill(['payment_gateway' => $result['gateway_name'] ?? null])->save();

        $this->recordEvent($subscription, $vendor->id, $plan->id, UssdSubscriptionEvent::PLAN_PURCHASE_INITIATED,
            "Purchase initiated for plan \"{$plan->name}\".", ['reference' => $reference, 'amount' => (float) $plan->price]);

        $authorizationUrl = $result['authorization_url'] ?? null;

        return [
            'success' => true,
            'reference' => $reference,
            'authorization_url' => $authorizationUrl,
            'flow_type' => $result['flow_type'] ?? ($authorizationUrl ? 'redirect' : 'inline'),
            'gateway_name' => $result['gateway_name'] ?? null,
            'subscription' => $subscription->fresh(),
        ];
    }

    /**
     * Verify a payment reference with the gateway and, if settled, activate.
     *
     * Safe to call repeatedly: activation is idempotent, and an already-active
     * subscription short-circuits before any gateway call is made.
     *
     * @return array{success: bool, message: string, subscription?: UssdSubscription}
     */
    public function verifyAndActivate(string $reference): array
    {
        $subscription = $this->resolveSubscription($reference);

        if (! $subscription) {
            Log::warning('USSD subscription callback: unknown reference', ['reference' => $reference]);

            return ['success' => false, 'message' => 'Unknown payment reference.'];
        }

        // Fast-path idempotency — no need to re-hit the gateway.
        if ($subscription->status === UssdSubscription::STATUS_ACTIVE) {
            return ['success' => true, 'message' => 'Subscription already active.', 'subscription' => $subscription];
        }

        if (! in_array($subscription->status, [UssdSubscription::STATUS_PENDING_PAYMENT, UssdSubscription::STATUS_PAID], true)) {
            return ['success' => false, 'message' => 'This subscription can no longer be activated.'];
        }

        $verification = $this->paymentService->checkPaymentStatus($reference);

        if (! ($verification['success'] ?? false) || ! $this->isSettled($verification)) {
            $this->recordEvent($subscription, $subscription->vendor_id, $subscription->ussd_plan_id,
                UssdSubscriptionEvent::PAYMENT_FAILED, 'Payment was not successful.', ['reference' => $reference]);

            SendUssdSubscriptionNotification::dispatch(
                $subscription->id,
                SendUssdSubscriptionNotification::TYPE_PAYMENT_FAILED,
                ['reason' => 'The payment was not completed.'],
            );

            return ['success' => false, 'message' => 'Payment was not successful.'];
        }

        $paidAmount = $this->normalizeAmount($verification, $subscription);

        // Never activate on an underpayment. Compare rounded to avoid float noise,
        // exactly as the wallet top-up callback does.
        if (round($paidAmount, 2) < round((float) $subscription->price_paid, 2)) {
            Log::error('USSD subscription amount mismatch', [
                'reference' => $reference,
                'expected' => $subscription->price_paid,
                'received' => $paidAmount,
            ]);

            $this->recordEvent($subscription, $subscription->vendor_id, $subscription->ussd_plan_id,
                UssdSubscriptionEvent::PAYMENT_FAILED, 'Payment amount did not match the plan price.', [
                    'expected' => (float) $subscription->price_paid,
                    'received' => $paidAmount,
                ]);

            SendUssdSubscriptionNotification::dispatch(
                $subscription->id,
                SendUssdSubscriptionNotification::TYPE_PAYMENT_FAILED,
                ['reason' => 'The amount received did not match the plan price.'],
            );

            return ['success' => false, 'message' => 'Payment amount did not match the plan price.'];
        }

        $this->recordEvent($subscription, $subscription->vendor_id, $subscription->ussd_plan_id,
            UssdSubscriptionEvent::PAYMENT_VERIFIED, 'Payment verified with the gateway.', [
                'reference' => $reference,
                'amount' => $paidAmount,
            ]);

        if (! $this->subscriptions->activate($subscription, $verification)) {
            return ['success' => false, 'message' => 'Payment verified but the subscription could not be activated.'];
        }

        Cache::forget($this->cacheKey($reference));

        return [
            'success' => true,
            'message' => 'Subscription activated.',
            'subscription' => $subscription->fresh(),
        ];
    }

    private function resolveSubscription(string $reference): ?UssdSubscription
    {
        $subscription = UssdSubscription::where('payment_reference', $reference)->first();

        if ($subscription) {
            return $subscription;
        }

        // Fall back to the initiation cache for gateways that re-key references
        // after we have already stored ours.
        $cached = Cache::get($this->cacheKey($reference));

        if (is_array($cached) && ! empty($cached['subscription_id'])) {
            return UssdSubscription::find($cached['subscription_id']);
        }

        return null;
    }

    private function isSettled(array $verification): bool
    {
        $status = strtolower((string) data_get($verification, 'data.status', ''));

        return in_array($status, ['success', 'successful', 'completed', 'paid'], true);
    }

    /**
     * Paystack reports amounts in pesewas; everything else reports major units.
     */
    private function normalizeAmount(array $verification, UssdSubscription $subscription): float
    {
        $raw = (float) (data_get($verification, 'data.amount') ?? 0);

        $gateway = $subscription->payment_gateway ?? data_get($verification, 'data.gateway');

        if ($gateway === 'paystack') {
            return PaystackPaymentService::normalizeAmount($raw);
        }

        return $raw;
    }

    private function resolvePayerPhone(Vendor $vendor, array $options): ?string
    {
        $phone = $options['payer_phone'] ?? $vendor->phone_number ?? null;

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    private function cacheReference(string $reference, int $vendorId, int $subscriptionId, float $amount): void
    {
        Cache::put($this->cacheKey($reference), [
            'vendor_id' => $vendorId,
            'subscription_id' => $subscriptionId,
            'amount' => $amount,
            'purpose' => self::PURPOSE,
        ], now()->addHours(self::CACHE_TTL_HOURS));
    }

    private function cacheKey(string $reference): string
    {
        return "ussd_subscription:{$reference}";
    }

    private function recordEvent(?UssdSubscription $subscription, ?int $vendorId, ?int $planId, string $event, string $description, array $context = []): void
    {
        UssdSubscriptionEvent::create([
            'ussd_subscription_id' => $subscription?->id,
            'vendor_id' => $vendorId,
            'ussd_plan_id' => $planId,
            'event' => $event,
            'description' => $description,
            'context' => $context ?: null,
            'actor_type' => 'system',
        ]);
    }

    /**
     * @throws RuntimeException when no payment gateway is configured
     */
    public function assertGatewayReady(): void
    {
        if (! $this->paymentService->isReady()) {
            throw new RuntimeException('No payment gateway is configured. Contact support.');
        }
    }
}
