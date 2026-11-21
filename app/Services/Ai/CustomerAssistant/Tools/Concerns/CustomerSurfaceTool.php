<?php

namespace App\Services\Ai\CustomerAssistant\Tools\Concerns;

use App\Services\Ai\CustomerAssistant\Tools\CustomerToolSupport;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\ToolContext;

/**
 * Surface + authorization shared by every Customer Assistant tool: customer surface only, and only for a context that
 * ToolContext::forGuest() built. The tools read PUBLIC data only, so the context carries no identity beyond a hash of
 * the server session; that hash is never used to look anything up (it is not proof of who someone is).
 */
trait CustomerSurfaceTool
{
    public function surfaces(): array
    {
        return [AiSurface::Customer];
    }

    public function authorize(ToolContext $context): bool
    {
        return CustomerToolSupport::isGuestContext($context);
    }
}
