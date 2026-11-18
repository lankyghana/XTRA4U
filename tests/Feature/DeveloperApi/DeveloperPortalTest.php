<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperApiRequest;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperAuditEvent;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The Developer Portal is for authenticated, APPROVED vendors, signed in through the platform's
 * existing vendor registration/login. There is no developer account of any kind.
 */
class DeveloperPortalTest extends PortalTestCase
{
    // ── public information page ─────────────────────────────────────────────

    public function test_the_info_page_is_public_and_explains_the_api_without_private_data(): void
    {
        $vendor = $this->approvedVendor(['name' => 'Private-Vendor-Name', 'email' => 'private-vendor@example.test']);
        $this->product($vendor, 10, [], ['name' => 'Private-Product-Marker']);

        $res = $this->get('/developers')->assertOk();

        $res->assertSee('approved XTRA4U vendors')
            ->assertSee('How to get access')
            ->assertSee('Authentication')
            ->assertSee('Integration steps')
            ->assertSee('Available services')
            ->assertSee('/api/v1/products')
            ->assertSee('Become a vendor')
            ->assertSee('Vendor sign in')
            ->assertDontSee('Private-Vendor-Name')
            ->assertDontSee('private-vendor@example.test')
            ->assertDontSee('Private-Product-Marker');
        $this->assertStringNotContainsString($vendor->vendor_code, $res->getContent());
    }

    public function test_the_info_page_points_signed_in_vendors_at_the_right_next_step(): void
    {
        $this->actingAsVendor($this->approvedVendor())->get('/developers')->assertOk()->assertSee('Open your Developer Portal');
        $this->actingAsVendor($this->unapprovedVendor())->get('/developers')->assertOk()->assertSee('awaiting approval');
    }

    // ── there is no separate developer authentication ───────────────────────

    public function test_separate_developer_registration_login_and_access_routes_do_not_exist(): void
    {
        foreach (['/developers/register', '/developers/login'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
        $this->post('/developers/register', ['email' => 'x@example.test', 'password' => 'secret-pass-123'])->assertStatus(404);
        $this->post('/developers/login', ['email' => 'x@example.test', 'password' => 'secret-pass-123'])->assertStatus(404);
        $this->post('/developers/logout')->assertStatus(404);

        $this->actingAsVendor($this->approvedVendor())->get('/vendor/developer-access')->assertNotFound();
        $this->asAdmin()->get('/admin/developer-access')->assertNotFound();

        $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->all();
        foreach (['developers.register', 'developers.login', 'developers.logout', 'admin.developer-access.index', 'vendor.developer-access.index'] as $gone) {
            $this->assertNotContains($gone, $names);
        }
    }

    public function test_there_is_no_developer_guard_provider_model_or_table_dependency(): void
    {
        $this->assertArrayNotHasKey('developer', config('auth.guards'));
        $this->assertArrayNotHasKey('developers', config('auth.providers'));
        $this->assertFalse(class_exists(\App\Models\Developer\Developer::class));
    }

    // ── the existing vendor login opens the portal ──────────────────────────

    public function test_an_approved_vendor_signs_in_with_the_existing_vendor_login_and_reaches_the_portal(): void
    {
        $vendor = $this->approvedVendor(['email' => 'real-vendor@example.test']);

        $this->post('/vendor/login', ['email' => 'real-vendor@example.test', 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($vendor, 'vendor');
        $this->get('/developers/dashboard')->assertOk()->assertSee($vendor->name);
        $this->get('/developers/applications')->assertOk();
    }

    public function test_a_pending_vendor_cannot_sign_in_so_cannot_reach_the_portal_that_way(): void
    {
        $this->unapprovedVendor(['email' => 'pending@example.test']);

        $this->post('/vendor/login', ['email' => 'pending@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('vendor');
        $this->get('/developers/dashboard')->assertRedirect(route('vendor.login.form'));
    }

    public function test_guests_are_sent_to_the_existing_vendor_login(): void
    {
        foreach (['dashboard', 'applications', 'applications/create'] as $path) {
            $this->get("/developers/{$path}")->assertRedirect(route('vendor.login.form'));
        }
        $this->post('/developers/applications', ['name' => 'x'])->assertRedirect(route('vendor.login.form'));
    }

    public function test_customer_and_admin_sessions_do_not_open_the_portal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']), 'web')
            ->get('/developers/dashboard')->assertRedirect(route('vendor.login.form'));

        $this->asAdmin()->get('/developers/dashboard')->assertRedirect(route('vendor.login.form'));
    }

    public function test_a_vendor_portal_session_does_not_grant_admin_access(): void
    {
        $this->actingAsVendor($this->approvedVendor());

        $this->get('/admin')->assertRedirect();
        $this->get('/admin/vendors')->assertRedirect();
        $this->assertGuest('web');
    }

    // ── pending / suspended vendors ─────────────────────────────────────────

    public function test_an_unapproved_vendor_gets_a_clear_status_page_and_no_portal_function(): void
    {
        $vendor = $this->unapprovedVendor();
        $this->actingAsVendor($vendor);

        foreach (['dashboard', 'applications', 'applications/create'] as $path) {
            $this->get("/developers/{$path}")->assertForbidden()
                ->assertSee('Vendor approval required')
                ->assertSee($vendor->name);
        }
        $this->post('/developers/applications', ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(0, DeveloperApplication::count());
    }

    public function test_a_vendor_suspended_mid_session_loses_the_portal_on_the_next_request(): void
    {
        $vendor = $this->approvedVendor();
        $this->actingAsVendor($vendor)->get('/developers/dashboard')->assertOk();

        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);
        $this->actingAsVendor($vendor->fresh());

        $this->get('/developers/dashboard')->assertForbidden()->assertSee('Vendor approval required');
    }

    public function test_the_existing_vendor_dashboard_is_untouched_by_the_api_platform(): void
    {
        $this->actingAsVendor($this->approvedVendor())->get('/vendor/dashboard')->assertOk();
    }

    // ── dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_shows_real_counts_for_this_vendor_only(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        [, $plain] = $this->makeKey($app);
        $this->makeWebhook($app);

        $this->makeKey($this->makeApp());   // someone else's application and key
        $this->makeWebhook($this->makeApp());

        $res = $this->actingAsVendor($vendor)->get('/developers/dashboard')->assertOk();

        $res->assertSee('data-stat="applications">1<', false);
        $res->assertSee('data-stat="active-keys">1<', false);
        $res->assertSee('1 active of 1 endpoints');
        $this->assertStringNotContainsString($plain, $res->getContent());
    }

    public function test_usage_and_request_logs_are_only_this_vendors(): void
    {
        $mine = $this->approvedVendor();
        $myApp = $this->makeApp($mine);
        [, $plain] = $this->makeKey($myApp);
        $theirs = $this->makeApp();
        [, $theirPlain] = $this->makeKey($theirs);

        $this->getJson('/api/v1/products?network=MINE', $this->auth($plain))->assertOk();
        $this->getJson('/api/v1/networks', $this->auth($theirPlain))->assertOk();

        $this->actingAsVendor($mine)->get("/developers/applications/{$myApp->id}/requests")->assertOk()
            ->assertSee('/api/v1/products')->assertDontSee('/api/v1/networks');
        $this->get("/developers/applications/{$theirs->id}/requests")->assertNotFound();

        $this->assertSame(1, DeveloperApiRequest::where('application_id', $myApp->id)->count());
    }

    // ── applications: always the signed-in vendor's own ─────────────────────

    public function test_an_application_is_owned_by_the_authenticated_vendor_and_ignores_privileged_input(): void
    {
        $vendor = $this->approvedVendor();
        $other = $this->approvedVendor();

        $this->actingAsVendor($vendor)->post('/developers/applications', [
            'name' => 'Shop', 'description' => 'Sells data',
            // Everything below must be ignored:
            'vendor_id' => $other->id, 'vendor_code' => $other->vendor_code, 'type' => 'marketplace',
            'environment' => 'production', 'production_status' => 'approved', 'status' => 'revoked',
            'rate_limit_per_minute' => 100000, 'plan_id' => 999, 'developer_id' => 1,
        ])->assertRedirect();

        $app = DeveloperApplication::firstOrFail();
        $this->assertSame($vendor->id, (int) $app->vendor_id);
        $this->assertSame('active', $app->status);
        $this->assertNull($app->rate_limit_per_minute);
        $this->assertNull($app->developer_id);
        $this->assertSame(0, DeveloperApplication::where('vendor_id', $other->id)->count());
    }

    public function test_every_portal_page_renders_for_an_approved_vendor(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        [$hook] = $this->makeWebhook($app);
        $this->actingAsVendor($vendor);

        foreach (['dashboard', 'applications', 'applications/create', "applications/{$app->id}", "applications/{$app->id}/requests",
            "applications/{$app->id}/webhooks", "applications/{$app->id}/webhooks/{$hook->id}/deliveries"] as $path) {
            $this->get("/developers/{$path}")->assertOk();
        }
    }

    public function test_application_creation_is_audited_against_the_vendor(): void
    {
        $vendor = $this->approvedVendor();

        $this->actingAsVendor($vendor)->post('/developers/applications', ['name' => 'Shop'])->assertRedirect();

        $row = DeveloperAuditEvent::where('action', 'application.created')->firstOrFail();
        $this->assertSame("vendor:{$vendor->id}", $row->actor);
    }

    public function test_application_name_is_required_and_the_per_vendor_cap_is_enforced(): void
    {
        $vendor = $this->approvedVendor();
        $this->actingAsVendor($vendor)->post('/developers/applications', ['name' => ''])->assertSessionHasErrors('name');

        config(['developer_api.applications.max_per_vendor' => 2]);
        $this->makeApp($vendor);
        $this->makeApp($vendor);
        $this->post('/developers/applications', ['name' => 'third'])->assertSessionHasErrors('name');
        $this->assertSame(2, DeveloperApplication::where('vendor_id', $vendor->id)->count());

        // The cap is per vendor: another vendor is unaffected.
        $this->actingAsVendor($this->approvedVendor())->post('/developers/applications', ['name' => 'fine'])->assertRedirect();
    }

    public function test_update_changes_only_descriptive_fields_and_never_the_owner(): void
    {
        $vendor = $this->approvedVendor();
        $other = $this->approvedVendor();
        $app = $this->makeApp($vendor);

        $this->actingAsVendor($vendor)->put("/developers/applications/{$app->id}", [
            'name' => 'Renamed', 'description' => 'New text',
            'vendor_id' => $other->id, 'vendor_code' => $other->vendor_code, 'status' => 'revoked',
            'rate_limit_per_minute' => 99999,
        ])->assertRedirect();

        $app->refresh();
        $this->assertSame('Renamed', $app->name);
        $this->assertSame('New text', $app->description);
        $this->assertSame($vendor->id, (int) $app->vendor_id);
        $this->assertSame('active', $app->status);
        $this->assertNull($app->rate_limit_per_minute);
    }

    public function test_listing_shows_only_own_applications(): void
    {
        $a = $this->approvedVendor();
        $this->makeApp($a, ['name' => 'Mine-App-Marker']);
        $this->makeApp(null, ['name' => 'Theirs-App-Marker']);

        $this->actingAsVendor($a)->get('/developers/applications')->assertOk()
            ->assertSee('Mine-App-Marker')->assertDontSee('Theirs-App-Marker');
    }

    // ── ownership is immutable and mandatory ────────────────────────────────

    public function test_an_application_cannot_exist_without_a_vendor(): void
    {
        $this->expectException(\LogicException::class);

        $app = new DeveloperApplication(['name' => 'Orphan']);
        $app->save();
    }

    public function test_an_applications_vendor_can_never_be_reassigned(): void
    {
        $owner = $this->vendor();
        $thief = $this->vendor();
        $app = $this->makeApp($owner);

        try {
            $app->forceFill(['vendor_id' => $thief->id])->save();
            $this->fail('vendor_id was changed.');
        } catch (\LogicException) {
            $this->assertSame($owner->id, (int) $app->fresh()->vendor_id);
        }

        // Nor through a mass-assignment style update.
        $app->fresh()->update(['name' => 'ok', 'vendor_id' => $thief->id]);
        $this->assertSame($owner->id, (int) $app->fresh()->vendor_id);
    }

    public function test_the_application_service_refuses_an_unapproved_vendor(): void
    {
        $vendor = $this->unapprovedVendor();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\DeveloperApi\DeveloperApplicationService::class)->create($vendor, ['name' => 'x']);
    }

    // ── cross-vendor isolation ──────────────────────────────────────────────

    public function test_one_vendor_cannot_see_or_touch_anothers_application(): void
    {
        $owner = $this->approvedVendor();
        $intruder = $this->approvedVendor();
        $app = $this->makeApp($owner, ['name' => 'Portal App']);
        [$key] = $this->makeKey($app);
        [$hook] = $this->makeWebhook($app);

        $this->actingAsVendor($intruder);

        $this->get("/developers/applications/{$app->id}")->assertNotFound();
        $this->get("/developers/applications/{$app->id}/requests")->assertNotFound();
        $this->put("/developers/applications/{$app->id}", ['name' => 'pwn'])->assertNotFound();
        $this->delete("/developers/applications/{$app->id}")->assertNotFound();
        $this->post("/developers/applications/{$app->id}/keys", ['name' => 'k', 'environment' => 'sandbox', 'scopes' => ['services:read'], 'expires_in_days' => 30])->assertNotFound();
        $this->post("/developers/applications/{$app->id}/keys/{$key->id}/revoke")->assertNotFound();
        $this->post("/developers/applications/{$app->id}/keys/{$key->id}/rotate")->assertNotFound();
        $this->get("/developers/applications/{$app->id}/webhooks")->assertNotFound();
        $this->post("/developers/applications/{$app->id}/webhooks", ['url' => 'https://hooks.example.com/x', 'events' => ['order.created']])->assertNotFound();
        $this->post("/developers/applications/{$app->id}/webhooks/{$hook->id}/rotate-secret")->assertNotFound();
        $this->get("/developers/applications/{$app->id}/webhooks/{$hook->id}/deliveries")->assertNotFound();

        $app->refresh();
        $this->assertSame('Portal App', $app->name);
        $this->assertNull($key->fresh()->revoked_at);
        $this->assertSame('active', $app->status);
    }

    public function test_the_policy_is_a_second_layer_behind_the_vendor_scoped_routes(): void
    {
        $owner = $this->approvedVendor();
        $other = $this->approvedVendor();
        $app = $this->makeApp($owner);
        $suspended = $this->unapprovedVendor();

        $this->assertTrue($owner->can('manage', $app));
        $this->assertFalse($other->can('manage', $app));
        $this->assertFalse($suspended->can('manage', $this->makeApp($suspended)));
    }

    public function test_two_vendors_applications_are_completely_separate_end_to_end(): void
    {
        $a = $this->approvedVendor();
        $b = $this->approvedVendor();
        $pa = $this->product($a, 10);
        $pb = $this->product($b, 20);
        [, $keyA] = $this->makeKey($this->makeApp($a));
        [, $keyB] = $this->makeKey($this->makeApp($b));

        $seenByA = collect($this->getJson('/api/v1/products', $this->auth($keyA))->assertOk()->json('data'))->pluck('id')->all();
        $seenByB = collect($this->getJson('/api/v1/products', $this->auth($keyB))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame(["own_{$pa->id}"], $seenByA);
        $this->assertSame(["own_{$pb->id}"], $seenByB);
    }

    // ── revocation / no business-rule drift ─────────────────────────────────

    public function test_revoking_an_application_kills_its_keys_and_silences_its_webhooks(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        [$key, $plain] = $this->makeKey($app);
        [$hook] = $this->makeWebhook($app);

        $this->actingAsVendor($vendor)->delete("/developers/applications/{$app->id}")->assertRedirect('/developers/applications');

        $this->assertSame('revoked', $app->fresh()->status);
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }

    public function test_using_the_portal_changes_no_vendor_pricing_or_wallet(): void
    {
        $vendor = $this->approvedVendor();
        $product = $this->product($vendor, 10);
        $before = [$product->fresh()->price, Vendor::find($vendor->id)->getAttributes()];

        $this->actingAsVendor($vendor);
        $this->post('/developers/applications', ['name' => 'Shop']);
        $app = DeveloperApplication::firstOrFail();
        $this->post("/developers/applications/{$app->id}/keys", ['name' => 'k', 'environment' => 'production', 'scopes' => ['products:read'], 'expires_in_days' => 30]);

        $this->assertEquals($before[0], $product->fresh()->price);
        $this->assertEquals($before[1], Vendor::find($vendor->id)->getAttributes());
    }
}
