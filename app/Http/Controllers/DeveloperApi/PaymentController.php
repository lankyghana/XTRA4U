<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Resources\DeveloperApi\OrderResource;
use App\Services\DeveloperApi\ApiOrderService;
use App\Services\DeveloperApi\ApiPaymentService;
use App\Support\DeveloperApi\ApiException;
use App\Support\DeveloperApi\ApiResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly ApiOrderService $orders,
        private readonly ApiPaymentService $payments,
    ) {}

    public function store(Request $request, string $order)
    {
        $found = $this->orders->findForApplication($this->application($request), $order)
            ?? throw ApiException::notFound('Order');

        $init = $this->payments->initiate($found);

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
        $found = $this->orders->findByPaymentReference($this->application($request), $reference)
            ?? throw ApiException::notFound('Transaction');

        $this->payments->refreshFromGateway($found);
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
}
