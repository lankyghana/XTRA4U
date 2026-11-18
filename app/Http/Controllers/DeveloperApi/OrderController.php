<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Requests\DeveloperApi\CreateOrderRequest;
use App\Http\Resources\DeveloperApi\OrderResource;
use App\Http\Resources\DeveloperApi\SandboxOrderResource;
use App\Models\Developer\SandboxOrder;
use App\Models\Order;
use App\Services\DeveloperApi\ApiOrderService;
use App\Services\DeveloperApi\ApiPaymentService;
use App\Services\DeveloperApi\Sandbox\SandboxOrderService;
use App\Support\DeveloperApi\ApiEnvironment;
use App\Support\DeveloperApi\ApiException;
use App\Support\DeveloperApi\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
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

    public function store(CreateOrderRequest $request)
    {
        // Environment gate FIRST. The sandbox branch always returns or throws; it can never fall through
        // to the production order flow below.
        if (ApiEnvironment::isSandbox($request)) {
            return $this->storeSandbox($request);
        }

        $result = $this->orders()->createFromQuote(
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
        if (ApiEnvironment::isSandbox($request)) {
            return ApiResponse::success($this->presentSandbox($this->ownedSandbox($request, $order)), 'Order retrieved successfully');
        }

        return ApiResponse::success($this->present($this->owned($request, $order)), 'Order retrieved successfully');
    }

    public function status(Request $request, string $order)
    {
        if (ApiEnvironment::isSandbox($request)) {
            // No gateway is ever asked: sandbox state is whatever the simulation has produced.
            $data = $this->presentSandbox($this->ownedSandbox($request, $order));

            return ApiResponse::success([
                'id' => $data['id'],
                'environment' => 'sandbox',
                'simulated' => true,
                'status' => $data['status'],
                'payment' => $data['payment'],
                'fulfillment' => $data['fulfillment'],
            ], 'Order status retrieved successfully');
        }

        $found = $this->owned($request, $order);

        // Ask the gateway (throttled) and let the existing trusted pipeline settle; then re-read.
        $this->payments()->refreshFromGateway($found);
        $found->refresh();

        $data = $this->present($found);

        return ApiResponse::success([
            'id' => $data['id'],
            'status' => $data['status'],
            'payment' => $data['payment'],
            'fulfillment' => $data['fulfillment'],
        ], 'Order status retrieved successfully');
    }

    private function storeSandbox(CreateOrderRequest $request)
    {
        $result = app(SandboxOrderService::class)->createFromQuote(
            $this->application($request),
            $request->attributes->get('api.key')?->id,
            $request->validated('quote_reference'),
            $request->idempotencyKey(),
            $request->validated('payer_phone'),
            $request->validated('payer_network'),
            $request->validated('client_reference'),
        );

        return ApiResponse::success(
            $this->presentSandbox($result['order']),
            $result['created'] ? 'Sandbox order created successfully' : 'Order already exists for this Idempotency-Key',
            ['idempotent_replay' => ! $result['created']],
            $result['created'] ? 201 : 200,
        );
    }

    private function ownedSandbox(Request $request, string $ref): SandboxOrder
    {
        return app(SandboxOrderService::class)->findForApplication($this->application($request), $ref)
            ?? throw ApiException::notFound('Order');
    }

    private function presentSandbox(SandboxOrder $order): array
    {
        return (new SandboxOrderResource($order))->resolve();
    }

    private function owned(Request $request, string $ref): Order
    {
        return $this->orders()->findForApplication($this->application($request), $ref)
            ?? throw ApiException::notFound('Order');
    }

    private function present(Order $order): array
    {
        $order->loadMissing(['service', 'vendor:id,name,vendor_code']);

        return (new OrderResource($order))->resolve();
    }
}
