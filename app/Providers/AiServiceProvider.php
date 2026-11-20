<?php

namespace App\Providers;

use App\Services\Ai\Providers\MockAiProvider;
use Illuminate\Support\ServiceProvider;

/**
 * AI engine foundation (M5.2). Registers only what needs shared state; everything else is auto-resolved.
 * Registers no routes, no views, no middleware and no listeners: the engine is inert until later milestones call
 * AiOrchestrator, and it does nothing at all while `ai.enabled` is false.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One shared offline provider so a test (or a dev script) and the engine see the same scripted instance.
        $this->app->singleton(MockAiProvider::class);
    }
}
