<?php

namespace App\Services\UtilityBills\Data;

/**
 * A verified account as returned by GET /utilities/lookup, sanitised.
 *
 * `meters` is non-empty only for ECG-style (phone-linked) lookups. For those,
 * every meter must be shown and the customer must explicitly pick one; the
 * picked meter's number becomes the payment account.
 *
 * `amountDue` keeps the provider's sign: a NEGATIVE value is an account CREDIT,
 * not a debt. Use creditBalance()/amountOwing() for display.
 */
final class LookupResult
{
    /**
     * @param  list<array{name:?string,meterNumber:string,outstanding:?string}>  $meters
     */
    public function __construct(
        public readonly ?string $accountName,
        public readonly ?string $accountNumber,
        public readonly ?string $amountDue,
        public readonly ?string $bouquet,
        public readonly array $meters,
    ) {}

    public function hasMeters(): bool
    {
        return $this->meters !== [];
    }

    public function meter(string $meterNumber): ?array
    {
        foreach ($this->meters as $meter) {
            if (hash_equals($meter['meterNumber'], $meterNumber)) {
                return $meter;
            }
        }

        return null;
    }

    public static function owing(?string $amount): ?string
    {
        return $amount !== null && (float) $amount > 0 ? $amount : null;
    }

    public static function credit(?string $amount): ?string
    {
        return $amount !== null && (float) $amount < 0 ? ltrim($amount, '-') : null;
    }

    public function toArray(): array
    {
        return [
            'account_name' => $this->accountName,
            'account_number' => $this->accountNumber,
            'amount_due' => $this->amountDue,
            'bouquet' => $this->bouquet,
            'meters' => $this->meters,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self($a['account_name'], $a['account_number'], $a['amount_due'], $a['bouquet'], $a['meters']);
    }
}
