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
 * The vendor's dashboard figures, straight from VendorDashboardReadService (the code the dashboard itself uses). No
 * figure is computed here: this tool formats what the service returns and labels what it means.
 */
final class GetDashboardSummaryTool implements AiTool
{
    use VendorSurfaceTool;

    public const NAME = 'vendor_get_dashboard_summary';

    public function __construct(private readonly VendorDashboardReadService $dashboard) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Your dashboard figures for a period: sales, earnings, commission, order counts and status counts, top products and a 7-day trend. Uses the same numbers as the dashboard.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::enum('period', 'Reporting period. Defaults to today.', VendorDashboardReadService::PERIODS),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $vendorId = (int) $context->vendorId;
        $period = $arguments->string('period', 'today');

        $summary = $this->dashboard->salesSummary($vendorId, $period);
        $analytics = $this->dashboard->analytics($vendorId);
        $range = VendorToolSupport::periodRange($this->dashboard->dateRange($period));

        // Order counts exist on the Analytics page only for these windows. "Yesterday" has no order count there, so it
        // is reported as unavailable rather than computed with a new definition.
        $ordersInPeriod = match ($period) {
            'today' => $analytics['ordersToday'],
            'this_week' => $analytics['ordersThisWeek'],
            'this_month' => $analytics['ordersThisMonth'],
            'all_time' => $analytics['totalOrders'],
            default => null,
        };

        return [
            'currency' => VendorToolSupport::CURRENCY,
            'period' => $period,
            'period_label' => VendorToolSupport::PERIOD_LABELS[$period] ?? $period,
            'period_range' => $range,
            'totals' => [
                'sales' => VendorToolSupport::money($summary['sales']),
                'earnings' => VendorToolSupport::money($summary['earnings']),
                'commissions' => VendorToolSupport::money($summary['commissions']),
            ],
            'by_source' => [
                'product_orders' => [
                    'sales' => VendorToolSupport::money($summary['sources']['product_orders']['sales']),
                    'earnings' => VendorToolSupport::money($summary['sources']['product_orders']['earnings']),
                    'commissions' => VendorToolSupport::money($summary['sources']['product_orders']['commissions']),
                ],
                'afa_registrations' => [
                    'sales' => VendorToolSupport::money($summary['sources']['afa']['sales']),
                    'earnings' => VendorToolSupport::money($summary['sources']['afa']['earnings']),
                ],
                'result_checkers' => [
                    'sales' => VendorToolSupport::money($summary['sources']['result_checker']['sales']),
                    'earnings' => VendorToolSupport::money($summary['sources']['result_checker']['earnings']),
                ],
            ],
            'orders' => [
                'orders_in_period' => $ordersInPeriod,
                'orders_in_period_note' => $ordersInPeriod === null ? 'The dashboard has no order count for this period.' : 'Paid orders you sold or own (Pending excluded), as on the Analytics page.',
                'status_counts_all_time' => $analytics['statusBreakdown'],
                'status_counts_note' => 'All-time counts of orders you are responsible for, as on the Analytics page. "pending" can include checkouts that were never paid.',
                'completion_rate_percent' => round((float) $analytics['completionRate'], 1),
            ],
            'top_products_all_time' => $analytics['topProducts']->map(fn ($p) => [
                'name' => VendorToolSupport::serviceName($p->name),
                'orders' => $p->orders_count,
                'amount' => VendorToolSupport::money($p->total_sales),
            ])->values()->all(),
            'earnings_last_7_days' => array_map(fn ($d) => [
                'date' => $d['date'],
                'earnings' => VendorToolSupport::money($d['amount']),
            ], $analytics['salesTrend']),
            'earnings_last_7_days_note' => 'Counts transactions marked "completed" only, as on the Analytics page.',
            'unavailable' => [
                'failed_or_pending_orders_for_a_period' => 'The dashboard only reports status counts for all time, not per period.',
            ],
        ];
    }
}
