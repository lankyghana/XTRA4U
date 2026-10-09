<?php

namespace Tests\Feature\UtilityBills;

use App\Http\Controllers\PlatformServiceController;
use App\Models\NetworkService;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * No NEW legacy ECG records can be created anywhere, while historical ECG records stay readable and
 * editable without silently changing category. The Utility Bills ECG biller is independent of all of it.
 */
class LegacyEcgRetirementTest extends UtilityBillTestCase
{
    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function asVendor(Vendor $vendor): void
    {
        Auth::guard('vendor')->setUser($vendor);
        Auth::shouldUse('web');
    }

    private function legacyEcgProduct(Vendor $vendor, array $extra = []): Product
    {
        return Product::create($extra + [
            'vendor_id' => $vendor->id, 'name' => 'ECG Prepaid Token', 'price' => 20, 'is_active' => true,
            'description' => json_encode(['category' => 'ecg', 'service' => 'ECG']),
        ]);
    }

    // ---- Admin > Networks ----------------------------------------------------

    public function test_admin_cannot_create_a_new_legacy_ecg_network_service(): void
    {
        $this->admin();

        $this->post(route('admin.network-services.store'), ['name' => 'ECG Power', 'category' => 'ecg'])->assertSessionHasErrors('category');
        $this->post(route('admin.network-services.store'), ['name' => 'ECG Power', 'category' => 'ecg', 'service_type' => 'general', 'base_price' => 0])->assertSessionHasErrors('category');

        $this->assertSame(0, NetworkService::count());
    }

    public function test_the_create_form_no_longer_offers_ecg_but_keeps_the_other_categories(): void
    {
        $this->admin();

        $html = $this->get(route('admin.network-services.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="ecg"', $html);
        foreach (['data', 'shop', 'results', 'afa'] as $category) {
            $this->assertStringContainsString('value="'.$category.'"', $html);
        }
    }

    public function test_other_network_categories_are_created_and_edited_as_before(): void
    {
        $this->admin();

        foreach (['data', 'shop', 'results', 'afa'] as $category) {
            $this->post(route('admin.network-services.store'), ['name' => 'Svc '.$category, 'category' => $category])->assertSessionHasNoErrors();
        }
        $this->assertSame(4, NetworkService::count());

        $svc = NetworkService::where('category', 'data')->first();
        $this->put(route('admin.network-services.update', $svc), ['name' => 'Svc data renamed', 'category' => 'shop'])->assertSessionHasNoErrors();
        $this->assertSame('shop', $svc->fresh()->category);
    }

    public function test_an_existing_legacy_ecg_network_service_loads_and_saves_without_changing_category(): void
    {
        $this->admin();
        $legacy = NetworkService::create(['name' => 'ECG', 'slug' => 'ecg', 'category' => 'ecg', 'service_type' => 'general', 'base_price' => 0, 'is_active' => true]);

        // The edit form shows the legacy category selected, so submitting it cannot silently change it.
        $this->get(route('admin.network-services.edit', $legacy))->assertOk()
            ->assertSee('value="ecg"', false)->assertSee('ECG (legacy)')->assertSee('selected');

        $this->put(route('admin.network-services.update', $legacy), ['name' => 'ECG (renamed)', 'category' => 'ecg'])->assertSessionHasNoErrors();
        $this->assertSame('ecg', $legacy->fresh()->category);
        $this->assertSame('ECG (renamed)', $legacy->fresh()->name);

        // ...and it may be moved OUT of the retired category.
        $this->put(route('admin.network-services.update', $legacy), ['name' => 'ECG (renamed)', 'category' => 'data'])->assertSessionHasNoErrors();
        $this->assertSame('data', $legacy->fresh()->category);
    }

    public function test_an_existing_service_cannot_be_moved_into_the_retired_category(): void
    {
        $this->admin();
        $svc = NetworkService::create(['name' => 'MTN', 'slug' => 'mtn', 'category' => 'data', 'service_type' => 'general', 'base_price' => 0, 'is_active' => true]);

        $this->put(route('admin.network-services.update', $svc), ['name' => 'MTN', 'category' => 'ecg'])->assertSessionHasErrors('category');
        $this->assertSame('data', $svc->fresh()->category);
    }

    // ---- Vendor products / reseller listings --------------------------------

    public function test_vendors_cannot_create_new_ecg_products_but_legacy_ones_stay_editable(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $legacy = $this->legacyEcgProduct($vendor);
        $this->asVendor($vendor);

        $this->post(route('vendor.products.store'), ['name' => 'New ECG', 'price' => 5, 'category' => 'ecg'])->assertSessionHasErrors('category');
        $this->assertSame(1, Product::count());

        $this->get(route('vendor.products.edit', $legacy))->assertOk()->assertSee('ECG (legacy product)');
        $this->put(route('vendor.products.update', $legacy), ['name' => 'ECG v2', 'price' => 21, 'category' => 'ecg'])->assertSessionHasNoErrors();
        $this->assertSame('ecg', json_decode($legacy->fresh()->description, true)['category']);
    }

    public function test_no_new_reseller_listing_of_a_legacy_ecg_product(): void
    {
        $parent = Vendor::factory()->create(['is_approved' => true, 'is_afa_affiliate' => true]);
        $reseller = Vendor::factory()->create(['is_approved' => true, 'is_afa_affiliate' => true, 'affiliate_vendor_id' => $parent->id]);
        $ecg = $this->legacyEcgProduct($parent, ['is_resellable' => true]);
        $data = Product::create(['vendor_id' => $parent->id, 'name' => 'MTN 1GB', 'price' => 5, 'is_active' => true, 'is_resellable' => true,
            'description' => json_encode(['category' => 'data'])]);
        $this->asVendor($reseller);

        // The marketplace no longer offers the legacy ECG product, but still offers the rest.
        $this->get(route('vendor.marketplace.index'))->assertOk()->assertSee('MTN 1GB')->assertDontSee('ECG Prepaid Token');

        // A direct attempt to list it is refused with the legacy message (before any affiliate/pricing logic).
        $this->post(route('vendor.marketplace.add'), ['product_id' => $ecg->id, 'markup_price' => 1])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'legacy ECG product'));
        $this->assertSame(0, ResellerProduct::count());

        // Non-ECG products pass through the original logic unchanged (here: it is not refused as legacy ECG).
        $res = $this->post(route('vendor.marketplace.add'), ['product_id' => $data->id, 'markup_price' => 1]);
        $this->assertStringNotContainsString('legacy ECG product', (string) session('error'));
    }

    public function test_an_existing_reseller_listing_of_a_legacy_product_is_not_removed(): void
    {
        $parent = Vendor::factory()->create(['is_approved' => true]);
        $reseller = Vendor::factory()->create(['is_approved' => true]);
        $ecg = $this->legacyEcgProduct($parent, ['is_resellable' => true]);
        $listing = ResellerProduct::create(['product_id' => $ecg->id, 'reseller_vendor_id' => $reseller->id, 'owner_vendor_id' => $parent->id, 'base_price' => 20, 'markup_price' => 2]);

        $this->assertNotNull($listing->fresh());
        $this->assertSame(1, ResellerProduct::count());
    }

    // ---- Other creation paths (search results enforced as tests) -------------

    public function test_no_code_path_seeds_or_hardcodes_a_new_ecg_category_record(): void
    {
        // Seeders / factories must not create ecg-category products or network services.
        foreach (File::allFiles(database_path('seeders')) as $file) {
            $this->assertStringNotContainsStringIgnoringCase("'ecg'", File::get($file->getPathname()), $file->getFilename());
        }
        foreach (File::allFiles(database_path('factories')) as $file) {
            $this->assertStringNotContainsStringIgnoringCase("'ecg'", File::get($file->getPathname()), $file->getFilename());
        }
    }

    // ---- Utility Bills ECG is independent -------------------------------------

    public function test_the_utility_bills_ecg_biller_uses_kingflexy_and_never_the_legacy_models(): void
    {
        $this->openService(['ecg']);
        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['account_name' => null, 'meters' => [['name' => 'KWAME MENSAH', 'meterNumber' => '3701234567', 'outstanding' => 10]]]]),
        ]);

        $this->postJson(route('utility-bills.lookup'), ['biller' => 'ecg', 'phone' => '0551617309'])->assertOk()->assertJsonCount(1, 'meters');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/utilities/lookup') && str_contains($r->url(), 'biller=ecg'));
        $this->assertSame(0, NetworkService::count());
        $this->assertSame(0, Product::count());
    }

    public function test_the_ecg_biller_is_managed_only_in_utility_bills_settings(): void
    {
        $this->admin();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        $this->get(route('admin.utility-bills.settings'))->assertOk()->assertSee('ECG Prepaid')->assertSee('billers[ecg][is_enabled]', false);
        $this->put(route('admin.utility-bills.settings.update'), ['enabled' => 1, 'billers' => ['ecg' => ['is_enabled' => 1, 'commission_type' => 'percentage', 'commission_value' => '0.5']]])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('utility_biller_configs', ['biller_key' => 'ecg', 'is_enabled' => 1]);
        $this->assertSame(0, NetworkService::count());
    }

    public function test_storefronts_still_display_utility_bills(): void
    {
        $this->openService(['ecg']);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $this->get('/store/'.$vendor->vendor_code)->assertOk()->assertSee('Utility Bills')->assertSee('utility_bills_service');
    }

    // ---- Routes / dead code ---------------------------------------------------

    public function test_services_ecg_is_a_redirect_only(): void
    {
        $this->get('/services/ecg')->assertStatus(301)->assertRedirect(route('services.utility-bills'));

        $route = Route::getRoutes()->getByName('services.ecg');
        $this->assertInstanceOf(\Closure::class, $route->getAction('uses'));          // a redirect closure, no controller/view
    }

    public function test_there_is_no_product_based_utility_bills_or_ecg_page_left(): void
    {
        $this->assertSame(\App\Http\Controllers\UtilityBillController::class.'@direct', Route::getRoutes()->getByName('services.utility-bills')->getActionName());

        foreach (['utilityBills', 'ecg'] as $method) {
            $this->assertFalse(method_exists(PlatformServiceController::class, $method), $method);
        }

        $this->assertFalse(view()->exists('platform-services.utility-bills'));
        $this->assertFalse(view()->exists('platform-services.ecg'));

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('PlatformServiceController@utilityBills', $route->getActionName());
            $this->assertStringNotContainsString('PlatformServiceController@ecg', $route->getActionName());
        }
    }

    public function test_nothing_references_the_deleted_views(): void
    {
        $needles = ['platform-services.utility-bills', 'platform-services.ecg', 'platform-services/utility-bills', 'platform-services/ecg'];

        foreach ([app_path(), resource_path(), base_path('routes'), config_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $content = File::get($file->getPathname());
                foreach ($needles as $needle) {
                    $this->assertStringNotContainsString($needle, $content, $file->getPathname());
                }
            }
        }
    }
}
