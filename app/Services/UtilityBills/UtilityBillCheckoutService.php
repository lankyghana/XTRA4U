<?php

namespace App\Services\UtilityBills;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\GatewayManager;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\Payments\OrderPricingSnapshot;
use App\Services\PaymentService;
use App\Services\UtilityBills\Exceptions\SaleNotAllowed;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates the immutable Utility Bill order and starts the customer payment.
 *
 * Nothing authoritative comes from the browser: the account, verified name,
 * meters and storefront vendor come from the server-side lookup snapshot, the
 * biller/commission terms from fresh admin config, the limits from the
 * provider catalog. The browser supplies only a lookup token, which meter it
 * picked, the amount to pay, and payer details for the gateway.
 *
 * Reuses the existing payment pipeline unchanged: the payment is carried by a
 * normal `orders` row (vendor_id NULL), so gateway collection, the integrity
 * guard, webhooks and reconciliation all apply as they do for every order.
 */
class UtilityBillCheckoutService
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_ALREADY_PAID = 'already_paid';

    public const OUTCOME_CONFIRMING = 'confirming';

    public function __construct(
        private UtilityBillLookupService $lookups,
        private UtilityBillAvailability $availability,
        private PaymentService $payments,
        private GatewayManager $gateways,
        private CheckoutIntentGuard $intentGuard,
    ) {}

    /**
     * @param  array{session_id:string,vendor_id:?int,lookup_token:string,selected_meter:?string,amount:string,payer_phone:?string,payer_network:?string,idempotency_key:?string}  $input
     * @return array{outcome:string, order:UtilityBillOrder, init:?array}
     *
     * @throws ValidationException
     * @throws SaleNotAllowed
     */
    public function checkout(array $input): array
    {
        $vendorId = $input['vendor_id'];

        $snapshot = $this->lookups->resolve($input['lookup_token'], $input['session_id'], $vendorId);
        if (! $snapshot) {
            throw ValidationException::withMessages(['lookup_token' => 'Your account verification has expired. Please verify the account again.']);
        }

        [$biller, $config, $catalog] = $this->availability->assertSellable($snapshot['biller_key']);

        $vendor = null;
        if ($vendorId !== null) {
            $vendor = Vendor::query()->find($vendorId);
            if (! $vendor || ! $vendor->is_approved) {
                throw new SaleNotAllowed('This store is not available right now.', 'vendor_ineligible');
            }
        }

        $target = $this->resolveTarget($snapshot, $input['selected_meter'] ?? null);
        $amount = $this->validateAmount((string) $input['amount'], $catalog->minAmount, $catalog->maxAmount);
        [$payerPhone, $payerNetwork] = $this->validatePayer($input['payer_phone'] ?? null, $input['payer_network'] ?? null);

        // Frozen vendor commission terms (XTRA4U -> vendor). Direct sales earn none.
        $commissionType = $vendor ? $config->commission_type : null;
        $commissionValue = $vendor ? number_format((float) $config->commission_value, 4, '.', '') : null;
        $commissionAmount = $vendor ? VendorCommission::calculate($commissionType, $commissionValue, $amount) : '0.00';

        $idempotencyKey = $input['idempotency_key'] ?? null;
        $scope = CheckoutIntentGuard::scopeForSession($input['session_id']);

        $isSuccess = fn (Order $o) => in_array($o->payment_status, ['paid', 'completed'], true);
        $isFailed = fn (Order $o) => $o->payment_status === 'failed';
        $hasGateway = fn (Order $o) => (bool) $o->payment_gateway && (bool) $o->payment_reference;

        $existing = $this->intentGuard->evaluate(Order::class, $scope, $idempotencyKey, $isSuccess, $isFailed, $hasGateway);
        $early = $this->reuseOutcome($existing);
        if ($early) {
            return $early;
        }
        if ($existing['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE && $idempotencyKey) {
            $idempotencyKey = $this->intentGuard->freshKeyAfterFailure($idempotencyKey);
        }

        $gatewayName = $this->gateways->getDefaultGatewayName(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION) ?? 'paystack';
        $publicRef = UtilityBillOrder::newPublicRef();

        $created = $this->intentGuard->createOrReuse(
            Order::class,
            $scope,
            $idempotencyKey,
            fn (?string $key) => DB::transaction(function () use (
                $key, $scope, $amount, $biller, $target, $payerPhone, $payerNetwork, $gatewayName, $vendor,
                $commissionType, $commissionValue, $commissionAmount, $publicRef, $snapshot, $catalog
            ) {
                $order = Order::create([
                    'recipient_phone_number' => $target['phone'] ?? $payerPhone ?? 'N/A',
                    'mobile_money_number' => $payerPhone ?? $target['phone'] ?? 'N/A',
                    'mobile_money_network' => $payerNetwork,
                    'service_purchased' => 'Utility Bill: '.$biller->label,
                    'amount_paid' => $amount,
                    // Immutable payment terms. No platform fee: the customer pays face value.
                    'base_price' => $amount,
                    'markup_price' => '0.00',
                    'expected_amount' => $amount,
                    'currency' => $catalog->currency ?: OrderPricingSnapshot::defaultCurrency(),
                    'pricing_snapshot_at' => now(),
                    // Platform-owned payment: attribution lives on the utility order.
                    'vendor_id' => null,
                    'is_reseller_order' => false,
                    'status' => 'Pending',
                    'payment_status' => 'unpaid',
                    'payment_gateway' => $gatewayName,
                    'idempotency_scope' => $key ? $scope : null,
                    'idempotency_key' => $key,
                ]);

                $utility = new UtilityBillOrder;
                $utility->forceFill([
                    'public_ref' => $publicRef,
                    'access_token' => UtilityBillOrder::newAccessToken(),
                    'order_id' => $order->id,
                    'vendor_id' => $vendor?->id,
                    'biller_key' => $biller->key,
                    'biller_label' => $biller->label,
                    'account_number' => $target['account'],
                    'account_name' => $target['name'],
                    'customer_phone' => $target['phone'],
                    'lookup_snapshot' => $target['snapshot'],
                    'amount_due_at_lookup' => $target['amount_due'],
                    'bill_amount' => $amount,
                    'expected_amount' => $amount,
                    'currency' => $catalog->currency ?: 'GHS',
                    'fulfillment_status' => FulfillmentStatus::AWAITING_PAYMENT,
                    'commission_type' => $commissionType,
                    'commission_value' => $commissionValue,
                    'commission_basis' => VendorCommission::BASIS_BILL_FACE_VALUE,
                    'commission_basis_amount' => $vendor ? $amount : '0.00',
                    'commission_amount' => $commissionAmount,
                    'commission_status' => Money::isPositive($commissionAmount)
                        ? UtilityBillOrder::COMMISSION_PENDING
                        : UtilityBillOrder::COMMISSION_NONE,
                ])->save();

                $utility->events()->create([
                    'kind' => 'order_created',
                    'to_status' => FulfillmentStatus::AWAITING_PAYMENT,
                    'detail' => $vendor ? 'storefront vendor '.$vendor->id : 'direct sale',
                    'actor' => 'customer',
                ]);

                return $order;
            }),
            $isSuccess,
            $isFailed,
            $hasGateway,
        );

        $early = $this->reuseOutcome($created);
        if ($early) {
            return $early;
        }
        if ($created['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
            throw ValidationException::withMessages(['amount' => 'Please try again.']);
        }

        /** @var Order $order */
        $order = $created['payable'];
        $utility = UtilityBillOrder::query()->where('order_id', $order->id)->firstOrFail();

        Log::info('utility_bills.order_created', [
            'utility_bill_order_id' => $utility->id,
            'public_ref' => $utility->public_ref,
            'biller' => $utility->biller_key,
            'vendor_id' => $utility->vendor_id,
            'amount' => $amount,
        ]);

        $init = $this->payments->initiatePayment($order, strtolower($publicRef).'@xtra4u.com', (float) $amount);

        if (! ($init['success'] ?? false)) {
            // Pre-payment failure only: nothing was charged and the provider was never contacted.
            $order->update(['payment_status' => 'failed', 'status' => 'Failed']);

            throw new SaleNotAllowed($init['message'] ?? 'We could not start the payment. Please try again.', 'payment_init_failed');
        }

        return ['outcome' => self::OUTCOME_CREATED, 'order' => $utility, 'init' => $init];
    }

    /** @return array{outcome:string, order:UtilityBillOrder, init:?array}|null */
    private function reuseOutcome(array $decision): ?array
    {
        if (! in_array($decision['action'], [CheckoutIntentGuard::ALREADY_SUCCEEDED, CheckoutIntentGuard::STILL_PENDING], true)) {
            return null;
        }

        $utility = UtilityBillOrder::query()->where('order_id', $decision['payable']->id)->first();

        // The idempotency key belonged to a non-utility order: never reuse it here.
        if (! $utility) {
            return null;
        }

        return [
            'outcome' => $decision['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED ? self::OUTCOME_ALREADY_PAID : self::OUTCOME_CONFIRMING,
            'order' => $utility,
            'init' => null,
        ];
    }

    /**
     * The account actually paid, taken only from the verified snapshot.
     *
     * @return array{account:string,name:?string,phone:?string,amount_due:?string,snapshot:array}
     */
    private function resolveTarget(array $snapshot, ?string $selectedMeter): array
    {
        $result = $snapshot['result'];
        $phone = $snapshot['phone'] ?? null;

        if (! empty($result['meters'])) {
            $selectedMeter = trim((string) $selectedMeter);
            $meter = null;
            foreach ($result['meters'] as $candidate) {
                if ($selectedMeter !== '' && hash_equals((string) $candidate['meterNumber'], $selectedMeter)) {
                    $meter = $candidate;
                    break;
                }
            }

            // Never default to the first meter, and never accept a meter the provider did not return.
            if (! $meter) {
                throw ValidationException::withMessages(['selected_meter' => 'Please select the meter you want to pay for.']);
            }

            return [
                'account' => (string) $meter['meterNumber'],
                'name' => $meter['name'] ?? null,
                'phone' => $phone,
                'amount_due' => $meter['outstanding'] ?? null,
                'snapshot' => ['selected_meter' => $meter, 'meter_count' => count($result['meters'])],
            ];
        }

        return [
            'account' => (string) $snapshot['account'],
            'name' => $result['account_name'] ?? null,
            'phone' => $phone,
            'amount_due' => $result['amount_due'] ?? null,
            'snapshot' => [
                'account_number' => $result['account_number'] ?? null,
                'bouquet' => $result['bouquet'] ?? null,
            ],
        ];
    }

    private function validateAmount(string $raw, ?string $min, ?string $max): string
    {
        $raw = trim($raw);

        if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw) || ! Money::isPositive($raw)) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid amount.']);
        }

        $amount = Money::toDecimalString($raw);

        // Limits come from the provider's live catalog; none are invented here.
        if ($min !== null && Money::isLessThan($amount, $min)) {
            throw ValidationException::withMessages(['amount' => 'The minimum payment is GHS '.$min.'.']);
        }
        if ($max !== null && Money::isGreaterThan($amount, $max)) {
            throw ValidationException::withMessages(['amount' => 'The maximum payment is GHS '.$max.'.']);
        }

        return $amount;
    }

    /** @return array{0:?string,1:?string} */
    private function validatePayer(?string $phone, ?string $network): array
    {
        $needsPayer = PaymentGatewayConfig::defaultCollectionRequiresPayerPhone();
        $phone = $phone !== null && trim($phone) !== '' ? \App\Support\GhanaPhoneNumber::toLocal($phone) : null;
        $network = $network !== null && trim($network) !== '' ? strtoupper(trim($network)) : null;
        $errors = [];

        if ($phone !== null && ! \App\Support\GhanaPhoneNumber::isValidLocal($phone)) {
            $errors['payer_phone'] = 'Enter a valid Mobile Money number.';
        }
        if ($network !== null && ! in_array($network, ['MTN', 'TELECEL', 'AIRTELTIGO'], true)) {
            $errors['payer_network'] = 'Choose a valid network.';
        }
        if ($needsPayer) {
            $errors += array_filter([
                'payer_phone' => $phone === null ? 'Enter your Mobile Money number.' : null,
                'payer_network' => $network === null ? 'Choose your Mobile Money network.' : null,
            ]);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$phone, $network];
    }
}
