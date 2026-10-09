<?php

namespace App\Services\UtilityBills\Data;

/**
 * One biller as the PROVIDER describes it. Behaviour (which fields to collect,
 * whether lookup is by phone) is driven by these capabilities, not by
 * hardcoded biller names.
 */
final class Biller
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly string $accountLabel,
        public readonly bool $requiresPhone,
        /** 'phone' (ECG: the phone is what the lookup queries) or 'account'. */
        public readonly string $lookupBy,
        public readonly bool $linksPhoneToAccount,
        public readonly bool $hasAmountDue,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['key'],
            (string) $a['label'],
            (bool) $a['enabled'],
            (string) $a['accountLabel'],
            (bool) $a['requiresPhone'],
            (string) $a['lookupBy'],
            (bool) $a['linksPhoneToAccount'],
            (bool) $a['hasAmountDue'],
        );
    }
}
