<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MainInterfaceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_with_results_checker_section(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Results Checker PINs, Delivered Instantly')
            ->assertSee('Retrieve My PIN')
            ->assertSee(route('result-checkers.entry'));
    }

    public function test_entry_redirects_to_configured_flagship_store(): void
    {
        $vendor = Vendor::factory()->create();
        config(['storefront.main_store_code' => $vendor->vendor_code]);

        $this->get(route('result-checkers.entry'))
            ->assertRedirect(route('storefront.result-checkers', ['vendor' => $vendor->vendor_code]));
    }

    public function test_entry_falls_back_when_configured_code_is_absent(): void
    {
        // The production flagship code does not exist in this database — the
        // link must resolve to some existing vendor instead of 404ing.
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        config(['storefront.main_store_code' => 'BUNDJXW6SR']);

        $this->get(route('result-checkers.entry'))
            ->assertRedirect(route('storefront.result-checkers', ['vendor' => $vendor->vendor_code]));
    }

    public function test_entry_redirects_home_when_no_vendors_exist(): void
    {
        $this->get(route('result-checkers.entry'))
            ->assertRedirect(route('storefront.index'));
    }

    public function test_result_checkers_url_preselects_results_category(): void
    {
        $vendor = Vendor::factory()->create();

        $this->get(route('storefront.result-checkers', ['vendor' => $vendor->vendor_code]))
            ->assertOk()
            ->assertSee('initialCategory: "results"', false);
    }

    public function test_plain_store_url_has_no_preselected_category(): void
    {
        $vendor = Vendor::factory()->create();

        $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]))
            ->assertOk()
            ->assertSee('initialCategory: null', false);
    }
}
