<?php

namespace App\Contracts\Gateways;

use App\Models\Order;

interface CollectsPayments
{
    /**
     * Initialize an order checkout payment.
     */
    public function requestPayment(Order $order, string $email, float $amount): array;

    /**
     * Verify a payment by reference.
     */
    public function verifyPayment(string $reference): array;

    /**
     * Whether the gateway is properly configured.
     */
    public function isConfigured(): bool;
}
