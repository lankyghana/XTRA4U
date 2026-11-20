<?php

namespace App\Services\Ai\Developer\Tools;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\Docs\DeveloperDocumentCatalog;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;

/** Keyword search over the APPROVED vendor-facing Developer API guides only (see DeveloperDocumentCatalog). */
final class SearchDeveloperDocsTool implements AiTool
{
    public const NAME = 'developer_search_docs';

    public function __construct(private readonly DeveloperDocumentCatalog $docs) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Searches the approved XTRA4U Developer API guides. Use it first for any question about endpoints, keys, scopes, limits, errors, sandbox or webhooks. Returns matching sections.';
    }

    public function surfaces(): array
    {
        return [AiSurface::Developer];
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::string('query', 'Words to look for, for example "MISSING_SCOPE" or "rate limit headers".', true, 200, 2),
            ToolParameter::integer('limit', 'How many matching sections to return (1-5, default 4).', 1, DeveloperDocumentCatalog::MAX_SEARCH_RESULTS),
        );
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->surface === AiSurface::Developer
            && $context->can(ToolContext::CAP_VENDOR_APPROVED)
            && $context->can(ToolContext::CAP_DEVELOPER_PORTAL);
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $results = $this->docs->search((string) $arguments->string('query'), $arguments->int('limit', 4) ?? 4);

        return [
            'count' => count($results),
            'results' => $results,
            'next_step' => $results === []
                ? 'Nothing in the approved guides matched. Say that the guides do not cover it; do not guess.'
                : 'Use developer_read_doc with doc and section to read the full text of a result.',
        ];
    }
}
