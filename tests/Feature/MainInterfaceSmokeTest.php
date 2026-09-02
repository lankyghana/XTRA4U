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
        // The featured heading is two-tone, so the phrase is split across a
        // <span> in the markup; assert on both halves rather than the
        // concatenated string.
        $this->get('/')
            ->assertOk()
            ->assertSee('Results Checker PINs,')
            ->assertSee('Delivered Instantly')
            ->assertSee('Retrieve My PIN')
            ->assertSee(route('result-checkers.entry'));
    }

    public function test_entry_forwards_to_the_official_platform_page(): void
    {
        // The legacy /results-checkers link (bookmarks, external links) now
        // forwards to the official, admin-configured platform page rather
        // than a specific vendor's store — that page resolves its own
        // vendor (App\Support\PlatformServiceVendor) and degrades
        // gracefully on its own, so this redirect no longer depends on
        // MainStore or on any vendor existing in the database.
        $this->get(route('result-checkers.entry'))
            ->assertRedirect(route('services.result-checkers'));
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
