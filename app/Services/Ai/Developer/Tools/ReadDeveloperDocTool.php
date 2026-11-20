<?php

namespace App\Services\Ai\Developer\Tools;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\Docs\DeveloperDocumentCatalog;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;

/**
 * Returns one approved guide: its outline (no `section`), or one bounded section. The model passes stable ids from the
 * allowlist; it can never name a file. An id that is not in the allowlist is simply "not found".
 */
final class ReadDeveloperDocTool implements AiTool
{
    public const NAME = 'developer_read_doc';

    public function __construct(private readonly DeveloperDocumentCatalog $docs) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Reads an approved Developer API guide by id. Without a section it lists the guide\'s sections; with a section id it returns that section\'s text.';
    }

    public function surfaces(): array
    {
        return [AiSurface::Developer];
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::string('doc', 'Guide id, for example "authentication" or "errors".', true, 40, 2, DeveloperDocumentCatalog::ID_PATTERN),
            ToolParameter::string('section', 'Section id taken from the guide outline or a search result. Omit to get the outline.', false, 60, 1, DeveloperDocumentCatalog::SECTION_PATTERN),
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
        $doc = (string) $arguments->string('doc');
        $section = $arguments->string('section');

        $found = $section === null ? $this->docs->outline($doc) : $this->docs->section($doc, $section);

        if ($found === null) {
            return [
                'found' => false,
                'message' => 'No approved guide or section with that id.',
                'available_docs' => array_keys($this->docs->documents()),
            ];
        }

        return ['found' => true] + $found;
    }
}
