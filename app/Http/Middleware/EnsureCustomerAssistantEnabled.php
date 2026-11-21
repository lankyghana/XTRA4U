<?php

namespace App\Http\Middleware;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Safety\KillSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer feature flag. The Customer Assistant is invisible to ordinary visitors unless it has been explicitly
 * enabled: the AI engine must be on (config `ai.enabled`, and not stopped by `php artisan ai:kill-switch`) AND the
 * customer surface must be on (`ai.surfaces.customer.enabled`, env AI_SURFACE_CUSTOMER). Both ship OFF.
 *
 * While it is off the page and the message endpoint answer exactly like a URL that does not exist (404), before any
 * rate limiter, controller or AI code runs. KillSwitch fails closed: if the shared cache cannot be read the engine is
 * treated as switched off.
 */
class EnsureCustomerAssistantEnabled
{
    public function __construct(private readonly KillSwitch $killSwitch) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->killSwitch->surfaceEnabled(AiSurface::Customer), 404);

        return $next($request);
    }
}
