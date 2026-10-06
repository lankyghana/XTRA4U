<?php

namespace Tests\Feature\Cms;

use App\Models\Admin;
use App\Models\Cms\CmsPage;
use App\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

class CmsAuthorizationTest extends CmsTestCase
{
    /** @return list<array{string,string}> every CMS admin route as [method, uri] with sample parameters */
    private function cmsRoutes(): array
    {
        $page = CmsPage::where('slug', 'privacy')->firstOrFail();
        $out = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with((string) $route->getName(), 'admin.cms.')) {
                continue;
            }

            $uri = $route->uri();
            $uri = preg_replace('#\{key\??\}#', 'home', $uri);
            $uri = preg_replace('#\{section\??\}#', 'hero', $uri);
            $uri = preg_replace('#\{(page|id|banner|faq|announcement|media|revision)\??\}#', (string) $page->id, $uri);

            $out[] = [$route->methods()[0], '/'.$uri];
        }

        return $out;
    }

    public function test_the_route_inventory_is_not_empty(): void
    {
        $this->assertGreaterThan(40, count($this->cmsRoutes()));
    }

    public function test_anonymous_visitors_are_turned_away_from_every_cms_route(): void
    {
        foreach ($this->cmsRoutes() as [$method, $uri]) {
            $response = $this->call($method, $uri);
            $this->assertContains($response->status(), [302, 401, 403], "$method $uri was reachable anonymously");
            if ($response->status() === 302) {
                $this->assertStringContainsString('/admin/login', (string) $response->headers->get('Location'), "$method $uri");
            }
        }
    }

    public function test_vendors_never_reach_cms_routes(): void
    {
        $this->actingAsVendor();

        foreach ($this->cmsRoutes() as [$method, $uri]) {
            $status = $this->call($method, $uri)->status();
            $this->assertContains($status, [302, 401, 403], "Vendor got $status on $method $uri");
        }
    }

    public function test_a_plain_user_is_denied(): void
    {
        $user = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($user);

        foreach ($this->cmsRoutes() as [$method, $uri]) {
            $this->assertSame(403, $this->call($method, $uri)->status(), "$method $uri");
        }
    }

    public function test_a_vendor_session_alongside_a_plain_user_still_has_no_access(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->actingAsVendor();

        $this->assertContains($this->get(route('admin.cms.dashboard'))->status(), [302, 403]);
        $this->assertContains($this->post(route('admin.cms.media.store'))->status(), [302, 403]);
    }

    public function test_an_admin_on_the_admin_guard_is_allowed(): void
    {
        $this->actingAsAdminGuard();

        $this->get(route('admin.cms.dashboard'))->assertOk();
        $this->get(route('admin.cms.pages.index'))->assertOk();
    }

    public function test_a_web_user_with_role_admin_is_allowed(): void
    {
        $this->actingAsRoleAdminUser();

        $this->get(route('admin.cms.dashboard'))->assertOk();
        $this->get(route('admin.cms.pages.create'))->assertOk();
    }

    public function test_every_mutating_cms_route_requires_csrf_protection(): void
    {
        // Tests bypass CSRF verification, so assert the middleware is actually attached instead.
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.cms.')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware, $route->getName().' is outside the web group');
            $this->assertContains('admin.only', $middleware, $route->getName());
            $this->assertContains('cms.admin', $middleware, $route->getName());
            $this->assertNotContains('api', $middleware);
        }
    }

    public function test_a_forged_cross_site_post_is_rejected_with_419(): void
    {
        $this->actingAsAdminGuard();

        // Laravel skips CSRF verification while the app runs as "testing"; switch it back on.
        $this->app['env'] = 'production';

        $this->post(route('admin.cms.media.store'), [])->assertStatus(419);
        $this->post(route('admin.cms.pages.store'), ['title' => 'x', 'slug' => 'x'])->assertStatus(419);
        $this->put(route('admin.cms.settings.update'), [])->assertStatus(419);
        $this->delete(route('admin.cms.pages.archive', CmsPage::where('slug', 'privacy')->first()))->assertStatus(419);
    }

    public function test_the_cms_gate_is_stricter_than_the_generic_admin_gate(): void
    {
        // An Admin model authenticated on the WRONG guard (web) is not an admin identity.
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'web');

        $this->assertNotSame(200, $this->get(route('admin.cms.dashboard'))->status());
    }
}
