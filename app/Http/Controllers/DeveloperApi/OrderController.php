<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Requests\DeveloperApi\CreateOrderRequest;
use App\Http\Resources\DeveloperApi\OrderResource;
use App\Models\Order;
use App\Services\DeveloperApi\ApiOrderService;
use App\Services\DeveloperApi\ApiPaymentService;
use App\Support\DeveloperApi\ApiException;
use App\Support\DeveloperApi\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    public function __construct(
        private readonly ApiOrderService $orders,
        private readonly ApiPaymentService $payments,
    ) {}

    public function store(CreateOrderRequest $request)
    {
        $result = $this->orders->createFromQuote(
            $this->application($request),
            $request->validated('quote_reference'),
            $request->idempotencyKey(),
            $request->validated('payer_phone'),
            $request->validated('payer_network'),
        );

        return ApiResponse::success(
            $this->present($result['order']),
            $result['created'] ? 'Order created successfully' : 'Order already exists for this Idempotency-Key',
            ['idempotent_replay' => ! $result['created']],
            $result['created'] ? 201 : 200,
        );
    }

    public function show(Request $request, string $order)
    {
        return ApiResponse::success($this->present($this->owned($request, $order)), 'Order retrieved successfully');
    }

    public function status(Request $request, string $order)
    {
        $found = $this->owned($request, $order);

        // Ask the gateway (throttled) and let the existing trusted pipeline settle; then re-read.
        $this->payments->refreshFromGateway($found);
        $found->refresh();

        $data = $this->present($found);

        return ApiResponse::success([
            'id' => $data['id'],
            'status' => $data['status'],
            'payment' => $data['payment'],
            'fulfillment' => $data['fulfillment'],
        ], 'Order status retrieved successfully');
    }

    private function owned(Request $request, string $ref): Order
    {
        return $this->orders->findForApplication($this->application($request), $ref)
            ?? throw ApiException::notFound('Order');
    }

    private function present(Order $order): array
    {
        $order->loadMissing(['service', 'vendor:id,name,vendor_code']);

        return (new OrderResource($order))->resolve();
    }
}
