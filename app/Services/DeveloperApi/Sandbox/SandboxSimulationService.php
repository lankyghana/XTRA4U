<?php

namespace App\Services\DeveloperApi\Sandbox;

use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\SandboxOrder;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;

/**
 * Drives a sandbox order through its payment / fulfilment outcomes on request. The caller picks a NAME from
 * a fixed list (a single event or a named scenario); arbitrary statuses cannot be injected.
 */
class SandboxSimulationService
{
    public function __construct(private readonly SandboxOrderTransitions $transitions) {}

    /**
     * @return array{order:SandboxOrder, applied:list<array{step:string,changed:bool}>}
     */
    public function run(DeveloperApplication $app, SandboxOrder $order, ?string $event, ?string $scenario): array
    {
        // Defence in depth on top of the app/vendor scoped lookup done by the caller.
        if ((int) $order->application_id !== (int) $app->id || (int) $order->vendor_id !== (int) $app->vendor_id) {
            throw ApiException::notFound('Order');
        }

        if (($event === null) === ($scenario === null)) {
            throw ApiException::unprocessable(ApiError::VALIDATION_FAILED, 'Provide exactly one of "event" or "scenario".');
        }

        if ($scenario !== null) {
            $steps = SandboxOrderTransitions::SCENARIOS[$scenario] ?? throw ApiException::unprocessable(ApiError::VALIDATION_FAILED, 'Unknown scenario.');
            $r = $this->transitions->simulate($order->id, $steps, lenient: true);
        } else {
            $r = $this->transitions->simulate($order->id, [$event], lenient: false);
        }

        return ['order' => $r['order'], 'applied' => $r['applied']];
    }
}
