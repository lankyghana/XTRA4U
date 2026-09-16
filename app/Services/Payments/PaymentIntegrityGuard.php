<?php

namespace App\Services\Payments;

use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Support\Money;
use App\Support\PaymentVerificationState;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single place where "may this order's financial side effects happen?" is
 * decided for a GATEWAY payment.
 *
 * Every gateway-facing surface — the browser verify endpoint, each provider's
 * webhook, the payment callback, and the reconciliation sweep — routes its
 * verification response through {@see guard()} instead of applying its own
 * ad-hoc amount comparison. Before this existed there were five separate
 * implementations of the amount check: three compared `confirmed < expected`
 * (so an over- or wrongly-priced payment passed), and the Moolre and Paystack
 * webhooks performed no amount comparison at all.
 *
 * An order passes only when ALL of the following hold:
 *   1. the gateway authoritatively confirmed SUCCESS;
 *   2. the verification was performed against the gateway the order was
 *      created under (not whichever is currently the platform default);
 *   3. any reference the gateway echoed back is this order's own reference;
 *   4. the order's expected amount is knowable from frozen/trustworthy data;
 *   5. the confirmed amount EXACTLY equals that expected amount (decimal-safe
 *      integer-minor-unit comparison — never float, never ">=");
 *   6. the confirmed currency matches the order's frozen currency, whenever
 *      the provider reports one;
 *   7. the gateway transaction/reference has not already been consumed by a
 *      different order.
 *
 * Failure NEVER mutates money. The order keeps its amounts, is not marked
 * paid, credits nothing, and dispatches nothing — it is stamped with a
 * terminal integrity status and an investigable note instead.
 */
class PaymentIntegrityGuard
{
    /**
     * Evaluate a verification response against an order and persist the
     * outcome. Returns the result so the caller can branch on it.
     */
    public function guard(Order $order, array $verification, ?string $verifiedAgainstGateway = null): PaymentIntegrityResult
    {
        $result = $this->evaluate($order, $verification, $verifiedAgainstGateway);

        if ($result->status !== null) {
            $this->stamp($order, $result);
        }

        if (! $result->passed && $result->status !== null) {
            $this->reportAnomaly($order, $result);
        }

        return $result;
    }

    /**
     * Pure evaluation — no writes, no logging. Exposed separately so callers
     * that need to decide something before persisting (and tests) can reason
     * about the verdict on its own.
     */
    public function evaluate(Order $order, array $verification, ?string $verifiedAgainstGateway = null): PaymentIntegrityResult
    {
        if (PaymentVerificationState::from($verification) !== PaymentVerificationState::SUCCESS) {
            // Not an integrity finding: the gateway simply has not confirmed
            // success. PENDING/UNKNOWN must leave the order untouched.
            return PaymentIntegrityResult::unresolved();
        }

        $confirmedAmount = $this->extractAmount($verification);
        $confirmedCurrency = $this->extractCurrency($verification);
        $transactionId = $this->extractTransactionId($verification);
        $expectedCurrency = $this->expectedCurrencyFor($order);

        $fields = [
            'confirmed_amount' => $confirmedAmount,
            'confirmed_currency' => $confirmedCurrency,
            'expected_currency' => $expectedCurrency,
            'gateway_transaction_id' => $transactionId,
        ];

        // (2) Gateway identity. An order with no recorded gateway cannot have
        // a payment attributed to it at all.
        $orderGateway = $this->normalizeGateway($order->payment_gateway);

        if ($orderGateway === null) {
            return PaymentIntegrityResult::manualReview(
                PaymentIntegrityResult::REASON_NO_GATEWAY_ON_ORDER,
                $fields
            );
        }

        $usedGateway = $this->normalizeGateway($verifiedAgainstGateway);

        if ($usedGateway !== null && $usedGateway !== $orderGateway) {
            return PaymentIntegrityResult::mismatch(
                PaymentIntegrityResult::REASON_GATEWAY_MISMATCH,
                $fields + ['context' => ['order_gateway' => $orderGateway, 'verified_against' => $usedGateway]]
            );
        }

        // (3) Reference identity — the payment must concern THIS order's
        // reference. Only checked when the provider echoes one back; several
        // do not, and absence is not evidence of anything.
        $confirmedReference = $this->extractReference($verification);
        $orderReference = trim((string) $order->payment_reference);

        if ($confirmedReference !== null && $orderReference !== '' && ! hash_equals($orderReference, $confirmedReference)) {
            return PaymentIntegrityResult::mismatch(
                PaymentIntegrityResult::REASON_REFERENCE_MISMATCH,
                $fields + ['context' => ['order_reference' => $orderReference, 'confirmed_reference' => $confirmedReference]]
            );
        }

        // (4) Expected amount. Frozen snapshot first; a narrowly-scoped,
        // documented fallback for records created after server-authoritative
        // pricing but before the snapshot column existed; otherwise this
        // order's terms are unprovable and it fails closed.
        $expectedAmount = $this->expectedAmountFor($order);

        if ($expectedAmount === null) {
            return PaymentIntegrityResult::manualReview(
                PaymentIntegrityResult::REASON_EXPECTED_UNPROVABLE,
                $fields + ['context' => [
                    'created_at' => optional($order->created_at)->toIso8601String(),
                    'authoritative_pricing_since' => $this->authoritativePricingSince()?->toIso8601String(),
                ]]
            );
        }

        $fields['expected_amount'] = $expectedAmount;

        // A provider that confirms success but reports no amount leaves us
        // unable to prove the central invariant. Fail closed rather than
        // assuming the amount was right.
        if ($confirmedAmount === null) {
            return PaymentIntegrityResult::manualReview(
                PaymentIntegrityResult::REASON_EXPECTED_UNPROVABLE,
                $fields + ['context' => ['detail' => 'gateway confirmed success without reporting an amount']]
            );
        }

        // (5) EXACT amount equality, in integer minor units.
        if (! Money::equals($confirmedAmount, $expectedAmount)) {
            $reason = Money::isLessThan($confirmedAmount, $expectedAmount)
                ? PaymentIntegrityResult::REASON_AMOUNT_UNDERPAID
                : PaymentIntegrityResult::REASON_AMOUNT_OVERPAID;

            return PaymentIntegrityResult::mismatch($reason, $fields + ['context' => [
                'difference' => Money::difference($confirmedAmount, $expectedAmount),
            ]]);
        }

        // (6) Currency, when the provider reports one.
        if ($confirmedCurrency !== null && $confirmedCurrency !== $expectedCurrency) {
            return PaymentIntegrityResult::mismatch(
                PaymentIntegrityResult::REASON_CURRENCY_MISMATCH,
                $fields
            );
        }

        // (7) Single-use: neither the gateway transaction nor the reference
        // may already belong to a different order.
        if ($consumed = $this->findConsumingOrder($order, $transactionId, $orderReference)) {
            return PaymentIntegrityResult::mismatch(
                $consumed['reason'],
                $fields + ['context' => ['consumed_by_order_id' => $consumed['order_id']]]
            );
        }

        // Record precisely which aspects the PROVIDER confirmed versus which
        // were assumed because it did not report them. A `verified` status must
        // never imply a check the provider made impossible (Moolre reports no
        // currency), so currency is marked ASSUMED in that case rather than
        // silently counted as confirmed.
        $aspects = [
            PaymentIntegrityResult::ASPECT_AMOUNT => PaymentIntegrityResult::CONFIRMED,
            PaymentIntegrityResult::ASPECT_GATEWAY => $usedGateway !== null
                ? PaymentIntegrityResult::CONFIRMED
                : PaymentIntegrityResult::ASSUMED,
            PaymentIntegrityResult::ASPECT_CURRENCY => $confirmedCurrency !== null
                ? PaymentIntegrityResult::CONFIRMED
                : PaymentIntegrityResult::ASSUMED,
            PaymentIntegrityResult::ASPECT_REFERENCE => $confirmedReference !== null
                ? PaymentIntegrityResult::CONFIRMED
                : PaymentIntegrityResult::ASSUMED,
            PaymentIntegrityResult::ASPECT_TRANSACTION => $transactionId !== null
                ? PaymentIntegrityResult::CONFIRMED
                : PaymentIntegrityResult::ASSUMED,
        ];

        return PaymentIntegrityResult::pass(
            $expectedAmount,
            Money::toDecimalString($confirmedAmount),
            $expectedCurrency,
            $confirmedCurrency,
            $transactionId,
            [],
            $aspects,
            $order->hasPricingSnapshot() ? 'snapshot' : 'corroborated',
        );
    }

    /**
     * The immutable answer to "how much was this exact order supposed to pay
     * when it was created?", or null when that cannot be established.
     *
     * Order of authority:
     *   1. `expected_amount` — frozen at creation from server-resolved
     *      product/reseller pricing. Never changes, and is unaffected by any
     *      later product price or markup change.
     *   2. An INDEPENDENTLY CORROBORATED reconstruction for legacy orders —
     *      see {@see reconstructLegacyExpectedAmount()}. Deliberately not a
     *      date-based assumption about which code path created the order.
     *   3. null — unprovable. Never guessed from today's product price.
     */
    public function expectedAmountFor(Order $order): ?string
    {
        if ($order->expected_amount !== null && Money::isPositive($order->expected_amount)) {
            return Money::toDecimalString($order->expected_amount);
        }

        return $this->reconstructLegacyExpectedAmount($order);
    }

    /**
     * Attempt to re-establish a legacy order's expected amount from evidence,
     * or return null so it fails closed.
     *
     * Two conditions must BOTH hold, and they are checked against the pricing
     * row this order actually points at:
     *
     *   1. That row has not been edited since the order was created
     *      (`updated_at <= orders.created_at`). This is what makes the
     *      comparison historical evidence rather than an assumption about
     *      today's price — if the product or listing has been touched since,
     *      we cannot know what it said at order time, and we do not guess.
     *   2. The price it states equals the amount recorded on the order. Two
     *      independently written records agreeing is the corroboration.
     *
     * Note what this deliberately does NOT do: it never trusts `amount_paid`
     * on its own, and it never infers an amount from a product price alone.
     * An order whose recorded amount disagrees with its own product — the
     * #83/#104 signature, GHS 0.10 against an GHS 89 listing — fails both
     * ways and is parked for a human.
     */
    private function reconstructLegacyExpectedAmount(Order $order): ?string
    {
        if (! config('payments.reconstruct_legacy_expected_amount', true)) {
            return null;
        }

        if (! Money::isPositive($order->amount_paid) || ! $order->created_at instanceof CarbonInterface) {
            return null;
        }

        [$source, $sourceAmount] = $this->legacyPricingSourceFor($order);

        if ($source === null || $sourceAmount === null) {
            return null;
        }

        $sourceUpdatedAt = $source->updated_at;

        if (! $sourceUpdatedAt instanceof CarbonInterface || $sourceUpdatedAt->greaterThan($order->created_at)) {
            // The pricing row has been edited at some point after this order
            // came into existence. Whatever it says now is not evidence of
            // what it said then.
            return null;
        }

        if (! Money::equals($sourceAmount, $order->amount_paid)) {
            return null;
        }

        return Money::toDecimalString($sourceAmount);
    }

    /**
     * The pricing row a legacy order points at, and the total it implies.
     * Reseller orders price as base + markup, exactly as OrderPricingSnapshot
     * does for new orders, so both paths answer the same question the same way.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Model|null, 1: string|null}
     */
    private function legacyPricingSourceFor(Order $order): array
    {
        if ($order->is_reseller_order && $order->reseller_product_id) {
            $listing = ResellerProduct::find($order->reseller_product_id);

            return $listing === null
                ? [null, null]
                : [$listing, Money::sum($listing->base_price, $listing->markup_price)];
        }

        if ($order->vendor_service_id) {
            $product = Product::withTrashed()->find($order->vendor_service_id);

            return $product === null
                ? [null, null]
                : [$product, Money::toDecimalString($product->price)];
        }

        return [null, null];
    }

    public function expectedCurrencyFor(Order $order): string
    {
        $currency = strtoupper(trim((string) $order->currency));

        return $currency !== '' ? $currency : OrderPricingSnapshot::defaultCurrency();
    }

    /**
     * Persist the verdict. Deliberately writes ONLY integrity/diagnostic
     * columns — never payment_status, never amount_paid, never any earnings
     * field. Completion remains the job of PaymentService.
     */
    public function stamp(Order $order, PaymentIntegrityResult $result): void
    {
        $attributes = [
            'payment_integrity_status' => $result->status,
            'payment_integrity_note' => $result->note(),
            'gateway_confirmed_amount' => $result->confirmedAmount,
            'gateway_confirmed_currency' => $result->confirmedCurrency,
            // Exactly which aspects the provider confirmed, and which were
            // assumed because it did not report them. Queryable, so an auditor
            // can find every payment whose currency was never independently
            // established rather than having to know which gateways report it.
            'payment_verified_aspects' => $result->verifiedAspects ?: null,
            'payment_verified_at' => now(),
        ];

        // The transaction id is claimed onto the order ONLY when the payment
        // was accepted. A refused payment's transaction may well belong to a
        // different order (that is precisely one of the refusal reasons), and
        // writing it here would both mis-attribute it and collide with the
        // single-use unique index — turning a clean refusal into a 500. The
        // identifier is still fully captured in the refusal's log entry and
        // note for investigation.
        if ($result->passed && $result->gatewayTransactionId) {
            $attributes['gateway_transaction_id'] = $result->gatewayTransactionId;
        }

        $order->forceFill($attributes)->save();
    }

    /**
     * Stamp a non-gateway trusted payment source. Used by the vendor wallet
     * flow (atomic server-side debit) and the admin manual-confirmation
     * escape hatch, both of which prove payment without a gateway response.
     */
    public function stampTrustedSource(Order $order, string $status, string $note): void
    {
        $order->forceFill([
            'payment_integrity_status' => $status,
            'payment_integrity_note' => mb_substr($note, 0, 255),
            'payment_verified_at' => now(),
        ])->save();
    }

    // -----------------------------------------------------------------
    // Observability
    // -----------------------------------------------------------------

    /**
     * Structured, non-secret record of a refused payment. Carries exactly the
     * fields an administrator needs to investigate and nothing from the raw
     * provider payload (which can differ per gateway and may contain
     * credentials or customer PII).
     */
    private function reportAnomaly(Order $order, PaymentIntegrityResult $result): void
    {
        $context = [
            'order_id' => $order->id,
            'reason' => $result->reason,
            'integrity_status' => $result->status,
            'expected_amount' => $result->expectedAmount,
            'confirmed_amount' => $result->confirmedAmount,
            'expected_currency' => $result->expectedCurrency,
            'confirmed_currency' => $result->confirmedCurrency,
            'gateway' => $order->payment_gateway,
            'payment_reference' => $order->payment_reference,
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'order_created_at' => optional($order->created_at)->toIso8601String(),
            'detected_at' => now()->toIso8601String(),
        ] + $result->context;

        Log::error('Payment integrity check failed — refusing to settle or fulfil this order', $context);

        if (! config('payments.integrity_alerts.enabled', true)) {
            return;
        }

        // Throttle per order+reason: a retrying webhook or the reconciliation
        // backoff schedule must not flood the admin dashboard with the same
        // finding over and over.
        $throttleMinutes = (int) config('payments.integrity_alerts.throttle_minutes', 60);
        $cacheKey = sprintf('payment-integrity-alert:%d:%s', $order->id, $result->reason);

        if (! Cache::add($cacheKey, true, now()->addMinutes(max(1, $throttleMinutes)))) {
            return;
        }

        try {
            AdminNotification::create([
                'type' => AdminNotification::TYPE_PAYMENT_INTEGRITY_ALERT,
                'title' => 'Payment integrity check failed',
                'message' => sprintf(
                    'Order #%d was refused: %s (expected %s %s, gateway confirmed %s %s).',
                    $order->id,
                    $result->reason,
                    $result->expectedCurrency ?? '',
                    $result->expectedAmount ?? 'unknown',
                    $result->confirmedCurrency ?? '',
                    $result->confirmedAmount ?? 'unknown'
                ),
                'order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'data' => $context,
            ]);
        } catch (\Throwable $e) {
            // Observability must never break the refusal itself.
            Log::warning('Failed to raise payment integrity admin notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Extraction helpers
    // -----------------------------------------------------------------

    /**
     * Every *PaymentService::verifyPayment() normalizes its amount to MAJOR
     * units (GHS) under `data.amount` — Paystack divides its pesewas by 100
     * internally, Payaza maps `transaction_amount`, Moolre maps `data.amount`.
     * The extra candidate paths cover providers whose raw payload is passed
     * through under `data` without that normalization step.
     */
    private function extractAmount(array $verification): ?string
    {
        $candidates = [
            data_get($verification, 'data.amount'),
            data_get($verification, 'data.transaction_amount'),
            data_get($verification, 'data.raw.amount'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '' || ! is_numeric($candidate)) {
                continue;
            }

            return Money::toDecimalString($candidate);
        }

        return null;
    }

    /**
     * Currency as reported by the provider, upper-cased, or null when it does
     * not report one. Moolre's status response, for instance, carries no
     * currency field — absence is recorded (see `currency_confirmed` in the
     * pass context) rather than treated as a match or a failure.
     */
    private function extractCurrency(array $verification): ?string
    {
        $candidates = [
            data_get($verification, 'data.currency'),
            data_get($verification, 'data.currency_code'),
            data_get($verification, 'data.raw.currency'),
            data_get($verification, 'data.raw.currency_code'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $value = strtoupper(trim((string) $candidate));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractReference(array $verification): ?string
    {
        $candidates = [
            data_get($verification, 'data.reference'),
            data_get($verification, 'data.externalref'),
            data_get($verification, 'data.tx_ref'),
            data_get($verification, 'data.raw.externalref'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Mirrors PaymentService::extractGatewayTransactionId()'s candidate list
     * so the identifier recorded on the order is the same one already stored
     * on its transactions.
     */
    private function extractTransactionId(array $verification): ?string
    {
        $candidates = [
            data_get($verification, 'data.transactionid'),
            data_get($verification, 'data.transaction_id'),
            data_get($verification, 'data.payaza_reference'),
            data_get($verification, 'data.id'),
            data_get($verification, 'data.raw.transactionid'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return null;
    }

    private function normalizeGateway(?string $gateway): ?string
    {
        $value = strtolower(trim((string) $gateway));

        return $value !== '' ? $value : null;
    }

    /**
     * Is this gateway transaction (or reference) already settled against a
     * DIFFERENT order? One real-world payment may only ever pay for one order.
     *
     * @return array{reason: string, order_id: int}|null
     */
    private function findConsumingOrder(Order $order, ?string $transactionId, string $reference): ?array
    {
        if ($transactionId !== null && $transactionId !== '') {
            $byTransaction = Order::query()
                ->whereKeyNot($order->id)
                ->where('gateway_transaction_id', $transactionId)
                ->whereIn('payment_status', ['paid', 'completed'])
                ->value('id');

            if ($byTransaction) {
                return [
                    'reason' => PaymentIntegrityResult::REASON_TRANSACTION_REUSED,
                    'order_id' => (int) $byTransaction,
                ];
            }
        }

        if ($reference !== '') {
            $byReference = Order::query()
                ->whereKeyNot($order->id)
                ->where('payment_reference', $reference)
                ->whereIn('payment_status', ['paid', 'completed'])
                ->value('id');

            if ($byReference) {
                return [
                    'reason' => PaymentIntegrityResult::REASON_REFERENCE_CONSUMED,
                    'order_id' => (int) $byReference,
                ];
            }
        }

        return null;
    }

    private function authoritativePricingSince(): ?Carbon
    {
        $configured = config('payments.authoritative_pricing_since');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        try {
            return Carbon::parse($configured);
        } catch (\Throwable $e) {
            Log::warning('Invalid payments.authoritative_pricing_since config value; treating historical amounts as unprovable', [
                'value' => $configured,
            ]);

            return null;
        }
    }
}
