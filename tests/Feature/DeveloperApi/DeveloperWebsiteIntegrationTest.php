<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\User;
use App\Services\DeveloperApi\Docs\PortalDocs;
use App\Services\DeveloperApi\Webhooks\WebhookSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The Developer API must be discoverable and documented: a public information page linked from the public site, a
 * vendor-dashboard entry, and API documentation inside the portal. All of it rides on the EXISTING vendor guard and
 * live approval check; nothing here adds an account, a login or a second approval.
 */
class DeveloperWebsiteIntegrationTest extends PortalTestCase
{
    // ── public information page and public site navigation ─────────────────

    public function test_the_public_page_returns_200_and_covers_what_a_prospective_vendor_needs(): void
    {
        $this->get('/developers')->assertOk()
            ->assertSee('approved XTRA4U vendors')
            ->assertSee('How to get access')
            ->assertSee('Sandbox: build and test safely')
            ->assertSee('xt_test_', false)
            ->assertSee('Webhooks')
            ->assertSee('HMAC-SHA256')
            ->assertSee('failed deliveries are retried automatically')
            ->assertSee('API documentation')
            ->assertSee('sign in')
            ->assertDontSee('published separately');
    }

    public function test_the_public_homepage_links_to_the_developer_api_in_the_header_and_footer(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'href="'.route('developers.landing').'"'), 'desktop header, mobile header and footer');
        $this->assertStringContainsString('Developer API', $html);
    }

    public function test_other_public_pages_carry_the_footer_link_too(): void
    {
        $this->get('/about')->assertOk()->assertSee('href="'.route('developers.landing').'"', false);
    }

    public function test_the_landing_page_offers_the_documentation_only_to_approved_vendors(): void
    {
        $docs = route('developers.docs.index');

        $this->get('/developers')->assertOk()->assertDontSee('href="'.$docs.'"', false);
        $this->actingAsVendor($this->unapprovedVendor())->get('/developers')->assertOk()->assertDontSee('href="'.$docs.'"', false);
        $this->actingAsVendor($this->approvedVendor())->get('/developers')->assertOk()->assertSee('href="'.$docs.'"', false);
    }

    // ── vendor dashboard navigation ─────────────────────────────────────────

    public function test_the_vendor_dashboard_shows_a_working_developer_api_link(): void
    {
        $vendor = $this->approvedVendor();

        $this->actingAsVendor($vendor)->get(route('vendor.dashboard'))->assertOk()
            ->assertSee('Developer API')
            ->assertSee('href="'.route('developers.dashboard').'"', false);

        $this->actingAsVendor($vendor)->get(route('developers.dashboard'))->assertOk();
    }

    public function test_the_portal_navigation_links_to_the_documentation_for_approved_vendors_only(): void
    {
        $docs = 'href="'.route('developers.docs.index').'"';

        $this->actingAsVendor($this->approvedVendor())->get(route('developers.dashboard'))->assertOk()->assertSee($docs, false);
    }

    public function test_the_public_navigation_does_not_link_to_the_documentation(): void
    {
        $this->get(route('developers.landing'))->assertOk()
            ->assertDontSee('href="'.route('developers.docs.index').'"', false)
            ->assertSee('href="'.route('storefront.index').'"', false);
    }

    // ── access to the documentation: same gate as the rest of the portal ────

    public function test_guests_are_sent_to_the_existing_vendor_login(): void
    {
        $this->get('/developers/docs')->assertRedirect(route('vendor.login.form'));
        $this->get('/developers/docs/quickstart')->assertRedirect(route('vendor.login.form'));
    }

    public function test_unapproved_and_suspended_vendors_cannot_read_the_documentation(): void
    {
        $vendor = $this->unapprovedVendor();

        $this->actingAsVendor($vendor)->get('/developers/docs')->assertForbidden()->assertSee('Vendor approval required');
        $this->actingAsVendor($vendor)->get('/developers/docs/quickstart')->assertForbidden()->assertDontSee('Authorization: Bearer');
    }

    public function test_a_vendor_suspended_mid_session_loses_the_documentation_on_the_next_request(): void
    {
        $vendor = $this->approvedVendor();
        $this->actingAsVendor($vendor)->get('/developers/docs')->assertOk();

        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $this->actingAsVendor($vendor->fresh())->get('/developers/docs')->assertForbidden();
    }

    public function test_customer_and_admin_sessions_do_not_open_the_documentation(): void
    {
        $this->actingAs(User::factory()->create())->get('/developers/docs')->assertRedirect(route('vendor.login.form'));
        $this->asAdmin()->get('/developers/docs')->assertRedirect(route('vendor.login.form'));
    }

    public function test_the_documentation_routes_sit_behind_the_portal_gate(): void
    {
        foreach (['developers.docs.index', 'developers.docs.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('developer.access', $route->gatherMiddleware(), $name);
        }
    }

    // ── the documentation itself ────────────────────────────────────────────

    public function test_every_documentation_page_renders_for_an_approved_vendor(): void
    {
        $this->actingAsVendor($this->approvedVendor());
        $pages = app(PortalDocs::class)->pages();

        $this->assertArrayHasKey('quickstart', $pages);
        $this->assertGreaterThanOrEqual(13, count($pages));

        $index = $this->get('/developers/docs')->assertOk();
        foreach ($pages as $slug => $page) {
            $index->assertSee($page['title']);
            $res = $this->get("/developers/docs/{$slug}")->assertOk()->assertSee($page['title']);
            $this->assertSame(1, preg_match('/<div class="docs-prose">(.*?)<\/div>\s*<\/article>/s', $res->getContent(), $body), $slug);
            $this->assertStringNotContainsString('<script', $body[1], "{$slug}: rendered documentation never carries a script tag");
            $this->assertStringNotContainsString('onerror=', $body[1], $slug);
        }
    }

    public function test_unknown_and_malicious_page_names_are_not_found(): void
    {
        $this->actingAsVendor($this->approvedVendor());

        foreach (['nope', 'overview.md', '..%2F..%2F.env', '%2e%2e%2f.env', 'README', 'a', str_repeat('x', 80)] as $slug) {
            $this->get('/developers/docs/'.$slug)->assertNotFound();
        }
    }

    public function test_the_quickstart_carries_headers_examples_and_tables_rendered_as_html(): void
    {
        $this->actingAsVendor($this->approvedVendor());

        $html = $this->get('/developers/docs/quickstart')->assertOk()->getContent();

        // HTTP methods are wrapped in a badge span, so compare the visible text.
        $text = html_entity_decode(strip_tags($html));
        foreach (['Base URL and headers', 'Idempotency-Key', 'Authorization', 'POST /api/v1/quotes', 'POST /api/v1/orders', '/payments', '/status',
            'X-XTRA4U-Signature', 'RATE_LIMITED', 'VALIDATION_FAILED', 'sandbox/orders'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
        $this->assertStringContainsString('<span class="docs-method docs-method-post">POST</span>', $html, 'endpoints carry a method badge');
        $this->assertStringContainsString('<pre>', $html, 'fenced examples become code blocks');
        $this->assertStringContainsString('<table>', $html, 'GFM tables are rendered');
        $this->assertStringContainsString('id="content-base-url-and-headers"', $html, 'headings are linkable');
        $this->assertStringNotContainsString('| Header |', $html, 'no raw markdown table is left behind');
    }

    public function test_the_documentation_describes_exactly_the_registered_api_endpoints(): void
    {
        $overview = (string) file_get_contents(resource_path('developer-docs/overview.md'));
        $registered = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $registered[] = $method.' `/'.substr($route->uri(), strlen('api/v1/')).'`';
            }
        }

        $this->assertNotEmpty($registered);
        foreach ($registered as $endpoint) {
            [$method, $path] = explode(' ', $endpoint, 2);
            $this->assertMatchesRegularExpression('/\|\s*'.$method.'\s*\|\s*'.preg_quote($path, '/').'\s*\|/', $overview, "{$endpoint} is registered but not documented");
        }

        // ...and the reverse: nothing documented that does not exist.
        preg_match_all('/^\|\s*(GET|POST|PUT|PATCH|DELETE)\s*\|\s*(`[^`]+`)\s*\|/m', $overview, $m, PREG_SET_ORDER);
        $documented = array_map(fn ($row) => $row[1].' '.$row[2], $m);
        $this->assertEqualsCanonicalizing($registered, $documented);
    }

    public function test_the_quickstart_signature_recipe_verifies_a_real_webhook_signature(): void
    {
        // The recipe from the quickstart, run against the platform's own signer.
        $secret = WebhookSigner::generateSecret();
        $raw = '{"id":"evt_x","type":"order.completed","api_version":"v1","created":1,"data":{"order":{}}}';
        $header = WebhookSigner::header($secret, time(), $raw);

        $verify = function (string $raw, string $header, string $secret): bool {
            parse_str(str_replace(',', '&', $header), $parts);
            $expected = hash_hmac('sha256', ($parts['t'] ?? '').'.'.$raw, $secret);
            $fresh = abs(time() - (int) ($parts['t'] ?? 0)) <= 300;

            return $fresh && hash_equals($expected, $parts['v1'] ?? '');
        };

        $this->assertTrue($verify($raw, $header, $secret));
        $this->assertFalse($verify($raw.' ', $header, $secret), 'a changed body fails');
        $this->assertFalse($verify($raw, WebhookSigner::header($secret, time() - 3600, $raw), $secret), 'a stale timestamp fails');
        $this->assertStringContainsString('hash_hmac', (string) file_get_contents(resource_path('developer-portal-docs/quickstart.md')));
    }

    public function test_every_portal_doc_page_comes_from_an_allowlisted_directory(): void
    {
        $dirs = [realpath(resource_path('developer-docs')), realpath(resource_path('developer-portal-docs'))];

        foreach (app(PortalDocs::class)->pages() as $slug => $page) {
            $real = realpath($page['path']);
            $this->assertNotFalse($real, $slug);
            $this->assertTrue(collect($dirs)->contains(fn ($d) => str_starts_with($real, $d.DIRECTORY_SEPARATOR)), $slug);
            $this->assertTrue(app(PortalDocs::class)->has($slug), $slug);
        }
        $this->assertFalse(app(PortalDocs::class)->has('../../.env'));
        $this->assertNull(app(PortalDocs::class)->page('not-a-page'));
    }

    // ── nothing underneath changed ──────────────────────────────────────────

    public function test_the_api_and_the_separate_login_situation_are_unchanged(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->getJson('/api/v1/products')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_API_KEY');

        foreach (['/developers/login', '/developers/register'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }
}
