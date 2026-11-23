<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Cms\CmsNavigationItem;
use App\Services\DeveloperApi\Docs\PortalDocs;
use App\Support\Cms\CmsRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies the ACTUAL rendered HTML of the pages that carry the Developer API entry points: which region of the page
 * each link sits in, how many there are, how the CMS-managed menus interact with the built-in links, and that every
 * internal link on the new pages resolves.
 */
class DeveloperWebsiteRenderingTest extends PortalTestCase
{
    /** Cut one region out of rendered HTML between two markers. */
    private function region(string $html, string $startMarker, string $endMarker): string
    {
        $start = strpos($html, $startMarker);
        $this->assertNotFalse($start, "marker not found: {$startMarker}");
        $end = strpos($html, $endMarker, $start + 1);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    public function test_the_homepage_has_exactly_one_developer_api_link_in_each_of_desktop_header_mobile_menu_and_footer(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $needle = 'href="'.route('developers.landing').'"';

        $desktop = $this->region($html, 'aria-label="Primary"', 'id="x4-mobile-nav"');
        $mobile = $this->region($html, 'id="x4-mobile-nav"', '</header>');
        $footer = $this->region($html, 'id="site-footer"', '</footer>');

        $this->assertSame(1, substr_count($desktop, $needle), 'desktop header');
        $this->assertSame(1, substr_count($mobile, $needle), 'mobile menu');
        $this->assertSame(1, substr_count($footer, $needle), 'footer');
        $this->assertSame(3, substr_count($html, $needle), 'nowhere else on the page');
    }

    public function test_the_shipped_cms_navigation_neither_contains_nor_removes_the_developer_link(): void
    {
        foreach (CmsRegistry::navigationDefaults() as $location => $rows) {
            foreach ($rows as [$label, $url]) {
                $this->assertStringNotContainsString('develop', strtolower($label.$url), "{$location} default");
            }
        }

        // With the migration-seeded CMS rows, and again after an admin empties every menu, the code-built links remain.
        foreach ([false, true] as $emptied) {
            if ($emptied) {
                CmsNavigationItem::query()->delete();
                Cache::flush();
            }

            $html = $this->get('/')->assertOk()->getContent();
            $this->assertSame(3, substr_count($html, 'href="'.route('developers.landing').'"'), $emptied ? 'CMS menus emptied' : 'seeded CMS menus');
        }
    }

    public function test_a_cms_menu_entry_for_the_same_page_adds_a_second_link_and_does_not_replace_the_built_in_one(): void
    {
        // Documented behaviour, not a defect: an admin who ALSO adds /developers to a CMS menu gets both entries.
        CmsNavigationItem::create(['location' => 'header', 'label' => 'Developers', 'url' => '/developers', 'sort_order' => 99, 'is_visible' => true]);
        Cache::flush();

        $html = $this->get('/')->assertOk()->getContent();
        $desktop = $this->region($html, 'aria-label="Primary"', 'id="x4-mobile-nav"');

        $this->assertSame(2, substr_count($desktop, '/developers"'), 'CMS entry + built-in entry');
        $this->assertMatchesRegularExpression('/>\s*Developers\s*<\/a>/', $desktop, 'the CMS-managed entry');
        $this->assertMatchesRegularExpression('/>\s*Developer API\s*<\/a>/', $desktop, 'the built-in entry');
    }

    public function test_the_footer_link_is_absent_on_a_vendors_own_storefront_and_present_on_other_public_pages(): void
    {
        $vendor = $this->approvedVendor();
        $link = 'href="'.route('developers.landing').'"';

        $this->get(route('storefront.vendor', ['vendor' => $vendor->vendor_code]))->assertOk()->assertDontSee($link, false);

        foreach (['/about', '/privacy', '/terms'] as $path) {
            $this->get($path)->assertOk()->assertSee($link, false);
        }
    }

    public function test_the_vendor_sidebar_shows_the_link_once_per_menu_in_the_grow_group_on_vendor_pages(): void
    {
        $vendor = $this->approvedVendor();
        $needle = 'href="'.route('developers.dashboard').'"';

        foreach ([route('vendor.dashboard'), route('vendor.support.index'), route('vendor.settings.index')] as $url) {
            $html = $this->actingAsVendor($vendor)->get($url)->assertOk()->getContent();
            $this->assertSame(2, substr_count($html, $needle), "{$url}: one in the mobile sidebar, one in the desktop sidebar");
        }

        // Inside the Grow group, not stranded in the "More" safety-net group.
        $html = $this->actingAsVendor($vendor)->get(route('vendor.dashboard'))->getContent();
        $this->assertStringContainsString($needle, $this->region($html, '>Grow<', '>Finance<'));
        $this->assertStringNotContainsString('>More<', $html);
    }

    public function test_a_link_missing_from_every_sidebar_group_still_renders_under_more(): void
    {
        // Guards the bug that hid the Developer API entry: the safety net must put an ungrouped link somewhere visible.
        $source = (string) file_get_contents(resource_path('views/components/vendor-layout.blade.php'));
        preg_match_all("/'key' => '([a-z-]+)'/", $source, $keys);
        preg_match('/\\$navGroups = \\[(.*?)\\n    \\];/s', $source, $groups);

        $grouped = [];
        preg_match_all("/'([a-z-]+)'/", preg_replace("/'[A-Za-z]+' =>/", '', $groups[1]), $g);
        foreach ($g[1] as $key) {
            $grouped[$key] = true;
        }

        $this->assertSame([], array_values(array_diff(array_unique($keys[1]), array_keys($grouped))), 'every sidebar link belongs to a group');
        $this->assertStringContainsString("\$navGroups['More']", $source, 'and the fallback exists for the next one that does not');
    }

    public function test_every_internal_link_on_the_new_and_changed_pages_resolves(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        $this->actingAsVendor($vendor);

        $pages = ['/developers', '/developers/dashboard', '/developers/docs', "/developers/applications/{$app->id}"];
        foreach (array_keys(app(PortalDocs::class)->pages()) as $slug) {
            $pages[] = "/developers/docs/{$slug}";
        }

        $checked = [];
        foreach ($pages as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $html, $m);

            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                $path = str_starts_with($href, 'http://localhost') ? substr($href, strlen('http://localhost')) : $href;

                if ($path === '' || $path[0] === '#' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
                    continue;
                }
                $path = explode('#', $path)[0];
                if ($path === '' || isset($checked[$path])) {
                    continue;
                }
                $checked[$path] = true;

                $status = $this->get($path)->status();
                $this->assertLessThan(400, $status, "{$page} links to {$path} which returns {$status}");
            }
        }

        $this->assertGreaterThan(15, count($checked), 'the crawl really followed the links');
    }

    public function test_in_page_contents_links_point_at_real_headings(): void
    {
        $this->actingAsVendor($this->approvedVendor());
        $total = 0;

        foreach (array_keys(app(PortalDocs::class)->pages()) as $slug) {
            $html = $this->get("/developers/docs/{$slug}")->assertOk()->getContent();
            preg_match_all('/<a href="#([^"]+)" class="text-gray-700 hover:text-brand-violet hover:underline">/', $html, $links);

            foreach ($links[1] as $anchor) {
                $total++;
                $this->assertStringContainsString('id="'.$anchor.'"', $html, "{$slug}: #{$anchor} has no target");
            }
        }

        $this->assertGreaterThan(30, $total);
    }
}
