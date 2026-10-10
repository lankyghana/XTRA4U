<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\UtilityBillerConfig;
use App\Models\Vendor;
use App\Services\UtilityBills\UtilityBillSettings;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use App\Support\SupersededCategories;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Architecture cleanup: Utility Bills is ONE top-level service. ECG is a biller inside it,
 * with no vendor assignment, no separate admin service toggle, and no old "ECG platform service".
 * Historical/legacy ECG records stay intact.
 */
class UtilityBillsArchitectureCleanupTest extends UtilityBillTestCase
{
    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function fakeBillers(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
    }

    private function legacyEcgProduct(Vendor $vendor): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'ECG Prepaid Token',
            'description' => json_encode(['category' => 'ecg', 'service' => 'ECG']),
            'price' => 20,
            'is_active' => true,
        ]);
    }

    // ---- Admin UI ---------------------------------------------------------

    public function test_service_availability_shows_utility_bills_not_ecg_as_a_service(): void
    {
        $this->admin();
        $this->openService(['ecg']);

        $page = $this->get(route('admin.settings.service-availability'))->assertOk();

        $page->assertSee('Utility Bills')
            ->assertSee('Pay electricity, water and TV bills.')
            ->assertSee('Managed in')
            ->assertSee(route('admin.utility-bills.settings'), false)
            ->assertDontSee('ECG (legacy vendor products)')
            ->assertDontSee('Pauses sales of ECG products')
            ->assertDontSee('Pay electricity tokens and prepaid bills')
            ->assertDontSee('name="open[ecg]"', false);

        // The generic services are still toggles.
        foreach (['data', 'shop', 'results', 'afa'] as $category) {
            $page->assertSee('name="open['.$category.']"', false);
        }
    }

    public function test_service_availability_reflects_the_utility_bills_switch_read_only(): void
    {
        $this->admin();

        UtilityBillSettings::save(true, null);
        $this->get(route('admin.settings.service-availability'))->assertOk()->assertSee('Open');

        UtilityBillSettings::save(false, null);
        $html = $this->get(route('admin.settings.service-availability'))->getContent();
        $this->assertMatchesRegularExpression('/Utility Bills.*?Closed/s', $html);
        $this->assertStringNotContainsString('name="open[utility', $html);
    }

    public function test_platform_service_vendors_page_has_no_ecg_assignment(): void
    {
        $this->admin();
        Vendor::factory()->create(['is_approved' => true]);

        $page = $this->get(route('admin.settings.platform-service-vendors'))->assertOk();

        $page->assertDontSee('name="vendor[ecg]"', false)
            ->assertDontSee('Pay electricity tokens and prepaid bills')
            ->assertSee('needs no vendor')
            ->assertSee(route('admin.utility-bills.settings'), false);
        // The other services keep their assignment dropdowns.
        foreach (['data', 'shop', 'results', 'afa'] as $category) {
            $page->assertSee('name="vendor['.$category.']"', false);
        }
    }

    // ---- Saving the admin forms must not touch legacy ECG values ----------

    public function test_saving_service_availability_leaves_the_legacy_ecg_flag_alone(): void
    {
        $this->admin();
        Setting::set('service_open.ecg', '0', ServiceAvailability::GROUP);   // an old deployment had it closed

        $this->put(route('admin.settings.service-availability.update'), ['open' => ['data' => '1', 'shop' => '1'], 'message' => ''])
            ->assertRedirect();

        $this->assertSame('0', Setting::get('service_open.ecg'));            // NOT rewritten
        $this->assertTrue(ServiceAvailability::isOpen('data'));
        $this->assertFalse(ServiceAvailability::isOpen('results'));          // unchecked => closed, as before
        $this->assertSame(['data', 'shop', 'results', 'afa'], ServiceAvailability::categories());
    }

    public function test_saving_platform_service_vendors_leaves_the_legacy_ecg_assignment_and_others_unchanged(): void
    {
        $this->admin();
        $legacy = Vendor::factory()->create(['is_approved' => true]);
        $dataVendor = Vendor::factory()->create(['is_approved' => true]);
        $shopVendor = Vendor::factory()->create(['is_approved' => true]);
        PlatformServiceVendor::setVendorFor('ecg', $legacy->id);
        PlatformServiceVendor::setVendorFor('shop', $shopVendor->id);

        $this->put(route('admin.settings.platform-service-vendors.update'), ['vendor' => ['data' => $dataVendor->id, 'shop' => $shopVendor->id]])
            ->assertRedirect();

        $this->assertSame($legacy->id, PlatformServiceVendor::vendorIdFor('ecg'));       // historical value kept
        $this->assertSame($dataVendor->id, PlatformServiceVendor::vendorIdFor('data'));
        $this->assertSame($shopVendor->id, PlatformServiceVendor::vendorIdFor('shop'));
        $this->assertNotContains('ecg', PlatformServiceVendor::categories());
        $this->assertArrayNotHasKey('ecg', PlatformServiceVendor::assignments());
    }

    // ---- Utility Bills needs no assigned vendor ---------------------------

    public function test_utility_bills_works_with_no_platform_vendor_assignment_at_all(): void
    {
        $this->openService(['ecg']);
        $this->fakeBillers();
        foreach (PlatformServiceVendor::categories() as $category) {
            $this->assertNull(PlatformServiceVendor::vendorIdFor($category));
        }
        $this->assertNull(PlatformServiceVendor::vendorIdFor('ecg'));

        $this->get('/services/utility-bills')->assertOk()->assertSee('ECG Prepaid')
            ->assertDontSee('No approved vendor currently offers this service')
            ->assertDontSee('Not assigned');
    }

    public function test_every_approved_storefront_gets_utility_bills_with_no_per_vendor_setup(): void
    {
        $this->openService(['ecg']);
        $this->fakeBillers();
        $a = Vendor::factory()->create(['is_approved' => true]);
        $b = Vendor::factory()->create(['is_approved' => true]);
        $pending = Vendor::factory()->create(['is_approved' => false]);

        // None of them has products, assignments, settings or ECG ownership.
        foreach ([$a, $b] as $vendor) {
            $this->get('/store/'.$vendor->vendor_code)->assertOk()
                ->assertSee('href="'.route('storefront.utility-bills', ['vendor' => $vendor->vendor_code]).'"', false);
            $this->get('/store/'.$vendor->vendor_code.'/utility-bills')->assertOk()->assertSee('ECG Prepaid');
        }
        $this->get('/store/'.$pending->vendor_code.'/utility-bills')->assertNotFound();
    }

    public function test_storefront_category_is_worded_utility_bills_not_ecg(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $html = $this->get('/store/'.$vendor->vendor_code)->assertOk()->getContent();

        $this->assertStringContainsString('Utility Bills', $html);
        $this->assertStringNotContainsString('"label":"ECG"', $html);
        $this->assertStringNotContainsString('Pay electricity tokens and prepaid bills', $html);
    }

    // ---- ECG is only a biller ---------------------------------------------

    public function test_disabling_the_ecg_biller_disables_only_ecg(): void
    {
        $this->openService(['ecg', 'dstv']);
        $this->fakeBillers();
        UtilityBillerConfig::where('biller_key', 'ecg')->update(['is_enabled' => false]);
        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));

        $this->get('/services/utility-bills')->assertOk()->assertSee('DSTV')->assertDontSee('ECG Prepaid');
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'ecg', 'phone' => '0551617309'])->assertStatus(422);

        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['account_name' => 'KOFI BOATENG', 'account_number' => '7041234567', 'meters' => []]]),
        ]);
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])->assertOk();
    }

    public function test_disabling_utility_bills_globally_disables_every_biller(): void
    {
        $this->openService();
        $this->fakeBillers();
        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
        UtilityBillSettings::save(false, null);

        $this->get('/services/utility-bills')->assertStatus(503);
        foreach (['ecg' => ['phone' => '0551617309'], 'dstv' => ['account' => '7041234567'], 'ghana_water' => ['account' => '123456', 'phone' => '0551617309']] as $biller => $input) {
            $this->postJson(route('utility-bills.lookup'), ['biller' => $biller] + $input)->assertStatus(422);
        }
    }

    public function test_the_legacy_ecg_flag_never_affects_utility_bills(): void
    {
        $this->openService(['ecg']);
        $this->fakeBillers();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        Setting::set('service_open.ecg', '0', ServiceAvailability::GROUP);
        PlatformServiceVendor::setVendorFor('ecg', null);

        $this->get('/services/utility-bills')->assertOk()->assertSee('ECG Prepaid');
        $this->get('/store/'.$vendor->vendor_code)->assertOk()
            ->assertSee('href="'.route('storefront.utility-bills', ['vendor' => $vendor->vendor_code]).'"', false);
    }

    // ---- Backward compatibility -------------------------------------------

    public function test_the_legacy_ecg_url_still_redirects(): void
    {
        $this->get('/services/ecg')->assertStatus(301)->assertRedirect(route('services.utility-bills'));
        $this->get('/services/ecg?reference=ABC')->assertStatus(301)->assertRedirect(route('services.utility-bills', ['reference' => 'ABC']));
    }

    public function test_historical_ecg_products_and_orders_remain_intact_and_readable(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 9.8]);
        $product = $this->legacyEcgProduct($vendor);
        $order = Order::create([
            'recipient_phone_number' => '0551234567', 'mobile_money_number' => '0551234567', 'service_purchased' => 'ECG Prepaid Token',
            'amount_paid' => 10, 'vendor_id' => $vendor->id, 'vendor_service_id' => $product->id, 'status' => 'Completed', 'payment_status' => 'paid',
        ]);

        // The old product is still on the vendor's own storefront catalog.
        $this->get('/store/'.$vendor->vendor_code)->assertOk()->assertSee('ECG Prepaid Token');
        // Admin can still open the old order; the public phone lookup still lists it.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.orders.show', $order))->assertOk()->assertSee('ECG Prepaid Token');
        $this->postJson(route('order.status.check'), ['phone' => '0551234567'])->assertJson(['success' => true]);

        $this->assertSame('ecg', json_decode($product->fresh()->description, true)['category']);
        $this->assertSame('9.80', (string) $vendor->fresh()->wallet_balance);
    }

    public function test_legacy_ecg_product_checkout_is_unchanged_by_the_cleanup(): void
    {
        // service_open.ecg still gates the OLD vendor ECG product checkout (backward compatibility).
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $product = $this->legacyEcgProduct($vendor);
        Setting::set('service_open.ecg', '0', ServiceAvailability::GROUP);

        $this->postJson(route('checkout.process'), [
            'vendor_id' => $vendor->id, 'service_id' => 'x', 'package_id' => 'x', 'amount' => 20,
            'recipient_phone' => '0551234567', 'original_product_id' => $product->id,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['service']]);
    }

    // ---- Vendor product forms ----------------------------------------------

    private function asVendor(Vendor $vendor): void
    {
        Auth::guard('vendor')->setUser($vendor);
        Auth::shouldUse('web');
    }

    public function test_vendors_can_no_longer_create_new_products_in_the_superseded_ecg_category(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->asVendor($vendor);

        $this->get(route('vendor.products.create'))->assertOk()
            ->assertDontSee('value="ecg"', false)
            ->assertSee('value="data"', false);

        $this->post(route('vendor.products.store'), ['name' => 'My ECG token', 'price' => 10, 'category' => 'ecg'])->assertSessionHasErrors('category');
        $this->assertSame(0, Product::count());

        $this->post(route('vendor.products.store'), ['name' => 'MTN 1GB', 'price' => 5, 'category' => 'data'])->assertSessionHasNoErrors();
        $this->assertSame(1, Product::count());
    }

    public function test_an_existing_legacy_ecg_product_can_still_be_edited_but_not_moved_in_from_elsewhere(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $legacy = $this->legacyEcgProduct($vendor);
        $other = Product::create(['vendor_id' => $vendor->id, 'name' => 'MTN 1GB', 'description' => json_encode(['category' => 'data']), 'price' => 5, 'is_active' => true]);
        $this->asVendor($vendor);

        // The legacy product keeps its category on edit (and the edit form still offers it).
        $this->get(route('vendor.products.edit', $legacy))->assertOk()->assertSee('ECG (legacy product)');
        $this->put(route('vendor.products.update', $legacy), ['name' => 'ECG Token v2', 'price' => 22, 'category' => 'ecg'])->assertSessionHasNoErrors();
        $this->assertSame('ECG Token v2', $legacy->fresh()->name);

        // A non-ECG product cannot be switched into the retired category.
        $this->put(route('vendor.products.update', $other), ['name' => 'MTN 1GB', 'price' => 5, 'category' => 'ecg'])->assertSessionHasErrors('category');
    }

    // ---- Code-level guarantees ----------------------------------------------

    public function test_the_superseded_category_helper(): void
    {
        $this->assertTrue(SupersededCategories::is('ecg'));
        $this->assertFalse(SupersededCategories::is('data'));
        $this->assertSame(['data', 'shop'], SupersededCategories::without(['data', 'ecg', 'shop']));
        $this->assertSame('ecg', SupersededCategories::UTILITY_BILLS_KEY);
        $this->assertSame('Utility Bills', SupersededCategories::UTILITY_BILLS_LABEL);
    }
}
