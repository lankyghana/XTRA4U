<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Resources\DeveloperApi\OrderResource;
use App\Services\DeveloperApi\ApiOrderService;
use App\Services\DeveloperApi\ApiPaymentService;
use App\Services\DeveloperApi\Sandbox\SandboxOrderService;
use App\Services\DeveloperApi\Sandbox\SandboxPaymentService;
use App\Support\DeveloperApi\ApiEnvironment;
use App\Support\DeveloperApi\ApiException;
use App\Support\DeveloperApi\ApiResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    // Production services are resolved lazily and ONLY on production code paths, so a sandbox request never
    // even instantiates the gateway-facing order/payment services.
    private function orders(): ApiOrderService
    {
        return app(ApiOrderService::class);
    }

    private function payments(): ApiPaymentService
    {
        return app(ApiPaymentService::class);
    }

    public function store(Request $request, string $order)
    {
        // Environment gate FIRST; the sandbox branch returns or throws and never reaches the gateway code below.
        if (ApiEnvironment::isSandbox($request)) {
            return $this->storeSandbox($request, $order);
        }

        $found = $this->orders()->findForApplication($this->application($request), $order)
            ?? throw ApiException::notFound('Order');

        $init = $this->payments()->initiate($found);

        return ApiResponse::success([
            'order_id' => $found->developer_order_ref,
            'status' => 'pending',
            'amount' => (float) $found->expected_amount,
            'currency' => $found->currency,
            'reference' => $init['reference'],
            'authorization_url' => $init['authorization_url'],
            'flow_type' => $init['flow_type'],
            'checkout_config' => $init['checkout_config'],
        ], 'Payment initiated. Payment is only confirmed by the platform after the gateway verifies it.', [], 202);
    }

    public function transaction(Request $request, string $reference)
    {
        if (ApiEnvironment::isSandbox($request)) {
            // Sandbox tables only. A production reference is simply "not found" here; there is no fallback.
            $payment = app(SandboxPaymentService::class)->findByReference($this->application($request), $reference)
                ?? throw ApiException::notFound('Transaction');

            return ApiResponse::success(SandboxPaymentService::present($payment, $payment->order), 'Transaction retrieved successfully');
        }

        $found = $this->orders()->findByPaymentReference($this->application($request), $reference)
            ?? throw ApiException::notFound('Transaction');

        $this->payments()->refreshFromGateway($found);
        $found->refresh();

        return ApiResponse::success([
            'reference' => $found->payment_reference,
            'order_id' => $found->developer_order_ref,
            'status' => OrderResource::paymentState($found),
            'amount' => (float) $found->expected_amount,
            'currency' => $found->currency,
            'paid_at' => $found->payment_completed_at?->toIso8601String(),
        ], 'Transaction retrieved successfully');
    }

    /** Simulated payment: a `pending` row in the sandbox tables. No gateway, no mobile-money prompt, no wallet. */
    private function storeSandbox(Request $request, string $orderRef)
    {
        $order = app(SandboxOrderService::class)->findForApplication($this->application($request), $orderRef)
            ?? throw ApiException::notFound('Order');

        $result = app(SandboxPaymentService::class)->initiate($order);
        $payment = $result['payment'];
        $order = $result['order'];

        return ApiResponse::success([
            'order_id' => $order->order_ref,
            'environment' => 'sandbox',
            'simulated' => true,
            'status' => 'pending',
            'amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'reference' => $payment->payment_ref,
            'method' => $payment->method,
            'authorization_url' => null,
            'flow_type' => 'simulated',
            'checkout_config' => null,
            'simulation' => SandboxPaymentService::simulationHint($order),
        ], 'Simulated payment started. No real payment was made; simulate its outcome with the sandbox simulation endpoint.', [], 202);
    }
}
