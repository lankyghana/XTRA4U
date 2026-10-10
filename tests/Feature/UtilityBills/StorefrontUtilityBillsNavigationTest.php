<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\UtilityBillSettings;
use App\Support\PlatformServiceVendor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The storefront "Utility Bills" category tile is a direct link to that storefront's own
 * Utility Bills page. It never enters the Choose Service / Select Package pipeline, so no
 * synthetic product, package or GH₵0.00 price exists for it.
 */
class StorefrontUtilityBillsNavigationTest extends UtilityBillTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_123', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);

        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    }

    private function fakeAll(): void
    {
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => [
                'account_name' => null, 'account_number' => null, 'amount_due' => null, 'bouquet' => null,
                'meters' => [['name' => 'KWAME MENSAH', 'meterNumber' => '3701234567', 'outstanding' => 245.8]],
            ]]),
            'https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.example/pay']]),
        ]);
    }

    private function tileLink(Vendor $vendor): string
    {
        return '<a href="'.route('storefront.utility-bills', ['vendor' => $vendor->vendor_code]).'" class="x4-cat-tile">';
    }

    private function utilityCategory($response): array
    {
        return collect($response->viewData('categories'))->firstWhere('value', 'ecg');
    }

    public function test_tile_links_directly_to_each_storefronts_own_utility_bills_page(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $a = Vendor::factory()->create(['is_approved' => true]);
        $b = Vendor::factory()->create(['is_approved' => true]);

        foreach ([[$a, $b], [$b, $a]] as [$vendor, $other]) {
            $page = $this->get('/store/'.$vendor->vendor_code)->assertOk()
                ->assertSee($this->tileLink($vendor), false)
                ->assertDontSee('/store/'.$other->vendor_code.'/utility-bills', false);

            $category = $this->utilityCategory($page);
            $this->assertTrue($category['is_utility_bills']);
            $this->assertSame(url('/store/'.$vendor->vendor_code.'/utility-bills'), $category['url']);

            // The destination is the vendor-branded Utility Bills page.
            $this->get($category['url'])->assertOk()->assertSee($vendor->name)->assertSee('ECG Prepaid');
        }
    }

    public function test_no_synthetic_utility_service_or_zero_price_package_is_rendered(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $page = $this->get('/store/'.$vendor->vendor_code)->assertOk()
            ->assertDontSee('utility_bills_service')
            ->assertDontSee('utility_bills_package')
            ->assertDontSee('utility_url');

        $this->assertTrue(collect($page->viewData('services'))->where('category', 'ecg')->isEmpty());
    }

    public function test_tile_shows_how_many_billers_are_available(): void
    {
        $this->openService();   // ECG, Ghana Water, DSTV, GOtv, StarTimes
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $html = $this->get('/store/'.$vendor->vendor_code)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/'.preg_quote($this->tileLink($vendor), '/').'.*?Utility Bills.*?5 available.*?<\/a>/s', $html);
    }

    public function test_utility_bills_needs_no_vendor_product_or_assignment(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->assertSame(0, Product::count());
        $this->assertNull(PlatformServiceVendor::vendorIdFor('ecg'));

        $this->get('/store/'.$vendor->vendor_code)->assertOk()->assertSee($this->tileLink($vendor), false);
        $this->get(route('storefront.utility-bills', ['vendor' => $vendor->vendor_code]))->assertOk();
        $this->assertSame(0, Product::count());
    }

    public function test_sale_through_the_linked_page_is_attributed_to_that_storefront_vendor(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        Vendor::factory()->create(['is_approved' => true]);   // another storefront that must not be credited

        $url = $this->utilityCategory($this->get('/store/'.$vendor->vendor_code))['url'];
        $this->get($url)->assertOk();

        $lookup = $this->postJson(route('storefront.utility-bills.lookup', $vendor->vendor_code), ['biller' => 'ecg', 'phone' => '0551617309'])
            ->assertOk()->json();
        $this->postJson(route('storefront.utility-bills.checkout', $vendor->vendor_code), [
            'lookup_token' => $lookup['token'], 'selected_meter_id' => $lookup['meters'][0]['id'], 'amount' => '50', 'vendor_id' => 999,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame($vendor->id, UtilityBillOrder::sole()->vendor_id);
    }

    public function test_disabled_utility_bills_shows_a_closed_tile_and_accepts_no_orders(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $lookup = $this->postJson(route('storefront.utility-bills.lookup', $vendor->vendor_code), ['biller' => 'ecg', 'phone' => '0551617309'])
            ->assertOk()->json();

        UtilityBillSettings::save(false, null);

        $html = $this->get('/store/'.$vendor->vendor_code)->assertOk()
            ->assertDontSee($this->tileLink($vendor), false)
            ->getContent();
        $this->assertMatchesRegularExpression('/x4-cat-tile is-closed".*?Utility Bills.*?Temporarily closed/s', $html);

        $this->get(route('storefront.utility-bills', ['vendor' => $vendor->vendor_code]))->assertStatus(503);
        $this->postJson(route('storefront.utility-bills.lookup', $vendor->vendor_code), ['biller' => 'ecg', 'phone' => '0551617309'])->assertStatus(422);
        $checkout = $this->postJson(route('storefront.utility-bills.checkout', $vendor->vendor_code), [
            'lookup_token' => $lookup['token'], 'selected_meter_id' => $lookup['meters'][0]['id'], 'amount' => '50',
        ]);
        $this->assertNotTrue($checkout->json('success'));
        $this->assertSame(0, UtilityBillOrder::count());
        $this->assertSame(0, Order::count());
    }

    public function test_other_categories_keep_the_normal_selection_flow(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        Product::create([
            'vendor_id' => $vendor->id, 'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data', 'service' => 'MTN']), 'price' => 5, 'is_active' => true,
        ]);

        $page = $this->get('/store/'.$vendor->vendor_code)->assertOk();

        $data = collect($page->viewData('categories'))->firstWhere('value', 'data');
        $this->assertSame(1, $data['serviceCount']);
        $this->assertArrayNotHasKey('is_utility_bills', $data);
        $mtn = collect($page->viewData('services'))->firstWhere('name', 'MTN');
        $this->assertSame('data', $mtn['category']);
        $this->assertSame(5.0, $mtn['packages'][0]['price']);
        $page->assertSee('@click="selectCategory(categories[0])"', false)->assertSee('1 available');
    }

    public function test_legacy_ecg_products_are_untouched_and_do_not_turn_the_tile_into_a_package_picker(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $legacy = Product::create([
            'vendor_id' => $vendor->id, 'name' => 'ECG Prepaid Token',
            'description' => json_encode(['category' => 'ecg', 'service' => 'ECG']), 'price' => 20, 'is_active' => true,
        ]);

        $page = $this->get('/store/'.$vendor->vendor_code)->assertOk()->assertSee($this->tileLink($vendor), false);

        // The legacy record and its payload are unchanged; the tile is still only a link.
        $this->assertSame('ECG Prepaid Token', collect($page->viewData('services'))->firstWhere('category', 'ecg')['packages'][0]['name']);
        $this->assertSame(1, $this->utilityCategory($page)['serviceCount']);   // the one enabled biller, not +1 legacy product
        $this->assertSame('ecg', json_decode($legacy->fresh()->description, true)['category']);
        $this->assertSame('20.00', (string) $legacy->fresh()->price);
    }
}
