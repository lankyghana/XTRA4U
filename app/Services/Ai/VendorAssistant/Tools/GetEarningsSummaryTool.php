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
 * Sales vs commission vs earnings for one period, from the SAME service call the dashboard cards use. It reports what
 * the dashboard records and says plainly what it does not (for example: there is no commission figure for AFA or
 * result-checker sales). It never derives a figure: "sales minus commission" is NOT offered as earnings.
 *
 * Wallet balances, withdrawals and payouts are out of scope on purpose; this tool reads none of them.
 */
final class GetEarningsSummaryTool implements AiTool
{
    use VendorSurfaceTool;

    public const NAME = 'vendor_get_earnings_summary';

    public function __construct(private readonly VendorDashboardReadService $dashboard) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Your sales, commission deducted and net earnings for a period, split by source, using the dashboard\'s own figures. Does not show wallet balance or withdrawals.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::enum('period', 'Reporting period. Defaults to today.', VendorDashboardReadService::PERIODS),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $period = $arguments->string('period', 'today');
        $summary = $this->dashboard->salesSummary((int) $context->vendorId, $period);
        $s = $summary['sources'];

        return [
            'currency' => VendorToolSupport::CURRENCY,
            'period' => $period,
            'period_label' => VendorToolSupport::PERIOD_LABELS[$period] ?? $period,
            'period_range' => VendorToolSupport::periodRange($this->dashboard->dateRange($period)),
            'gross_sales' => VendorToolSupport::money($summary['sales']),
            'commission_deducted_on_product_orders' => VendorToolSupport::money($summary['commissions']),
            'net_earnings' => VendorToolSupport::money($summary['earnings']),
            'breakdown' => [
                'product_orders' => [
                    'gross_sales' => VendorToolSupport::money($s['product_orders']['sales']),
                    'commission_deducted' => VendorToolSupport::money($s['product_orders']['commissions']),
                    'net_earnings' => VendorToolSupport::money($s['product_orders']['earnings']),
                ],
                'afa_registrations' => [
                    'gross_sales' => VendorToolSupport::money($s['afa']['sales']),
                    'commission_deducted' => null,
                    'net_earnings' => VendorToolSupport::money($s['afa']['earnings']),
                ],
                'result_checkers' => [
                    'gross_sales' => VendorToolSupport::money($s['result_checker']['sales']),
                    'commission_deducted' => null,
                    'net_earnings' => VendorToolSupport::money($s['result_checker']['earnings']),
                ],
            ],
            'definitions' => [
                'gross_sales' => 'Sales value as the dashboard counts it: product-order transaction amounts, AFA provider price (plus your markup when you resell AFA) and result-checker sales.',
                'net_earnings' => 'What the dashboard records as your earning. It is a recorded figure, NOT gross sales minus commission, because resellers and providers are credited differently.',
                'commission' => 'Platform commission deducted, as recorded on product-order transactions.',
            ],
            'unavailable' => [
                'commission_for_afa_and_result_checkers' => 'The dashboard records no commission figure for these, so none is shown. Their net earnings are still included above.',
                'wallet_balance_and_withdrawals' => 'Not part of this tool. Earnings are not the same as wallet balance.',
            ],
        ];
    }
}
