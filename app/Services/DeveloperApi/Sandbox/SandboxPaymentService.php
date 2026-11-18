<?php

namespace App\Services\DeveloperApi\Sandbox;

use App\Http\Resources\DeveloperApi\SandboxOrderResource;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\SandboxOrder;
use App\Models\Developer\SandboxPayment;

/**
 * SIMULATED payments. Starting one creates a `pending` row in developer_sandbox_payments and nothing else:
 * no gateway, no mobile-money prompt, no callback, no wallet, no transaction row. The outcome is chosen by
 * the developer through SandboxSimulationService.
 */
class SandboxPaymentService
{
    public function __construct(private readonly SandboxOrderTransitions $transitions) {}

    /** @return array{order:SandboxOrder,payment:SandboxPayment} */
    public function initiate(SandboxOrder $order): array
    {
        $r = $this->transitions->initiatePayment($order->id);

        return ['order' => $r['order'], 'payment' => $r['payment']];
    }

    /** Scoped to the caller's application AND vendor; a production reference simply does not exist here. */
    public function findByReference(DeveloperApplication $app, string $reference): ?SandboxPayment
    {
        return SandboxPayment::query()
            ->where('application_id', $app->id)
            ->where('vendor_id', $app->vendor_id)
            ->where('payment_ref', $reference)
            ->with('order')
            ->first();
    }

    /** Public shape of a simulated payment/transaction. */
    public static function present(SandboxPayment $payment, SandboxOrder $order): array
    {
        return [
            'reference' => $payment->payment_ref,
            'order_id' => $order->order_ref,
            'environment' => 'sandbox',
            'simulated' => true,
            'status' => $payment->status,
            'method' => $payment->method,
            'gateway' => $payment->gateway,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'simulation_outcome' => $payment->outcome,
            'paid_at' => $payment->status === 'paid' ? $payment->simulated_at?->toIso8601String() : null,
        ];
    }

    /** Next-step hint returned with a new simulated payment. */
    public static function simulationHint(SandboxOrder $order): array
    {
        return [
            'endpoint' => "POST /api/v1/sandbox/orders/{$order->order_ref}/simulate",
            'events' => SandboxOrderTransitions::STEPS,
            'scenarios' => array_keys(SandboxOrderTransitions::SCENARIOS),
        ];
    }

    public static function presentOrder(SandboxOrder $order): array
    {
        return (new SandboxOrderResource($order))->resolve();
    }
}
