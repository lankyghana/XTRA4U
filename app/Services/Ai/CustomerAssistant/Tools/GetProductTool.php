<?php

namespace App\Services\Ai\CustomerAssistant\Tools;

use App\Services\Ai\CustomerAssistant\Tools\Concerns\CustomerSurfaceTool;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Catalogue\PublicCatalogueReadService;

/**
 * Details of ONE publicly available product, by the ref the search returned. The ref is only a lookup key: visibility
 * and availability are conditions of the same SQL query that finds the row, so a ref for an inactive, withdrawn,
 * non-resellable, soft-deleted or off-page product is answered exactly like a ref that never existed.
 */
final class GetProductTool implements AiTool
{
    use CustomerSurfaceTool;

    public const NAME = 'customer_get_product';

    public function __construct(private readonly PublicCatalogueReadService $catalogue) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Gets the details and current catalogue price of one product that can be bought now, using the ref from customer_search_products.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::string('product_ref', 'The ref exactly as returned by the search, e.g. product_12.', true, 40, 3, '/^(product|reseller|result_checker)_[1-9][0-9]{0,9}$/D'),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $item = $this->catalogue->find((string) $arguments->string('product_ref'));

        // One answer for every kind of "no": unknown, inactive, withdrawn, closed, malformed or not on an official page.
        if ($item === null) {
            return ['found' => false, 'message' => 'That product is not available.'];
        }

        return [
            'found' => true,
            'product' => CustomerToolSupport::productSummary($item, true),
            'price_note' => CustomerToolSupport::PRICE_NOTE,
            'how_to_buy' => [
                'page_path' => $this->catalogue->pagePath($item['category']),
                'note' => 'Open this page, choose the package and follow the normal checkout steps. The assistant cannot place orders or take payments.',
            ],
        ];
    }
}
