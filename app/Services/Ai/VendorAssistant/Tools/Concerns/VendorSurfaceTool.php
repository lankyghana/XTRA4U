<?php

namespace App\Services\Ai\VendorAssistant\Tools\Concerns;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\VendorAssistant\Tools\VendorToolSupport;

/**
 * Surface + authorization shared by every Vendor Assistant tool: vendor surface only, and only for a context that
 * ToolContext::forVendor() built (an approved vendor). The executor additionally re-verifies on every call that the
 * vendor still exists and is still approved.
 */
trait VendorSurfaceTool
{
    public function surfaces(): array
    {
        return [AiSurface::Vendor];
    }

    public function authorize(ToolContext $context): bool
    {
        return VendorToolSupport::isVendorContext($context);
    }
}
