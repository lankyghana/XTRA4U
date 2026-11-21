<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Models\Vendor;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CHARACTERISATION of the existing vendor dashboard, written BEFORE its read logic was extracted into
 * App\Services\Vendor\VendorDashboardReadService. These tests drive the real HTTP pages (not the service) and record
 * what they actually showed on the original controller, so they prove the extraction changed nothing. They are kept
 * as permanent regression tests: if one fails, a dashboard figure moved.
 *
 * Several of the recorded behaviours are quirks, kept on purpose (see docs/ai/04-vendor-assistant.md "Known quirks"):
 *  - the dashboard counts transactions in ('completed','successful'); the analytics page counts only 'completed';
 *  - an unrecognised ?filter= silently means "today";
 *  - commissions cover product-order transactions only (AFA / result-checker have no commission figure there).
 */
class DashboardCharacterizationTest extends VendorAssistantTestCase
{
    /** @return array<string,array{0:string,1:string,2:string,3:string}> filter => [sales, earnings, commissions] as the page prints them */
    public static function periods(): array
    {
        // today:     tx 100/98/2   + AFA provider 30/29.40 + AFA reseller (50-40)=10/9.00 + RC 25/5
        // yesterday: tx  50/49/1   + RC 50 / stored profit 10
        // this_week: today + yesterday + Monday tx 200/196/4
        // this_month: this_week + Nov 3 tx 400/392/8
        // all_time:  this_month + Oct 20 tx 1000/980/20
        return [
            'today' => ['today', '165.00', '141.40', '2.00'],
            'yesterday' => ['yesterday', '100.00', '59.00', '1.00'],
            'this_week' => ['this_week', '465.00', '396.40', '7.00'],
            'this_month' => ['this_month', '865.00', '788.40', '15.00'],
            'all_time' => ['all_time', '1,865.00', '1,768.40', '35.00'],
            'unknown filter falls back to today' => ['bogus', '165.00', '141.40', '2.00'],
        ];
    }

    #[DataProvider('periods')]
    public function test_sales_stats_endpoint_figures(string $filter, string $sales, string $earnings, string $commissions): void
    {
        $w = $this->world();

        $this->signedIn($w['a'])
            ->getJson('/vendor/dashboard/sales-stats?filter='.$filter)
            ->assertOk()
            ->assertExactJson(['sales' => $sales, 'earnings' => $earnings, 'commissions' => $commissions, 'filter' => $filter]);
    }

    #[DataProvider('periods')]
    public function test_dashboard_page_shows_the_same_figures(string $filter, string $sales, string $earnings, string $commissions): void
    {
        $w = $this->world();

        $response = $this->signedIn($w['a'])->get('/vendor/dashboard?filter='.$filter)->assertOk();

        $this->assertEqualsWithDelta((float) str_replace(',', '', $sales), (float) $response->viewData('totalSales'), 0.001);
        $this->assertEqualsWithDelta((float) str_replace(',', '', $earnings), (float) $response->viewData('totalEarnings'), 0.001);
        $this->assertEqualsWithDelta((float) $commissions, (float) $response->viewData('commissions'), 0.001);
    }

    public function test_dashboard_defaults_to_today_with_no_filter(): void
    {
        $w = $this->world();

        $response = $this->signedIn($w['a'])->get('/vendor/dashboard')->assertOk();

        $this->assertSame('today', $response->viewData('filter'));
        $this->assertEqualsWithDelta(165.0, (float) $response->viewData('totalSales'), 0.001);
    }

    public function test_other_vendors_figures_never_leak_into_the_totals(): void
    {
        $w = $this->world();

        // Vendor B only has the 7777 transaction plus nothing else of its own today.
        $this->signedIn($w['b'])
            ->getJson('/vendor/dashboard/sales-stats?filter=today')
            ->assertOk()
            ->assertJson(['sales' => '7,777.00', 'earnings' => '7,621.46', 'commissions' => '155.54']);

        $this->signedIn($w['c'])
            ->getJson('/vendor/dashboard/sales-stats?filter=all_time')
            ->assertOk()
            // C is the provider on the reseller AFA registration: provider sales = vendor_price (40), earning = vendor_earning (39.20).
            ->assertJson(['sales' => '40.00', 'earnings' => '39.20', 'commissions' => '0.00']);
    }

    public function test_the_dashboard_own_products_list_is_the_vendors_own_products_only(): void
    {
        $w = $this->world();
        $this->makeProduct($w['c'], 'Charlie Secret Bundle');

        $products = $this->signedIn($w['a'])->get('/vendor/dashboard')->viewData('products');

        $this->assertSame(['MTN 5GB'], $products->pluck('name')->all());
    }

    // ------------------------------------------------------------------------------------------ orders page

    public function test_orders_page_ownership_and_visibility_rules(): void
    {
        $w = $this->world();
        [$a, $b, $c, $listing] = [$w['a'], $w['b'], $w['c'], $w['listing']];

        $own = $this->makeOrder(['vendor_id' => $a->id]);
        $pending = $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Pending']);
        $unpaid = $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Processing', 'payment_status' => 'unpaid']);
        $failed = $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Failed']);
        $altPaid = $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Processing', 'payment_status' => 'completed']);

        // B resold A's product: B is the seller, A is the owner.
        $affiliate = $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'reseller_vendor_id' => $b->id, 'is_reseller_order' => true, 'reseller_product_id' => $listing->id]);
        // Legacy shape: owner_vendor_id missing, ownership only derivable through the reseller listing.
        $legacy = $this->makeOrder(['vendor_id' => $b->id, 'is_reseller_order' => true, 'reseller_product_id' => $listing->id]);
        // owner_vendor_id set but the order is NOT flagged as a reseller order: the owner does not see it.
        $notFlagged = $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'is_reseller_order' => false]);
        $foreign = $this->makeOrder(['vendor_id' => $c->id]);

        $idsFor = fn (Vendor $v) => $this->signedIn($v)->get('/vendor/orders')->assertOk()->viewData('orders')->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([$own, $failed, $altPaid, $affiliate, $legacy])->pluck('id')->sort()->values()->all(),
            $idsFor($a),
            'vendor A: own paid non-pending orders, plus affiliate orders for products A owns'
        );

        $this->assertSame(
            collect([$affiliate, $legacy, $notFlagged])->pluck('id')->sort()->values()->all(),
            $idsFor($b),
            'vendor B: everything B sold'
        );

        $this->assertSame([$foreign->id], $idsFor($c));
        $this->assertNotContains($pending->id, $idsFor($a));
        $this->assertNotContains($unpaid->id, $idsFor($a));
    }

    public function test_orders_page_search_matches_service_text_and_numeric_id(): void
    {
        $w = $this->world();
        $hit = $this->makeOrder(['vendor_id' => $w['a']->id, 'service_purchased' => 'Zebra Special']);
        $miss = $this->makeOrder(['vendor_id' => $w['a']->id, 'service_purchased' => 'Other']);

        $byText = $this->signedIn($w['a'])->get('/vendor/orders?q=zebra')->viewData('orders')->pluck('id')->all();
        $this->assertSame([$hit->id], $byText);

        $byId = $this->signedIn($w['a'])->get('/vendor/orders?q=%23'.$miss->id)->viewData('orders')->pluck('id')->all();
        $this->assertContains($miss->id, $byId);
    }

    // ---------------------------------------------------------------------------------------- analytics page

    public function test_analytics_page_figures(): void
    {
        $w = $this->world();
        [$a, $b] = [$w['a'], $w['b']];

        // Orders A is responsible for.
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Completed', 'service_purchased' => 'MTN 5GB', 'amount_paid' => 40], '2026-11-18 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Completed', 'service_purchased' => 'MTN 5GB', 'amount_paid' => 40], '2026-11-17 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Processing', 'service_purchased' => 'Voda 2GB', 'amount_paid' => 15], '2026-11-03 09:00:00');
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Failed', 'service_purchased' => 'Voda 2GB', 'amount_paid' => 15], '2026-10-01 09:00:00');
        // Pending + unpaid is counted by the status breakdown (no payment filter there) but not by the visible-order totals.
        $this->makeOrder(['vendor_id' => $a->id, 'status' => 'Pending', 'payment_status' => 'unpaid', 'service_purchased' => 'Voda 2GB', 'amount_paid' => 15], '2026-11-18 09:30:00');
        // Affiliate order: B sold, A owns. Counts for A as owner (status breakdown + top products via owner_earning).
        $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'reseller_vendor_id' => $b->id, 'is_reseller_order' => true,
            'status' => 'Completed', 'service_purchased' => 'MTN 5GB', 'amount_paid' => 43, 'owner_earning' => 39.2, 'reseller_earning' => 3.0], '2026-11-18 08:00:00');

        $r = $this->signedIn($a)->get('/vendor/analytics')->assertOk();

        // visible orders: paid and not Pending, as seller OR owner -> 4 own (Completed x2, Processing, Failed) + 1 affiliate = 5
        $this->assertSame(5, $r->viewData('totalOrders'));
        $this->assertSame(4, $r->viewData('ordersThisMonth'));   // Nov: 18th, 17th, Nov 3, affiliate 18th (the Oct 1 order is out)
        $this->assertSame(2, $r->viewData('ordersToday'));       // own 18th + affiliate 18th
        $this->assertSame(3, $r->viewData('ordersThisWeek'));    // Mon 16 .. Sun 22: own 18th, own 17th, affiliate 18th
        // Responsible orders: seller & not reseller (5 incl. the Pending one) + owner of reseller orders (1) = 6, 2 Completed + affiliate = 3
        $this->assertSame([
            'pending' => 1, 'processing' => 1, 'completed' => 3, 'failed' => 1, 'cancelled' => 0,
        ], $r->viewData('statusBreakdown'));
        $this->assertEqualsWithDelta(50.0, (float) $r->viewData('completionRate'), 0.001); // 3 of 6

        // Revenue summaries use transactions with payment_status = 'completed' ONLY (the yesterday 'successful' one is excluded).
        $this->assertEqualsWithDelta(98.0, (float) $r->viewData('revenueToday'), 0.001);
        $this->assertEqualsWithDelta(294.0, (float) $r->viewData('revenueThisWeek'), 0.001);  // 98 + Monday 196
        $this->assertEqualsWithDelta(686.0, (float) $r->viewData('revenueThisMonth'), 0.001); // + Nov 3 392

        // Average earning per completed/successful transaction (5 of them): (98+49+196+392+980)/5
        $this->assertEqualsWithDelta(343.0, (float) $r->viewData('averageOrderValue'), 0.001);

        $top = $r->viewData('topProducts');
        $this->assertSame('MTN 5GB', $top[0]->name);
        $this->assertSame(3, $top[0]->orders_count);              // 2 own + 1 affiliate (as owner)
        $this->assertEqualsWithDelta(40 + 40 + 39.2, $top[0]->total_sales, 0.001);
        $this->assertSame('Voda 2GB', $top[1]->name);
        $this->assertSame(2, $top[1]->orders_count);              // Processing + the unpaid Pending one; Failed / Cancelled are excluded

        $trend = $r->viewData('salesTrend');
        $this->assertCount(7, $trend);
        $this->assertSame('Nov 18', $trend[6]['date']);
        $this->assertEqualsWithDelta(98.0, (float) $trend[6]['amount'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $trend[5]['amount'], 0.001);   // yesterday's tx is 'successful' -> not in the trend
        $this->assertEqualsWithDelta(196.0, (float) $trend[4]['amount'], 0.001); // Monday
    }

    public function test_dashboard_and_analytics_pages_reject_other_vendors_data_for_a_vendor_with_nothing(): void
    {
        $lonely = Vendor::factory()->create(['is_approved' => true]);
        $this->world();

        $r = $this->signedIn($lonely)->get('/vendor/analytics')->assertOk();
        $this->assertSame(0, $r->viewData('totalOrders'));
        $this->assertSame(['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0], $r->viewData('statusBreakdown'));
        $this->assertEqualsWithDelta(0.0, (float) $r->viewData('completionRate'), 0.001);
    }
}
