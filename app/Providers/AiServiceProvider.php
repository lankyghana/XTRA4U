<?php

namespace App\Providers;

use App\Models\Vendor;
use App\Services\Ai\Developer\Docs\DeveloperDocumentCatalog;
use App\Services\Ai\Developer\Tools\GetMyAppTool;
use App\Services\Ai\Developer\Tools\ListMyAppsTool;
use App\Services\Ai\Developer\Tools\ReadDeveloperDocTool;
use App\Services\Ai\Developer\Tools\SearchDeveloperDocsTool;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Tools\ReadOnlyGuard;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * AI engine foundation (M5.2), tool system (M5.3) and the Developer Assistant's tools (M5.4). Registers only what needs
 * shared state; everything else is auto-resolved. The engine does nothing at all while `ai.enabled` is false, and the
 * Developer Assistant's routes answer "unavailable" while `ai.surfaces.developer.enabled` is false (the default).
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One shared offline provider so a test (or a dev script) and the engine see the same scripted instance.
        $this->app->singleton(MockAiProvider::class);

        // One registry per process, and one read-only guard (it installs a single DB hook per connection).
        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(ReadOnlyGuard::class);

        // The documentation allowlist (parsed lazily, once per process).
        $this->app->singleton(DeveloperDocumentCatalog::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        // Per-vendor throttle for the Developer Assistant's HTTP endpoint (the engine has its own per-principal limit
        // on top). Keyed by the vendor-guard identity; falls back to the IP for anything that is not a vendor.
        RateLimiter::for('developer-assistant', function (Request $request) {
            $vendor = Auth::guard('vendor')->user();

            return Limit::perMinute(10)->by($vendor instanceof Vendor ? 'dev-assistant:vendor:'.$vendor->id : 'dev-assistant:ip:'.$request->ip());
        });

        // THE place tools are registered - trusted code only, objects only (never class names from config, the
        // database or a request). M5.4 registers the Developer Assistant's four read-only tools. Registering a tool
        // does not make it callable: it must also be listed in config('ai.tools.allowlist.developer').
        try {
            foreach ([SearchDeveloperDocsTool::class, ReadDeveloperDocTool::class, ListMyAppsTool::class, GetMyAppTool::class] as $tool) {
                $registry->register($this->app->make($tool));
            }
        } catch (\Throwable $e) {
            // A configuration problem must never stop the marketplace from booting. Fail closed instead: whatever
            // was not registered is simply unavailable, and the assistant reports itself unavailable.
            Log::error('AI developer tools could not be registered.', ['exception' => $e::class]);
        } finally {
            // From now on the registry is closed for the lifetime of the process.
            $registry->seal();
        }
    }
}
