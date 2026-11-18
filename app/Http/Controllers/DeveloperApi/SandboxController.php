<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Requests\DeveloperApi\SimulateSandboxOrderRequest;
use App\Http\Resources\DeveloperApi\SandboxOrderResource;
use App\Services\DeveloperApi\Sandbox\SandboxOrderService;
use App\Services\DeveloperApi\Sandbox\SandboxOrderTransitions;
use App\Services\DeveloperApi\Sandbox\SandboxSimulationService;
use App\Support\DeveloperApi\ApiException;
use App\Support\DeveloperApi\ApiResponse;
use Illuminate\Http\Request;

/**
 * Sandbox-only endpoints. The route group requires a sandbox API key (api.sandbox middleware) before this
 * class is even constructed, and nothing here can reach a production service.
 */
class SandboxController extends ApiController
{
    public function simulate(SimulateSandboxOrderRequest $request, string $order)
    {
        $app = $this->application($request);

        $found = app(SandboxOrderService::class)->findForApplication($app, $order)
            ?? throw ApiException::notFound('Order');

        $result = app(SandboxSimulationService::class)->run(
            $app,
            $found,
            $request->validated('event'),
            $request->validated('scenario'),
        );

        $changed = collect($result['applied'])->contains(fn (array $a) => $a['changed']);

        return ApiResponse::success([
            'environment' => 'sandbox',
            'simulated' => true,
            'applied' => $result['applied'],
            'order' => (new SandboxOrderResource($result['order']))->resolve(),
        ], $changed ? 'Simulation applied' : 'Order was already in the requested state', ['idempotent_replay' => ! $changed]);
    }

    /** What can be simulated. Static documentation of the predefined, allow-listed outcomes. */
    public function scenarios(Request $request)
    {
        return ApiResponse::success([
            'environment' => 'sandbox',
            'events' => SandboxOrderTransitions::STEPS,
            'scenarios' => SandboxOrderTransitions::SCENARIOS,
        ], 'Sandbox simulations retrieved successfully');
    }
}
