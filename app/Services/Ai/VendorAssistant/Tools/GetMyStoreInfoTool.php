<?php

namespace App\Services\Ai\VendorAssistant\Tools;

use App\Models\Vendor;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\VendorAssistant\Tools\Concerns\VendorSurfaceTool;
use App\Services\Vendor\VendorDashboardReadService;

/**
 * What the vendor's own public store shows. No parameters: the vendor is the one in the trusted context, and the row is
 * selected by that id with an explicit column list (no email, phone number, balances, wallet, password or settings).
 * The storefront does show the vendor's phone number to customers, but the assistant never returns phone numbers, so
 * the tool only says that a contact number is displayed and where to change it.
 */
final class GetMyStoreInfoTool implements AiTool
{
    use VendorSurfaceTool;

    public const NAME = 'vendor_get_my_store_info';

    public function __construct(private readonly VendorDashboardReadService $dashboard) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Describes your public store: its name, web address, whether the account is approved, what is on sale and whether AFA registration is offered. No private account details.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema;
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $vendorId = (int) $context->vendorId;

        $vendor = Vendor::query()
            ->whereKey($vendorId)
            ->select(['id', 'name', 'vendor_code', 'is_approved', 'afa_enabled', 'afa_price', 'afa_reseller_enabled', 'afa_selling_price', 'afa_source_vendor_id'])
            ->first();

        if ($vendor === null) {
            return ['found' => false, 'message' => 'Your store could not be found.'];
        }

        $counts = $this->dashboard->catalogueCounts($vendorId);
        $code = is_string($vendor->vendor_code) && $vendor->vendor_code !== '' ? $vendor->vendor_code : null;

        return [
            'found' => true,
            'store_name' => VendorToolSupport::text($vendor->name, 80),
            'store_url' => $code === null ? null : route('storefront.vendor', ['vendor' => $code]),
            'store_code' => $code,
            'account_status' => $vendor->is_approved === true ? 'approved' : 'not_approved',
            'on_sale_now' => [
                'own_products' => $counts['own_active'],
                'resold_products' => $counts['listings_available'],
            ],
            'not_on_sale_now' => [
                'own_products' => $counts['own_inactive'],
                'resold_products' => max(0, $counts['listings_total'] - $counts['listings_available']),
            ],
            'afa_registration_offered' => $vendor->offersAfaRegistration() === true,
            'public_description' => null,
            'public_contact_details' => null,
            'note' => 'Your store page displays your account phone number to customers. The assistant does not return phone numbers, emails, balances or other private account details; change them in your account settings.',
        ];
    }
}
