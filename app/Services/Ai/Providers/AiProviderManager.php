<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Exceptions\AiConfigurationException;

/**
 * Resolves the configured provider. Only called AFTER the kill switch has allowed the request, so a disabled
 * engine never even instantiates a provider.
 */
class AiProviderManager
{
    public function provider(): AiProvider
    {
        $name = config('ai.provider');

        return match ($name) {
            MockAiProvider::NAME => app(MockAiProvider::class),
            // M5.7: the adapter exists but fails closed (assertConfigured) unless every approval and configuration gate
            // holds. Selecting it never bypasses the orchestrator: budgets, limits, breaker and kill switch all apply.
            OpenAiProvider::NAME => app(OpenAiProvider::class),
            default => throw new AiConfigurationException('Unknown or missing AI provider configuration.'),
        };
    }
}
