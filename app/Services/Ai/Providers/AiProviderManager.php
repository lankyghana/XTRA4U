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
            // Deliberately unimplemented in M5.2: the endpoint, model and request format must first be verified
            // against OpenAI's official documentation. Failing closed means no request can ever be sent.
            'openai' => throw new AiConfigurationException('The OpenAI provider adapter is not implemented yet.'),
            default => throw new AiConfigurationException('Unknown or missing AI provider configuration.'),
        };
    }
}
