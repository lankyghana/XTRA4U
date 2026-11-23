<?php

namespace App\Providers;

use App\Models\Vendor;
use App\Services\Ai\CustomerAssistant\Tools\GetProductTool;
use App\Services\Ai\CustomerAssistant\Tools\ListServicesTool;
use App\Services\Ai\CustomerAssistant\Tools\SearchHelpTool;
use App\Services\Ai\CustomerAssistant\Tools\SearchProductsTool;
use App\Services\Ai\Developer\Docs\DeveloperDocumentCatalog;
use App\Services\Ai\Developer\Tools\GetMyAppTool;
use App\Services\Ai\Developer\Tools\ListMyAppsTool;
use App\Services\Ai\Developer\Tools\ReadDeveloperDocTool;
use App\Services\Ai\Developer\Tools\SearchDeveloperDocsTool;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Safety\CacheEngineCheck;
use App\Services\Ai\Safety\ConcurrencyGuard;
use App\Services\Ai\Tools\ReadOnlyGuard;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\VendorAssistant\Tools\GetDashboardSummaryTool;
use App\Services\Ai\VendorAssistant\Tools\GetEarningsSummaryTool;
use App\Services\Ai\VendorAssistant\Tools\GetMyOrderTool;
use App\Services\Ai\VendorAssistant\Tools\GetMyStoreInfoTool;
use App\Services\Ai\VendorAssistant\Tools\ListMyProductsTool;
use App\Services\Ai\VendorAssistant\Tools\SearchMyOrdersTool;
use App\Support\ClientIpKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * AI engine foundation (M5.2), tool system (M5.3), the Developer Assistant's tools (M5.4) and the Vendor Assistant's
 * tools (M5.5). Registers only what needs shared state; everything else is auto-resolved. The engine does nothing at
 * all while `ai.enabled` is false, and each assistant's routes answer "unavailable" while its surface flag
 * (`ai.surfaces.developer.enabled` / `ai.surfaces.vendor.enabled`) is false (the default).
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One shared offline provider so a test (or a dev script) and the engine see the same scripted instance.
        $this->app->singleton(MockAiProvider::class);

        // Process-wide state: the turn-scoped concurrency slot and the once-per-process cache-engine verdict.
        $this->app->singleton(ConcurrencyGuard::class);
        $this->app->singleton(CacheEngineCheck::class);

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

            return Limit::perMinute(10)->by($vendor instanceof Vendor ? 'dev-assistant:vendor:'.$vendor->id : 'dev-assistant:ip:'.ClientIpKey::of($request->ip()));
        });

        // Same idea for the Vendor Assistant (inside the vendor dashboard, behind `vendor.approved`).
        RateLimiter::for('vendor-assistant', function (Request $request) {
            $vendor = Auth::guard('vendor')->user();

            return Limit::perMinute(10)->by($vendor instanceof Vendor ? 'vendor-assistant:vendor:'.$vendor->id : 'vendor-assistant:ip:'.ClientIpKey::of($request->ip()));
        });

        // Customer Assistant (M5.6): the public, guest-accessible endpoint. Unauthenticated, so the limits are keyed on the
        // client IP (a visitor can discard a session cookie at will, an IP is much harder to rotate): a per-minute burst
        // limit and an hourly ceiling. They live in the shared cache. The engine adds its own per-guest-session limit,
        // concurrency cap and daily budgets on top, and the whole thing fails closed if the cache is unavailable.
        // The key comes from `$request->ip()`, which is the connecting address unless the request arrived through a proxy
        // listed in TRUSTED_PROXIES; forwarded headers from anyone else are ignored, so they cannot be used to dodge the
        // limit. IPv6 clients are keyed by their /64 (a subscriber owns the whole prefix). See docs/ai/06 section 9.
        RateLimiter::for('customer-assistant', function (Request $request) {
            $client = ClientIpKey::of($request->ip());

            return [
                Limit::perMinute(10)->by('customer-assistant:ip-min:'.$client),
                Limit::perHour(60)->by('customer-assistant:ip-hour:'.$client),
            ];
        });

        // THE place tools are registered - trusted code only, objects only (never class names from config, the
        // database or a request). M5.4 registers the Developer Assistant's four read-only tools and M5.5 the Vendor
        // Assistant's six and M5.6 the Customer Assistant's four. Registering a tool does not make it callable: it must also be listed in
        // config('ai.tools.allowlist.<surface>') and declare that surface.
        try {
            foreach ([
                SearchDeveloperDocsTool::class, ReadDeveloperDocTool::class, ListMyAppsTool::class, GetMyAppTool::class,
                GetDashboardSummaryTool::class, SearchMyOrdersTool::class, GetMyOrderTool::class,
                ListMyProductsTool::class, GetEarningsSummaryTool::class, GetMyStoreInfoTool::class,
                ListServicesTool::class, SearchProductsTool::class, GetProductTool::class, SearchHelpTool::class,
            ] as $tool) {
                $registry->register($this->app->make($tool));
            }
        } catch (\Throwable $e) {
            // A configuration problem must never stop the marketplace from booting. Fail closed instead: whatever
            // was not registered is simply unavailable, and the assistant reports itself unavailable.
            Log::error('AI assistant tools could not be registered.', ['exception' => $e::class]);
        } finally {
            // From now on the registry is closed for the lifetime of the process.
            $registry->seal();
        }
    }
}
