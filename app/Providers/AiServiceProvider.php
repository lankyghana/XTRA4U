<?php

namespace App\Providers;

use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Tools\ReadOnlyGuard;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * AI engine foundation (M5.2) and tool system (M5.3). Registers only what needs shared state; everything else is
 * auto-resolved. Registers no routes, no views, no middleware and no listeners: the engine is inert until later
 * milestones call AiOrchestrator, and it does nothing at all while `ai.enabled` is false.
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
    }

    public function boot(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        // THE place tools are registered - trusted code only, objects only (never class names from config, the
        // database or a request). M5.3 ships no business tools, so nothing is registered here yet. A later milestone
        // adds lines such as `$registry->register($this->app->make(SomeVendorTool::class));` right here.

        // From now on the registry is closed for the lifetime of the process.
        $registry->seal();
    }
}
