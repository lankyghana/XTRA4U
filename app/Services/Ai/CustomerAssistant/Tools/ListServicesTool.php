<?php

namespace App\Services\Ai\CustomerAssistant\Tools;

use App\Services\Ai\CustomerAssistant\Tools\Concerns\CustomerSurfaceTool;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Catalogue\PublicCatalogueReadService;

/**
 * The public XTRA4U service categories and whether each can be bought right now. No parameters: nothing here depends on
 * who is asking. Returns the same categories, wording and availability the public service pages use.
 */
final class ListServicesTool implements AiTool
{
    use CustomerSurfaceTool;

    public const NAME = 'customer_list_services';

    public function __construct(private readonly PublicCatalogueReadService $catalogue) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Lists the XTRA4U services a visitor can buy (data bundles, shop, result checkers, AFA registration, utility bills) and whether each is available now.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema;
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $services = array_map(fn (array $s) => [
            'category' => $s['category'],
            'name' => CustomerToolSupport::text($s['label'], 60),
            'description' => CustomerToolSupport::text($s['description'], 160),
            'availability' => $s['availability'],
            'page_path' => $s['page_path'],
        ], $this->catalogue->services());

        return [
            'services' => $services,
            'availability_meaning' => [
                'available' => 'Can be bought now.',
                'closed' => 'Temporarily closed by XTRA4U.',
                'unavailable' => 'Nothing can be bought at the moment.',
                'see_page' => 'Availability is shown live on the service page.',
            ],
            'note' => 'Prices are not listed here; use the product search for prices. The AFA registration fee is shown on its own page.',
        ];
    }
}
