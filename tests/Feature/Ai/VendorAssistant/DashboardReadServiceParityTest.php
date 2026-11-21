<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Services\Vendor\VendorDashboardReadService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The extracted VendorDashboardReadService, the real dashboard pages and the assistant's tools must report the SAME
 * figures. Each test computes the dashboard side through the real HTTP pages and the tool side through the real
 * executor, then compares; the headline numbers are also pinned to hand-checked literals (see world() in the base case).
 */
class DashboardReadServiceParityTest extends VendorAssistantTestCase
{
    /** period => [sales, earnings, commissions] for vendor A of world() */
    public static function periods(): array
    {
        return [
            'today' => ['today', '165.00', '141.40', '2.00'],
            'yesterday' => ['yesterday', '100.00', '59.00', '1.00'],
            'this_week' => ['this_week', '465.00', '396.40', '7.00'],
            'this_month' => ['this_month', '865.00', '788.40', '15.00'],
            'all_time' => ['all_time', '1865.00', '1768.40', '35.00'],
        ];
    }

    private function plain(string $formatted): float
    {
        return (float) str_replace(',', '', $formatted);
    }

    #[DataProvider('periods')]
    public function test_service_endpoint_page_and_both_tools_agree(string $period, string $sales, string $earnings, string $commissions): void
    {
        $w = $this->world();
        $a = $w['a'];
        $context = $this->vendorContext($a);

        $service = app(VendorDashboardReadService::class)->salesSummary($a->id, $period);
        $endpoint = $this->signedIn($a)->getJson('/vendor/dashboard/sales-stats?filter='.$period)->assertOk()->json();
        $page = $this->signedIn($a)->get('/vendor/dashboard?filter='.$period)->assertOk();
        $summary = $this->runTool('vendor_get_dashboard_summary', ['period' => $period], $context);
        $earn = $this->runTool('vendor_get_earnings_summary', ['period' => $period], $context, 'call_2');

        $this->assertTrue($summary->isOk());
        $this->assertTrue($earn->isOk());

        // 1. pinned to the hand-checked literals
        $this->assertSame($sales, $summary->data['totals']['sales']);
        $this->assertSame($earnings, $summary->data['totals']['earnings']);
        $this->assertSame($commissions, $summary->data['totals']['commissions']);

        // 2. service == endpoint == page == dashboard tool == earnings tool
        $this->assertEqualsWithDelta($service['sales'], $this->plain($endpoint['sales']), 0.005);
        $this->assertEqualsWithDelta($service['earnings'], $this->plain($endpoint['earnings']), 0.005);
        $this->assertEqualsWithDelta($service['commissions'], $this->plain($endpoint['commissions']), 0.005);

        $this->assertEqualsWithDelta($service['sales'], (float) $page->viewData('totalSales'), 0.005);
        $this->assertEqualsWithDelta($service['earnings'], (float) $page->viewData('totalEarnings'), 0.005);
        $this->assertEqualsWithDelta($service['commissions'], (float) $page->viewData('commissions'), 0.005);

        $this->assertSame($earn->data['gross_sales'], $summary->data['totals']['sales']);
        $this->assertSame($earn->data['net_earnings'], $summary->data['totals']['earnings']);
        $this->assertSame($earn->data['commission_deducted_on_product_orders'], $summary->data['totals']['commissions']);

        // 3. the per-source split adds back up to the totals
        $sources = $earn->data['breakdown'];
        $this->assertEqualsWithDelta(
            (float) $earn->data['gross_sales'],
            (float) $sources['product_orders']['gross_sales'] + (float) $sources['afa_registrations']['gross_sales'] + (float) $sources['result_checkers']['gross_sales'],
            0.005
        );
        $this->assertEqualsWithDelta(
            (float) $earn->data['net_earnings'],
            (float) $sources['product_orders']['net_earnings'] + (float) $sources['afa_registrations']['net_earnings'] + (float) $sources['result_checkers']['net_earnings'],
            0.005
        );
    }

    public function test_the_period_range_matches_the_dashboard_date_boundaries(): void
    {
        $w = $this->world();
        $service = app(VendorDashboardReadService::class);

        $expected = [
            'today' => ['2026-11-18', '2026-11-18'],
            'yesterday' => ['2026-11-17', '2026-11-17'],
            'this_week' => ['2026-11-16', '2026-11-18'],   // Monday .. today
            'this_month' => ['2026-11-01', '2026-11-18'],
            'all_time' => [null, null],
        ];

        foreach ($expected as $period => [$from, $to]) {
            $range = $this->runTool('vendor_get_dashboard_summary', ['period' => $period], $this->vendorContext($w['a']), 'c'.$period)->data['period_range'];
            $this->assertSame(['from' => $from, 'to' => $to], $range, $period);

            [$f, $t] = $service->dateRange($period);
            $this->assertSame($from, $f?->toDateString());
            $this->assertSame($to, $t?->toDateString());
        }

        // An unknown period behaves as "today" in the service, exactly like the dashboard's ?filter=
        $this->assertSame($service->salesSummary($w['a']->id, 'today'), $service->salesSummary($w['a']->id, 'nonsense'));
    }

    public function test_rounding_matches_the_dashboard_for_fractional_amounts(): void
    {
        $vendor = $this->approvedVendor();
        // Thousandths and an awkward float sum: both sides must print the same two decimals.
        $this->makeTransaction($vendor, 10.105, 0.202, 9.903, '2026-11-18 09:00:00');
        $this->makeTransaction($vendor, 20.205, 0.404, 19.801, '2026-11-18 10:00:00');
        $this->makeTransaction($vendor, 0.1, 0.0, 0.1, '2026-11-18 11:00:00');
        $this->makeTransaction($vendor, 0.2, 0.0, 0.2, '2026-11-18 12:00:00');

        $endpoint = $this->signedIn($vendor)->getJson('/vendor/dashboard/sales-stats?filter=today')->json();
        $tool = $this->runTool('vendor_get_dashboard_summary', ['period' => 'today'], $this->vendorContext($vendor))->data['totals'];

        $this->assertSame($this->plain($endpoint['sales']), (float) $tool['sales']);
        $this->assertSame($this->plain($endpoint['earnings']), (float) $tool['earnings']);
        $this->assertSame($this->plain($endpoint['commissions']), (float) $tool['commissions']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $tool['sales'], 'money is always a plain two-decimal string');
    }

    public function test_order_counts_and_status_counts_match_the_analytics_page(): void
    {
        $w = $this->world();
        [$a, $b] = [$w['a'], $w['b']];

        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Completed', 'service_purchased' => 'MTN 5GB'], '2026-11-18 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Completed', 'service_purchased' => 'MTN 5GB'], '2026-11-17 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Processing', 'service_purchased' => 'Voda 2GB'], '2026-11-03 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Failed', 'service_purchased' => 'Voda 2GB'], '2026-10-01 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Pending', 'payment_status' => 'unpaid', 'service_purchased' => 'Voda 2GB'], '2026-11-18 09:30:00');
        $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'is_reseller_order' => true, 'status' => 'Completed',
            'service_purchased' => 'MTN 5GB', 'owner_earning' => 39.2, 'reseller_earning' => 3.0], '2026-11-18 08:00:00');

        $analytics = $this->signedIn($a)->get('/vendor/analytics')->assertOk();
        $context = $this->vendorContext($a);

        $expectedByPeriod = [
            'today' => $analytics->viewData('ordersToday'),
            'this_week' => $analytics->viewData('ordersThisWeek'),
            'this_month' => $analytics->viewData('ordersThisMonth'),
            'all_time' => $analytics->viewData('totalOrders'),
            'yesterday' => null,   // the dashboard has no "yesterday" order count: reported as unavailable, not invented
        ];

        foreach ($expectedByPeriod as $period => $expected) {
            $data = $this->runTool('vendor_get_dashboard_summary', ['period' => $period], $context, 'c_'.$period)->data;
            $this->assertSame($expected, $data['orders']['orders_in_period'], $period);
        }

        $data = $this->runTool('vendor_get_dashboard_summary', ['period' => 'all_time'], $context)->data;
        $this->assertSame($analytics->viewData('statusBreakdown'), $data['orders']['status_counts_all_time']);
        $this->assertEqualsWithDelta((float) $analytics->viewData('completionRate'), $data['orders']['completion_rate_percent'], 0.05);

        // top products and the 7-day trend are the Analytics page's own numbers
        $top = $analytics->viewData('topProducts');
        $this->assertSame($top->pluck('name')->all(), array_column($data['top_products_all_time'], 'name'));
        $this->assertSame($top->pluck('orders_count')->all(), array_column($data['top_products_all_time'], 'orders'));
        $trend = $analytics->viewData('salesTrend');
        $this->assertSame(array_column($trend, 'date'), array_column($data['earnings_last_7_days'], 'date'));
        foreach ($trend as $i => $day) {
            $this->assertEqualsWithDelta((float) $day['amount'], (float) $data['earnings_last_7_days'][$i]['earnings'], 0.005);
        }
    }

    public function test_the_tools_use_the_dashboard_read_service_not_their_own_queries(): void
    {
        // The money tools contain no SQL of their own: every figure is a VendorDashboardReadService call.
        foreach (['GetDashboardSummaryTool', 'GetEarningsSummaryTool'] as $class) {
            $source = (string) file_get_contents(app_path("Services/Ai/VendorAssistant/Tools/{$class}.php"));
            $this->assertStringContainsString('VendorDashboardReadService', $source);
            $this->assertDoesNotMatchRegularExpression('/(::query\(|DB::|->where\(|selectRaw|->sum\(|->avg\()/', $source, "{$class} must not run queries or sum anything itself");
        }
    }

    public function test_the_orders_tools_see_exactly_the_orders_page_rows(): void
    {
        $w = $this->world();
        [$a, $b, $c, $listing] = [$w['a'], $w['b'], $w['c'], $w['listing']];

        $this->makeOrder(['vendor_id' => $a->id]);
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Pending']);
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Processing', 'payment_status' => 'unpaid']);
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Failed']);
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Processing', 'payment_status' => 'completed']);
        $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'is_reseller_order' => true, 'reseller_product_id' => $listing->id]);
        $this->makeOrder(['vendor_id' => $b->id, 'is_reseller_order' => true, 'reseller_product_id' => $listing->id]);
        $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'is_reseller_order' => false]);
        $this->makeOrder(['vendor_id' => $c->id]);

        foreach ([$a, $b, $c] as $vendor) {
            $page = $this->signedIn($vendor)->get('/vendor/orders')->viewData('orders')->pluck('id')->sort()->values()->all();
            $tool = collect($this->runTool('vendor_search_my_orders', [], $this->vendorContext($vendor))->data['orders'])->pluck('ref')->sort()->values()->all();

            $this->assertSame($page, $tool, "vendor {$vendor->name}: the assistant lists the same orders as the Orders page");
        }
    }

    public function test_product_availability_matches_the_public_storefront(): void
    {
        $w = $this->world();
        [$a, $b] = [$w['a'], $w['b']];

        $p1 = $this->makeProduct($a, 'ZQX Alpha Open');
        $p2 = $this->makeProduct($a, 'ZQX Beta ListingOff');
        $p3 = $this->makeProduct($a, 'ZQX Gamma OwnerOff', 10, [], false);
        $p4 = $this->makeProduct($a, 'ZQX Delta NotResellable', 10, [], true, false);
        $p5 = $this->makeProduct($a, 'ZQX Epsilon Withdrawn');
        $ownOff = $this->makeProduct($b, 'ZQX Zeta OwnInactive', 10, [], false);
        $ownOn = $this->makeProduct($b, 'ZQX Eta OwnActive');

        $this->makeListing($p1, $b);
        $this->makeListing($p2, $b, 2.0, false);
        $this->makeListing($p3, $b);
        $this->makeListing($p4, $b);
        $this->makeListing($p5, $b);
        $p5->delete();   // soft delete: withdrawn by the owner

        $html = $this->get(route('storefront.vendor', ['vendor' => $b->vendor_code]))->assertOk()->getContent();
        $rows = collect($this->runTool('vendor_list_my_products', ['limit' => 20], $this->vendorContext($b))->data['products'])->keyBy('name');

        $expectedOnSale = [
            'ZQX Alpha Open' => true,          // active listing of an active, resellable product
            'ZQX Beta ListingOff' => false,    // the reseller switched the listing off
            'ZQX Gamma OwnerOff' => false,     // the owner deactivated the product
            'ZQX Delta NotResellable' => false, // the owner no longer allows resale
            'ZQX Eta OwnActive' => true,
            'ZQX Zeta OwnInactive' => false,
            'MTN 5GB' => true,                 // the listing from world()
        ];

        foreach ($expectedOnSale as $name => $onSale) {
            $this->assertTrue($rows->has($name), "{$name} is in the vendor's catalogue");
            $this->assertSame($onSale, $rows[$name]['on_sale'], "{$name}: on_sale as the tool reports it");
            $this->assertSame($onSale, str_contains($html, $name), "{$name}: shown on the public storefront exactly when the tool says it is on sale");
        }

        // The withdrawn (soft-deleted) product: its listing is reported honestly, with no name to leak, and is not on the storefront.
        $withdrawn = $rows->get('Unavailable product');
        $this->assertNotNull($withdrawn);
        $this->assertFalse($withdrawn['on_sale']);
        $this->assertStringContainsString('withdrawn', $withdrawn['reason_not_on_sale']);
        $this->assertStringNotContainsString('ZQX Epsilon Withdrawn', $html);

        // The service's own predicate and query agree with both.
        $service = app(VendorDashboardReadService::class);
        $this->assertSame(2, $service->publiclyAvailableListingsQuery($b->id)->count());   // Alpha + the world() listing
        foreach ($service->resellerListingsQuery($b->id)->with('product')->get() as $listing) {
            $this->assertSame(
                $service->publiclyAvailableListingsQuery($b->id)->whereKey($listing->id)->exists(),
                $service->listingIsPubliclyAvailable($listing)
            );
        }
    }
}
