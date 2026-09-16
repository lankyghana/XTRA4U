<?php

namespace App\Services\Payments;

use App\Support\PaymentIntegrity;

/**
 * The outcome of one payment-integrity evaluation.
 *
 * Carries everything an administrator needs to investigate a rejection
 * without having to reconstruct it from logs: what we required, what the
 * gateway actually confirmed, which reference/transaction it concerned, and
 * the machine-readable reason it was refused.
 */
final class PaymentIntegrityResult
{
    /** Machine-readable rejection reasons. Stored on the order and logged. */
    public const REASON_OK = 'ok';

    public const REASON_NOT_CONFIRMED = 'not_confirmed_success';

    public const REASON_NO_GATEWAY_ON_ORDER = 'no_gateway_recorded_on_order';

    public const REASON_GATEWAY_MISMATCH = 'gateway_mismatch';

    public const REASON_REFERENCE_MISMATCH = 'reference_mismatch';

    public const REASON_EXPECTED_UNPROVABLE = 'expected_amount_unprovable';

    public const REASON_AMOUNT_UNDERPAID = 'amount_underpaid';

    public const REASON_AMOUNT_OVERPAID = 'amount_overpaid';

    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';

    public const REASON_TRANSACTION_REUSED = 'gateway_transaction_already_consumed';

    public const REASON_REFERENCE_CONSUMED = 'payment_reference_already_consumed';

    /**
     * The individual aspects an acceptance can claim. Recorded per order so a
     * `verified` status never implies more was checked than the provider
     * actually supplied — most importantly, Moolre's status response carries
     * no currency field, so a Moolre payment is amount/reference verified with
     * currency ASSUMED from the platform default, and says so.
     */
    public const ASPECT_AMOUNT = 'amount';

    public const ASPECT_CURRENCY = 'currency';

    public const ASPECT_REFERENCE = 'reference';

    public const ASPECT_TRANSACTION = 'transaction';

    public const ASPECT_GATEWAY = 'gateway';

    /** Aspect outcomes. */
    public const CONFIRMED = 'confirmed';

    /** The provider did not supply this field; it was not independently checked. */
    public const ASSUMED = 'assumed';

    public function __construct(
        public readonly bool $passed,
        public readonly ?string $status,
        public readonly string $reason,
        public readonly ?string $expectedAmount = null,
        public readonly ?string $confirmedAmount = null,
        public readonly ?string $expectedCurrency = null,
        public readonly ?string $confirmedCurrency = null,
        public readonly ?string $gatewayTransactionId = null,
        public readonly array $context = [],
        /** @var array<string,string> aspect => confirmed|assumed */
        public readonly array $verifiedAspects = [],
        /** How the expected amount was established: 'snapshot' or 'corroborated'. */
        public readonly ?string $expectedAmountSource = null,
    ) {}

    public static function pass(
        ?string $expectedAmount,
        ?string $confirmedAmount,
        ?string $expectedCurrency,
        ?string $confirmedCurrency,
        ?string $gatewayTransactionId,
        array $context = [],
        array $verifiedAspects = [],
        ?string $expectedAmountSource = null,
    ): self {
        return new self(
            passed: true,
            status: PaymentIntegrity::VERIFIED,
            reason: self::REASON_OK,
            expectedAmount: $expectedAmount,
            confirmedAmount: $confirmedAmount,
            expectedCurrency: $expectedCurrency,
            confirmedCurrency: $confirmedCurrency,
            gatewayTransactionId: $gatewayTransactionId,
            context: $context,
            verifiedAspects: $verifiedAspects,
            expectedAmountSource: $expectedAmountSource,
        );
    }

    /** True only when the provider itself supplied and matched the currency. */
    public function currencyIndependentlyConfirmed(): bool
    {
        return ($this->verifiedAspects[self::ASPECT_CURRENCY] ?? null) === self::CONFIRMED;
    }

    /**
     * An authoritative contradiction of the order's frozen terms.
     */
    public static function mismatch(string $reason, array $fields = []): self
    {
        return self::failure(PaymentIntegrity::MISMATCH, $reason, $fields);
    }

    /**
     * Integrity could not be established either way — needs a human, never
     * an automatic retry.
     */
    public static function manualReview(string $reason, array $fields = []): self
    {
        return self::failure(PaymentIntegrity::MANUAL_REVIEW, $reason, $fields);
    }

    /**
     * The gateway has not (yet) authoritatively confirmed success. Not a
     * finding about the order at all — nothing is stamped for this outcome.
     */
    public static function unresolved(): self
    {
        return new self(false, null, self::REASON_NOT_CONFIRMED);
    }

    private static function failure(string $status, string $reason, array $fields): self
    {
        return new self(
            passed: false,
            status: $status,
            reason: $reason,
            expectedAmount: $fields['expected_amount'] ?? null,
            confirmedAmount: $fields['confirmed_amount'] ?? null,
            expectedCurrency: $fields['expected_currency'] ?? null,
            confirmedCurrency: $fields['confirmed_currency'] ?? null,
            gatewayTransactionId: $fields['gateway_transaction_id'] ?? null,
            context: $fields['context'] ?? [],
        );
    }

    /** Short human-readable note persisted to orders.payment_integrity_note. */
    public function note(): string
    {
        if ($this->passed) {
            // State exactly what was established, never more. An "assumed"
            // aspect is one the provider did not report.
            $claims = [];

            foreach ($this->verifiedAspects as $aspect => $outcome) {
                $claims[] = $aspect.'='.$outcome;
            }

            if ($this->expectedAmountSource !== null) {
                $claims[] = 'expected_from='.$this->expectedAmountSource;
            }

            return mb_substr('verified '.implode(' ', $claims), 0, 255);
        }

        $parts = [$this->reason];

        if ($this->expectedAmount !== null || $this->confirmedAmount !== null) {
            $parts[] = sprintf(
                'expected=%s confirmed=%s',
                $this->expectedAmount ?? 'unknown',
                $this->confirmedAmount ?? 'unknown'
            );
        }

        if (in_array($this->reason, [self::REASON_TRANSACTION_REUSED, self::REASON_REFERENCE_CONSUMED], true)) {
            // The order does not get to claim this transaction id (it belongs
            // to another order), so the note is where it stays visible.
            $parts[] = sprintf(
                'gateway_transaction_id=%s consumed_by_order_id=%s',
                $this->gatewayTransactionId ?? 'unknown',
                $this->context['consumed_by_order_id'] ?? 'unknown'
            );
        }

        if ($this->reason === self::REASON_CURRENCY_MISMATCH) {
            $parts[] = sprintf(
                'expected_currency=%s confirmed_currency=%s',
                $this->expectedCurrency ?? 'unknown',
                $this->confirmedCurrency ?? 'unknown'
            );
        }

        return mb_substr(implode(' ', $parts), 0, 255);
    }
}
