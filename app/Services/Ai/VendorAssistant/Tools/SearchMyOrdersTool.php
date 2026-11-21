<?php

namespace App\Services\Ai\VendorAssistant\Tools;

use App\Models\Order;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\VendorAssistant\Tools\Concerns\VendorSurfaceTool;
use App\Services\Vendor\VendorDashboardReadService;
use Carbon\Carbon;

/**
 * Search the vendor's OWN orders, with the same ownership and visibility rules as the Orders page
 * (VendorDashboardReadService::visibleOrdersQuery: the vendor's own sales, plus affiliate orders for products it owns,
 * paid only, never Pending). Ownership is in the SQL, so a foreign order is never loaded.
 *
 * Only these columns are ever selected: reference, amount, status, product, dates, ownership flags. Recipient and
 * mobile-money numbers, the payment reference, gateway data and provider responses are not loaded at all, so they
 * cannot be returned by mistake. Results are capped at MAX_LIMIT.
 */
final class SearchMyOrdersTool implements AiTool
{
    use VendorSurfaceTool;

    public const NAME = 'vendor_search_my_orders';

    public const MAX_LIMIT = 10;

    public const MAX_RANGE_DAYS = 366;

    /** Pending is deliberately not offered: vendors do not see Pending orders (see the Orders page). */
    public const STATUSES = ['Processing', 'Verifying', 'On Hold', 'Completed', 'Cancelled', 'Failed', 'Refunded'];

    public function __construct(private readonly VendorDashboardReadService $dashboard) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Searches your own orders by status, date range, product name or order number. Returns at most 10 recent matches with safe details only (no customer numbers or payment references).';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::enum('status', 'Only orders with this status.', self::STATUSES),
            ToolParameter::string('from', 'First day, YYYY-MM-DD.', false, 10, 10, '/^\d{4}-\d{2}-\d{2}$/'),
            ToolParameter::string('to', 'Last day, YYYY-MM-DD.', false, 10, 10, '/^\d{4}-\d{2}-\d{2}$/'),
            ToolParameter::string('product', 'Part of the product or service name.', false, 60, 2),
            ToolParameter::integer('order_ref', 'An order number you were given (the number after #).', 1, 2147483647),
            ToolParameter::integer('limit', 'How many orders to return (1-'.self::MAX_LIMIT.', default '.self::MAX_LIMIT.').', 1, self::MAX_LIMIT),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $vendorId = (int) $context->vendorId;
        $limit = $arguments->int('limit', self::MAX_LIMIT);

        $from = $this->day($arguments->string('from'));
        $to = $this->day($arguments->string('to'));

        if (($arguments->has('from') && $from === null) || ($arguments->has('to') && $to === null)) {
            return $this->problem('That date is not valid. Use YYYY-MM-DD.');
        }

        if ($from !== null && $to !== null && ($to->lt($from) || $from->diffInDays($to) > self::MAX_RANGE_DAYS)) {
            return $this->problem('The date range must be in order and at most '.self::MAX_RANGE_DAYS.' days.');
        }

        $query = $this->dashboard->visibleOrdersQuery($vendorId)
            ->select(OrderFields::LIST_COLUMNS)
            ->with('service:id,name')
            ->when($arguments->has('status'), fn ($q) => $q->where('status', $arguments->string('status')))
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to->copy()->endOfDay()))
            ->when($arguments->has('order_ref'), fn ($q) => $q->where('id', $arguments->int('order_ref')))
            ->when($arguments->has('product'), function ($q) use ($arguments) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $arguments->string('product')).'%';
                $q->where(fn ($q2) => $q2->where('service_purchased', 'like', $like)
                    ->orWhereHas('service', fn ($q3) => $q3->where('name', 'like', $like)));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;

        return [
            'currency' => VendorToolSupport::CURRENCY,
            'count' => min($rows->count(), $limit),
            'has_more' => $hasMore,
            'orders' => $rows->take($limit)->map(fn (Order $o) => OrderFields::summary($o, $vendorId))->values()->all(),
            'note' => 'Newest first. Pending (unpaid) orders are not shown, as on your Orders page. Narrow the search if has_more is true.',
        ];
    }

    private function day(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $date : null;
    }

    /** @return array<string,mixed> */
    private function problem(string $message): array
    {
        return ['count' => 0, 'has_more' => false, 'orders' => [], 'problem' => $message];
    }
}
