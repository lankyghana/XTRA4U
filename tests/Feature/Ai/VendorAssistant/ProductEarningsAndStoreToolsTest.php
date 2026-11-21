<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue tool, the earnings tool and the store-information tool.
 */
class ProductEarningsAndStoreToolsTest extends VendorAssistantTestCase
{
    private function products(array $args, Vendor $vendor, string $id = 'call_1'): array
    {
        return $this->runTool('vendor_list_my_products', $args, $this->vendorContext($vendor), $id)->data;
    }

    private function earnings(string $period, Vendor $vendor, string $id = 'call_1'): array
    {
        return $this->runTool('vendor_get_earnings_summary', ['period' => $period], $this->vendorContext($vendor), $id)->data;
    }

    // ------------------------------------------------------------------------------------------------ products

    public function test_only_the_vendors_own_sellable_catalogue_is_returned(): void
    {
        $w = $this->world();
        $this->makeProduct($w['b'], 'B Own Bundle', 12.5, ['category' => 'data', 'network' => 'Telecel']);

        $b = $this->products(['limit' => 20], $w['b']);
        $rows = collect($b['products'])->keyBy('name');

        $this->assertSame(['B Own Bundle', 'MTN 5GB'], $rows->keys()->sort()->values()->all());
        $this->assertSame('own_product', $rows['B Own Bundle']['kind']);
        $this->assertSame('12.50', $rows['B Own Bundle']['price']);
        $this->assertSame('Telecel', $rows['B Own Bundle']['network']);
        $this->assertSame('resold_listing', $rows['MTN 5GB']['kind']);
        $this->assertSame('43.00', $rows['MTN 5GB']['price'], 'the reseller sees its own selling price (owner base 40 + markup 3)');
        $this->assertSame(['own_products_on_sale' => 1, 'own_products_not_on_sale' => 0, 'resold_listings' => 1, 'resold_listings_on_sale' => 1], $b['counts']);
    }

    public function test_inactive_products_are_represented_accurately(): void
    {
        $a = $this->approvedVendor();
        $this->makeProduct($a, 'Alive One');
        $this->makeProduct($a, 'Dormant One', 5.0, [], false);

        $all = collect($this->products([], $a)['products'])->keyBy('name');
        $this->assertTrue($all['Alive One']['on_sale']);
        $this->assertFalse($all['Dormant One']['is_active']);
        $this->assertFalse($all['Dormant One']['on_sale']);

        $this->assertSame(['Alive One'], array_column($this->products(['status' => 'active'], $a, 'c2')['products'], 'name'));
        $this->assertSame(['Dormant One'], array_column($this->products(['status' => 'inactive'], $a, 'c3')['products'], 'name'));
        $this->assertSame(['Dormant One'], array_column($this->products(['name' => 'dormant'], $a, 'c4')['products'], 'name'));
    }

    public function test_reseller_listings_report_exactly_why_they_are_off_sale(): void
    {
        $w = $this->world();
        $owner = $w['a'];
        $reseller = $this->approvedVendor(['name' => 'Reseller R']);

        $pOk = $this->makeProduct($owner, 'Why OK');
        $pOff = $this->makeProduct($owner, 'Why OwnerOff', 1, [], false);
        $pNoResale = $this->makeProduct($owner, 'Why NoResale', 1, [], true, false);
        $pGone = $this->makeProduct($owner, 'Why Gone');
        $pListingOff = $this->makeProduct($owner, 'Why ListingOff');
        foreach ([$pOk, $pOff, $pNoResale, $pGone] as $p) {
            $this->makeListing($p, $reseller);
        }
        $this->makeListing($pListingOff, $reseller, 2.0, false);
        $pGone->delete();

        $data = $this->products(['limit' => 20], $reseller);
        $rows = collect($data['products']);
        $byName = $rows->keyBy('name');

        $this->assertTrue($byName['Why OK']['on_sale']);
        $this->assertNull($byName['Why OK']['reason_not_on_sale']);
        $this->assertSame('The owner switched this product off.', $byName['Why OwnerOff']['reason_not_on_sale']);
        $this->assertSame('The owner no longer allows resale of this product.', $byName['Why NoResale']['reason_not_on_sale']);
        $this->assertSame('You switched this listing off.', $byName['Why ListingOff']['reason_not_on_sale']);
        $this->assertSame('The product you resell was withdrawn by its owner.', $byName['Unavailable product']['reason_not_on_sale']);

        $this->assertSame(['own_products_on_sale' => 0, 'own_products_not_on_sale' => 0, 'resold_listings' => 5, 'resold_listings_on_sale' => 1], $data['counts']);
    }

    public function test_pins_provider_mappings_and_supplier_data_are_never_returned(): void
    {
        $a = $this->approvedVendor();
        $this->makeProduct($a, 'Mapped Bundle', 10.0, [
            'external_mappings' => ['gigshub' => ['service_id' => 'SECRET-SERVICE-ID-777', 'price' => 6.66, 'network' => 'MTN', 'offer_slug' => 'SECRET-SLUG']],
            'external_service_id' => 'SECRET-EXT-ID',
            'api_key' => 'sk_live_SECRET_KEY_VALUE',
            'delivered_pins' => 'PIN-123456',
            'notes' => 'internal note CANARY',
        ]);

        $payload = $this->payload($this->runTool('vendor_list_my_products', [], $this->vendorContext($a)));

        foreach (['SECRET-SERVICE-ID-777', '6.66', 'SECRET-SLUG', 'SECRET-EXT-ID', 'sk_live', 'PIN-123456', 'internal note', 'external_mappings', 'gigshub', 'service_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload, "leaked {$secret}");
        }
        $this->assertStringContainsString('Mapped Bundle', $payload);
    }

    public function test_the_catalogue_is_bounded(): void
    {
        $a = $this->approvedVendor();
        foreach (range(1, 30) as $i) {
            $this->makeProduct($a, sprintf('Bulk %02d', $i));
        }

        $default = $this->products([], $a);
        $this->assertCount(10, $default['products']);
        $this->assertTrue($default['has_more']);

        $max = $this->products(['limit' => 20], $a, 'c2');
        $this->assertCount(20, $max['products']);

        foreach ([0, 21, 1000] as $i => $bad) {
            $this->assertSame('invalid_arguments', $this->runTool('vendor_list_my_products', ['limit' => $bad], $this->vendorContext($a), 'd'.$i)->errorCode);
        }
        $this->assertSame('invalid_arguments', $this->runTool('vendor_list_my_products', ['status' => 'deleted'], $this->vendorContext($a), 'e')->errorCode);
    }

    public function test_a_vendor_with_no_products_gets_an_empty_honest_answer(): void
    {
        $data = $this->products([], $this->approvedVendor());

        $this->assertSame([], $data['products']);
        $this->assertSame(['own_products_on_sale' => 0, 'own_products_not_on_sale' => 0, 'resold_listings' => 0, 'resold_listings_on_sale' => 0], $data['counts']);
    }

    public function test_the_catalogue_tool_changes_nothing(): void
    {
        $w = $this->world();
        $before = md5(DB::table('products')->get()->toJson().DB::table('reseller_products')->get()->toJson().DB::table('vendors')->get()->toJson());

        $this->products(['limit' => 20], $w['b']);
        $this->runTool('vendor_list_my_products', ['is_active' => false, 'price' => 1], $this->vendorContext($w['b']), 'c2');

        $this->assertSame($before, md5(DB::table('products')->get()->toJson().DB::table('reseller_products')->get()->toJson().DB::table('vendors')->get()->toJson()));
    }

    // ------------------------------------------------------------------------------------------------ earnings

    public function test_earnings_distinguish_sales_commission_and_net_earnings(): void
    {
        $w = $this->world();
        $data = $this->earnings('today', $w['a']);

        $this->assertSame('GHS', $data['currency']);
        $this->assertSame('today', $data['period']);
        $this->assertSame(['from' => '2026-11-18', 'to' => '2026-11-18'], $data['period_range']);
        $this->assertSame('165.00', $data['gross_sales']);
        $this->assertSame('2.00', $data['commission_deducted_on_product_orders']);
        $this->assertSame('141.40', $data['net_earnings']);

        // Net earnings is a recorded figure, not sales minus commission: here 165 - 2 = 163, not 141.40.
        $this->assertNotSame(number_format(165 - 2, 2, '.', ''), $data['net_earnings']);
        $this->assertStringContainsString('NOT gross sales minus commission', $data['definitions']['net_earnings']);
    }

    public function test_missing_commission_figures_are_reported_unavailable_not_invented(): void
    {
        $w = $this->world();
        $data = $this->earnings('today', $w['a']);

        $this->assertNull($data['breakdown']['afa_registrations']['commission_deducted']);
        $this->assertNull($data['breakdown']['result_checkers']['commission_deducted']);
        $this->assertArrayHasKey('commission_for_afa_and_result_checkers', $data['unavailable']);

        // A vendor with no activity at all gets zeros, never a made-up figure, and the same honest notes.
        $empty = $this->earnings('all_time', $this->approvedVendor(), 'c2');
        $this->assertSame('0.00', $empty['gross_sales']);
        $this->assertSame('0.00', $empty['net_earnings']);
        $this->assertSame('0.00', $empty['commission_deducted_on_product_orders']);
        $this->assertNull($empty['breakdown']['afa_registrations']['commission_deducted']);
    }

    public function test_date_ranges_are_respected(): void
    {
        $w = $this->world();

        $this->assertSame('100.00', $this->earnings('yesterday', $w['a'])['gross_sales'], 'yesterday excludes today');
        $this->assertSame('165.00', $this->earnings('today', $w['a'], 'c2')['gross_sales'], 'today excludes yesterday');
        $this->assertSame('465.00', $this->earnings('this_week', $w['a'], 'c3')['gross_sales']);
        $this->assertSame('865.00', $this->earnings('this_month', $w['a'], 'c4')['gross_sales'], 'excludes the October transaction');
        $this->assertSame('1865.00', $this->earnings('all_time', $w['a'], 'c5')['gross_sales']);

        foreach (['last_year', 'custom', '2026-11-01'] as $i => $bad) {
            $this->assertSame('invalid_arguments', $this->runTool('vendor_get_earnings_summary', ['period' => $bad], $this->vendorContext($w['a']), 'd'.$i)->errorCode, "period '{$bad}'");
        }
    }

    public function test_reseller_and_provider_attribution_matches_the_dashboard(): void
    {
        $w = $this->world();

        // A resold C's AFA registration: A earns the reseller markup; C is the provider and earns vendor_earning.
        $a = $this->earnings('today', $w['a'])['breakdown']['afa_registrations'];
        $c = $this->earnings('all_time', $w['c'], 'c2')['breakdown']['afa_registrations'];

        $this->assertSame(['gross_sales' => '40.00', 'commission_deducted' => null, 'net_earnings' => '38.40'], $a);  // provider own 30/29.40 + reseller (50-40)/9.00
        $this->assertSame(['gross_sales' => '40.00', 'commission_deducted' => null, 'net_earnings' => '39.20'], $c);  // provider side of the reseller order

        // B, an unrelated reseller of a data product, has no AFA earnings.
        $b = $this->earnings('all_time', $w['b'], 'c3')['breakdown']['afa_registrations'];
        $this->assertSame('0.00', $b['net_earnings']);
    }

    public function test_the_earnings_tool_does_not_touch_wallets_withdrawals_or_payouts(): void
    {
        $w = $this->world();
        DB::table('vendors')->where('id', $w['a']->id)->update(['wallet_balance' => 4321.99, 'balance' => 1234.56]);
        $before = md5(DB::table('vendors')->get()->toJson().DB::table('vendor_withdrawals')->get()->toJson().DB::table('wallet_ledgers')->get()->toJson());

        $payload = $this->payload($this->runTool('vendor_get_earnings_summary', ['period' => 'all_time'], $this->vendorContext($w['a'])));

        $this->assertStringNotContainsString('4321.99', $payload, 'wallet balance is not an earnings figure');
        $this->assertStringNotContainsString('1234.56', $payload);
        $this->assertSame($before, md5(DB::table('vendors')->get()->toJson().DB::table('vendor_withdrawals')->get()->toJson().DB::table('wallet_ledgers')->get()->toJson()));
        $this->assertSame(0, DB::table('vendor_withdrawals')->count());
        $this->assertArrayHasKey('wallet_balance_and_withdrawals', $this->earnings('all_time', $w['a'], 'c2')['unavailable']);
    }

    // ------------------------------------------------------------------------------------------------ store info

    public function test_store_information_is_the_authenticated_vendors_own_and_accurate(): void
    {
        $w = $this->world();
        $this->makeProduct($w['a'], 'Inactive A', 1, [], false);

        $a = $this->runTool('vendor_get_my_store_info', [], $this->vendorContext($w['a']))->data;
        $b = $this->runTool('vendor_get_my_store_info', [], $this->vendorContext($w['b']), 'c2')->data;

        $this->assertTrue($a['found']);
        $this->assertSame('Alpha Data', $a['store_name']);
        $this->assertSame($w['a']->vendor_code, $a['store_code']);
        $this->assertSame(route('storefront.vendor', ['vendor' => $w['a']->vendor_code]), $a['store_url']);
        $this->assertSame('approved', $a['account_status']);
        $this->assertSame(['own_products' => 1, 'resold_products' => 0], $a['on_sale_now']);
        $this->assertSame(['own_products' => 1, 'resold_products' => 0], $a['not_on_sale_now']);
        $this->assertFalse($a['afa_registration_offered']);

        $this->assertSame('Bravo Reseller', $b['store_name']);
        $this->assertSame(['own_products' => 0, 'resold_products' => 1], $b['on_sale_now']);
        $this->assertStringNotContainsString('Alpha Data', json_encode($b));
    }

    public function test_the_store_url_is_the_real_public_storefront_and_the_status_follows_the_account(): void
    {
        $w = $this->world();
        $data = $this->runTool('vendor_get_my_store_info', [], $this->vendorContext($w['a']))->data;

        $this->get($data['store_url'])->assertOk();

        DB::table('vendors')->where('id', $w['a']->id)->update(['afa_enabled' => true, 'afa_price' => 25]);
        $this->assertTrue($this->runTool('vendor_get_my_store_info', [], $this->vendorContext($w['a']->fresh()), 'c2')->data['afa_registration_offered']);
    }

    public function test_private_account_data_is_excluded_from_store_information(): void
    {
        $vendor = $this->approvedVendor([
            'name' => 'Private Test Store',
            'email' => 'canary.owner@private-canary.test',
            'phone_number' => '0509998877',
        ]);
        DB::table('vendors')->where('id', $vendor->id)->update([
            'wallet_balance' => 8765.43, 'balance' => 7654.32, 'afa_base_price' => 11.11, 'tier_id' => null,
        ]);

        $result = $this->runTool('vendor_get_my_store_info', [], $this->vendorContext($vendor->fresh()));
        $payload = $this->payload($result);

        foreach (['canary.owner', 'private-canary', '0509998877', '050999', '8765.43', '7654.32', '11.11', 'password', 'remember_token', 'wallet_balance', 'momo'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload, "leaked {$secret}");
        }
        $this->assertNull($result->data['public_contact_details']);
        $this->assertNull($result->data['public_description']);
    }

    public function test_the_tool_never_returns_the_phone_the_storefront_displays_and_says_so_honestly(): void
    {
        $vendor = $this->approvedVendor(['name' => 'Contact Check Store', 'email' => 'visible.check@contact-canary.test', 'phone_number' => '0501234999']);
        $this->makeProduct($vendor, 'Visible Product');

        // The real storefront displays the vendor's phone number (documented), but never the email.
        $html = $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]))->assertOk()->getContent();
        $this->assertStringContainsString('0501234999', $html);
        $this->assertStringNotContainsString('contact-canary.test', $html);

        $result = $this->runTool('vendor_get_my_store_info', [], $this->vendorContext($vendor));
        $payload = $this->payload($result);

        $this->assertStringNotContainsString('0501234999', $payload);
        $this->assertStringNotContainsString('contact-canary', $payload);
        $this->assertStringContainsString('displays your account phone number to customers', $result->data['note'], 'the tool must not claim the page has no contact details');
    }

    public function test_the_store_tool_takes_no_arguments_and_changes_nothing(): void
    {
        $w = $this->world();
        $before = md5(DB::table('vendors')->get()->toJson());

        $this->assertSame('invalid_arguments', $this->runTool('vendor_get_my_store_info', ['name' => 'New Name'], $this->vendorContext($w['a']))->errorCode);
        $this->runTool('vendor_get_my_store_info', [], $this->vendorContext($w['a']), 'c2');

        $this->assertSame($before, md5(DB::table('vendors')->get()->toJson()));
    }
}
