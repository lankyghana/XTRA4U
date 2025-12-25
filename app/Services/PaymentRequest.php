<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Unified payment request DTO for all payment types (orders, AFA, etc.)
 * Ensures consistent payment handling across different domain models.
 */
class PaymentRequest
{
    public const TYPE_ORDER = 'order';
    public const TYPE_AFA_REGISTRATION = 'afa_registration';

    public function __construct(
        public readonly Model $payable,
        public readonly string $type,
        public readonly string $email,
        public readonly float $amount,
        public readonly string $callbackUrl,
        public readonly string $reference,
        public readonly array $metadata = [],
    ) {
    }

    public static function forOrder(
        \App\Models\Order $order,
        string $email,
        float $amount,
        string $callbackUrl
    ): self {
        return new self(
            payable: $order,
            type: self::TYPE_ORDER,
            email: $email,
            amount: $amount,
            callbackUrl: $callbackUrl,
            reference: $order->reference ?? 'ORD-' . strtoupper(uniqid()),
            metadata: [
                'type' => self::TYPE_ORDER,
                'order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
            ]
        );
    }

    public static function forAfaRegistration(
        \App\Models\AfaRegistration $registration,
        string $email,
        float $amount,
        string $callbackUrl
    ): self {
        return new self(
            payable: $registration,
            type: self::TYPE_AFA_REGISTRATION,
            email: $email,
            amount: $amount,
            callbackUrl: $callbackUrl,
            reference: $registration->reference,
            metadata: [
                'type' => self::TYPE_AFA_REGISTRATION,
                'registration_id' => $registration->id,
                'vendor_id' => $registration->vendor_id,
                'reseller_vendor_id' => $registration->reseller_vendor_id,
                'customer_name' => $registration->full_name,
                'is_reseller_order' => $registration->is_reseller_order,
            ]
        );
    }

    public function getPayableId(): int
    {
        return $this->payable->id;
    }

    public function getPayableType(): string
    {
        return get_class($this->payable);
    }

    public function isOrder(): bool
    {
        return $this->type === self::TYPE_ORDER;
    }

    public function isAfaRegistration(): bool
    {
        return $this->type === self::TYPE_AFA_REGISTRATION;
    }
}
