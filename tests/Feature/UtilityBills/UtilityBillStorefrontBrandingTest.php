<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/** The storefront Utility Bills page and receipt carry the store's own identity, per vendor. */
class UtilityBillStorefrontBrandingTest extends UtilityBillTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->openService(['ecg']);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
    }

    public function test_each_storefront_page_shows_its_own_vendor_branding_and_back_link(): void
    {
        $a = Vendor::factory()->create(['is_approved' => true, 'name' => 'Ama Bundles', 'phone_number' => '0241112222']);
        $b = Vendor::factory()->create(['is_approved' => true, 'name' => 'Kofi Data Hub', 'phone_number' => '0553334444']);

        $this->get('/store/'.$a->vendor_code.'/utility-bills')->assertOk()
            ->assertSee('Pay bills with')->assertSee('Ama Bundles')->assertSee($a->vendor_code)
            ->assertSee('Verified Vendor')->assertSee('0241112222')
            ->assertSee('Back to Ama Bundles')
            ->assertSee('href="'.route('storefront.vendor', ['vendor' => $a->vendor_code]).'"', false)
            ->assertSee('wa.me/0241112222', false)
            ->assertDontSee('Kofi Data Hub');

        $this->get('/store/'.$b->vendor_code.'/utility-bills')->assertOk()
            ->assertSee('Kofi Data Hub')->assertSee($b->vendor_code)->assertSee('Back to Kofi Data Hub')
            ->assertDontSee('Ama Bundles');
    }

    public function test_direct_page_has_no_vendor_identity(): void
    {
        $this->get('/services/utility-bills')->assertOk()
            ->assertSee('Back to XTRA4U')->assertDontSee('Vendor Code')->assertDontSee('Verified Vendor');
    }

    public function test_dashboard_shortcut_only_for_the_store_owner(): void
    {
        $owner = Vendor::factory()->create(['is_approved' => true]);
        $other = Vendor::factory()->create(['is_approved' => true]);
        $url = '/store/'.$owner->vendor_code.'/utility-bills';

        $this->get($url)->assertOk()->assertDontSee('Vendor Dashboard');

        Auth::guard('vendor')->setUser($other);
        $this->get($url)->assertOk()->assertDontSee('Vendor Dashboard');

        Auth::guard('vendor')->setUser($owner);
        $this->get($url)->assertOk()->assertSee('Vendor Dashboard');
    }

    public function test_receipt_keeps_the_store_context_it_was_bought_in(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'name' => 'Ama Bundles']);
        $u = $this->makeOrder(['vendor' => $vendor]);

        $this->get($u->statusUrl())->assertOk()
            ->assertSee('Back to Ama Bundles')
            ->assertSee('href="'.route('storefront.utility-bills', ['vendor' => $vendor->vendor_code]).'"', false);
    }

    public function test_platform_receipt_and_unapproved_vendor_receipt_link_to_the_platform_page(): void
    {
        $platform = $this->makeOrder(['vendor' => null]);
        $this->get($platform->statusUrl())->assertOk()
            ->assertSee('Back to XTRA4U')
            ->assertSee('href="'.route('services.utility-bills').'"', false);

        $suspended = Vendor::factory()->create(['is_approved' => true, 'name' => 'Gone Store']);
        $u = $this->makeOrder(['vendor' => $suspended]);
        $suspended->forceFill(['is_approved' => false])->save();

        $this->get($u->statusUrl())->assertOk()->assertDontSee('Gone Store')->assertSee('Back to XTRA4U');
    }
}
