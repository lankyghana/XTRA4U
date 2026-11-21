<?php

namespace App\Services\Ai\VendorAssistant\Tools;

use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\VendorAssistant\Tools\Concerns\VendorSurfaceTool;
use App\Services\Vendor\VendorDashboardReadService;

/**
 * One order of the vendor's own, by order number. The order number and the vendor are in the SAME query
 * (`visibleOrdersQuery(vendor)` + `id = ?`): an order that does not exist, belongs to another vendor, is unpaid or is
 * still Pending all produce the identical `found: false` answer, so the tool cannot be used to probe other vendors'
 * order numbers.
 *
 * The earnings line is the vendor's own recorded transaction for the order (the table the dashboard totals sum); it is
 * read, never computed. No customer contact details, payment reference, provider data or PINs are loaded.
 */
final class GetMyOrderTool implements AiTool
{
    use VendorSurfaceTool;

    public const NAME = 'vendor_get_my_order';

    public function __construct(private readonly VendorDashboardReadService $dashboard) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Shows one of your own orders by order number: product, status, amount, dates and your recorded earning for it. No customer contact details or payment references.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::integer('order_ref', 'The order number (the number after #), for example 1234.', 1, 2147483647, true),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $vendorId = (int) $context->vendorId;
        $orderId = (int) $arguments->int('order_ref');

        $order = $this->dashboard->visibleOrdersQuery($vendorId)
            ->where('id', $orderId)
            ->select(OrderFields::LIST_COLUMNS)
            ->with('service:id,name')
            ->first();

        if ($order === null) {
            return ['found' => false, 'message' => 'No order with that number was found in your orders.'];
        }

        $record = $this->dashboard->orderEarningsRecord($vendorId, (int) $order->id);

        return [
            'found' => true,
            'currency' => VendorToolSupport::CURRENCY,
            'order' => OrderFields::summary($order, $vendorId) + [
                'payment_state' => (string) $order->payment_status,
                'your_recorded_earning' => $record === null ? null : [
                    'sale_amount' => VendorToolSupport::money($record['sale_amount']),
                    'commission' => VendorToolSupport::money($record['commission']),
                    'earning' => VendorToolSupport::money($record['earning']),
                    'transaction_status' => $record['payment_status'],
                ],
            ],
            'note' => $record === null ? 'No earnings record exists for this order under your account.' : 'The earning is the figure recorded for you; it is not recalculated.',
        ];
    }
}
