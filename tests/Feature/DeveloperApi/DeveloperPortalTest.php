<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\Developer;
use App\Models\Developer\DeveloperApplication;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DeveloperPortalTest extends PortalTestCase
{
    private function registrationPayload(array $o = []): array
    {
        return array_merge([
            'name' => 'Ama Dev', 'email' => 'Ama@Example.test', 'company' => 'Acme',
            'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9', 'terms' => '1',
        ], $o);
    }

    // ── registration & authentication ───────────────────────────────────────

    public function test_landing_page_is_public(): void
    {
        $this->get('/developers')->assertOk();
        $this->get('/developers/register')->assertOk();
        $this->get('/developers/login')->assertOk();
    }

    public function test_registration_creates_a_hashed_developer_and_signs_them_in_on_the_developer_guard_only(): void
    {
        $this->post('/developers/register', $this->registrationPayload())->assertRedirect('/developers/dashboard');

        $dev = Developer::where('email', 'ama@example.test')->firstOrFail();
        $this->assertNotSame('correct-horse-9', $dev->password);
        $this->assertTrue(Hash::check('correct-horse-9', $dev->password));
        $this->assertTrue(Auth::guard('developer')->check());
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertFalse(Auth::guard('vendor')->check());
    }

    public function test_registration_validates_input(): void
    {
        $this->developer(['email' => 'taken@example.test']);

        $this->post('/developers/register', $this->registrationPayload(['email' => 'taken@example.test']))->assertSessionHasErrors('email');
        $this->post('/developers/register', $this->registrationPayload(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post('/developers/register', $this->registrationPayload(['password' => 'correct-horse-9', 'password_confirmation' => 'different-9']))->assertSessionHasErrors('password');
        $this->post('/developers/register', $this->registrationPayload(['terms' => null]))->assertSessionHasErrors('terms');
        $this->assertSame(1, Developer::count());
    }

    public function test_login_logout_and_generic_failure_message(): void
    {
        $dev = $this->developer(['email' => 'a@example.test', 'password' => 'correct-horse-9']);

        $bad = $this->from('/developers/login')->post('/developers/login', ['email' => 'a@example.test', 'password' => 'wrong']);
        $bad->assertSessionHasErrors('email');
        $unknown = $this->from('/developers/login')->post('/developers/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $this->assertSame(session('errors')->first('email'), $bad->baseResponse->getSession()->get('errors')->first('email'));

        $this->post('/developers/login', ['email' => 'a@example.test', 'password' => 'correct-horse-9'])->assertRedirect('/developers/dashboard');
        $this->assertAuthenticatedAs($dev, 'developer');

        $this->post('/developers/logout')->assertRedirect('/developers');
        $this->assertGuest('developer');
    }

    public function test_suspended_developer_cannot_log_in_or_keep_a_session(): void
    {
        $dev = $this->developer(['email' => 's@example.test', 'password' => 'correct-horse-9']);
        $dev->forceFill(['status' => Developer::STATUS_SUSPENDED])->save();

        $this->post('/developers/login', ['email' => 's@example.test', 'password' => 'correct-horse-9'])->assertSessionHasErrors('email');
        $this->assertGuest('developer');

        $this->actingAsDeveloper($dev)->get('/developers/dashboard')->assertRedirect('/developers/login');
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/developers/login', ['email' => 'x@example.test', 'password' => 'nope']);
        }
        $this->post('/developers/login', ['email' => 'x@example.test', 'password' => 'nope'])->assertStatus(429);
    }

    public function test_portal_routes_require_a_developer_session(): void
    {
        $app = $this->sandboxApp($this->developer());

        foreach (['/developers/dashboard', '/developers/applications', '/developers/applications/create', "/developers/applications/{$app->id}", "/developers/applications/{$app->id}/webhooks"] as $url) {
            $this->get($url)->assertRedirect('/developers/login');
        }
        $this->post('/developers/applications', ['name' => 'x', 'type' => 'marketplace'])->assertRedirect('/developers/login');
    }

    public function test_other_realms_do_not_grant_portal_access(): void
    {
        $this->asAdmin()->get('/developers/dashboard')->assertRedirect('/developers/login');
        $this->actingAs($this->vendor(), 'vendor')->get('/developers/dashboard')->assertRedirect('/developers/login');
    }

    public function test_a_developer_session_does_not_open_the_vendor_or_admin_areas(): void
    {
        $this->actingAsDeveloper($this->developer());

        $this->get('/admin/developer-access')->assertRedirect();
        $this->get('/vendor/developer-access')->assertRedirect();
    }

    // ── dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_shows_real_counts_for_this_developer_only(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [, $plain] = $this->makeKey($app);
        $this->makeWebhook($app);

        $this->sandboxApp($this->developer()); // someone else's

        $res = $this->actingAsDeveloper($dev)->get('/developers/dashboard')->assertOk();

        $res->assertSee('data-stat="applications">1<', false);
        $res->assertSee('data-stat="active-keys">1<', false);
        $res->assertSee('1 active of 1 endpoints');
        $this->assertStringNotContainsString($plain, $res->getContent());
    }

    // ── applications ────────────────────────────────────────────────────────

    public function test_marketplace_application_starts_in_the_sandbox_and_ignores_privileged_input(): void
    {
        $dev = $this->developer();

        $this->actingAsDeveloper($dev)->post('/developers/applications', [
            'name' => 'Shop', 'description' => 'Resells data', 'type' => 'marketplace',
            // Everything below must be ignored:
            'environment' => 'production', 'production_status' => 'approved', 'status' => 'active',
            'vendor_id' => 99, 'vendor_link_status' => 'approved', 'rate_limit_per_minute' => 100000, 'plan_id' => 1,
        ])->assertRedirect();

        $app = DeveloperApplication::firstOrFail();
        $this->assertSame($dev->id, (int) $app->developer_id);
        $this->assertSame('sandbox', $app->environment);
        $this->assertSame('none', $app->production_status);
        $this->assertNull($app->vendor_id);
        $this->assertSame('none', $app->vendor_link_status);
        $this->assertFalse($app->hasProductionAccess());
    }

    public function test_application_name_is_required_and_the_per_developer_cap_is_enforced(): void
    {
        $dev = $this->developer();
        $this->actingAsDeveloper($dev)->post('/developers/applications', ['name' => '', 'type' => 'marketplace'])->assertSessionHasErrors('name');

        config(['developer_api.applications.max_per_developer' => 2]);
        $this->sandboxApp($dev);
        $this->sandboxApp($dev);
        $this->actingAsDeveloper($dev)->post('/developers/applications', ['name' => 'third', 'type' => 'marketplace'])->assertSessionHasErrors('name');
        $this->assertSame(2, $dev->applications()->count());
    }

    public function test_vendor_bound_application_is_pending_and_notifies_that_vendor_only(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $other = $this->vendor(['is_approved' => true]);
        $dev = $this->developer();

        $this->actingAsDeveloper($dev)->post('/developers/applications', [
            'name' => 'Bound', 'type' => 'vendor', 'vendor_code' => strtolower($vendor->vendor_code),
        ])->assertRedirect();

        $app = DeveloperApplication::firstOrFail();
        $this->assertSame($vendor->id, (int) $app->vendor_id);
        $this->assertSame('pending', $app->vendor_link_status);
        $this->assertSame('sandbox', $app->environment);
        $this->assertSame(1, VendorNotification::where('vendor_id', $vendor->id)->where('type', 'developer_access_request')->count());
        $this->assertSame(0, VendorNotification::where('vendor_id', $other->id)->count());
    }

    public function test_unknown_or_unapproved_vendor_codes_get_the_same_generic_error(): void
    {
        $unapproved = $this->vendor(['is_approved' => false]);
        $dev = $this->developer();

        $a = $this->actingAsDeveloper($dev)->post('/developers/applications', ['name' => 'A', 'type' => 'vendor', 'vendor_code' => 'NOSUCHCODE']);
        $b = $this->actingAsDeveloper($dev)->post('/developers/applications', ['name' => 'B', 'type' => 'vendor', 'vendor_code' => $unapproved->vendor_code]);

        $a->assertSessionHasErrors('vendor_code');
        $b->assertSessionHasErrors('vendor_code');
        $this->assertSame(
            $a->baseResponse->getSession()->get('errors')->first('vendor_code'),
            $b->baseResponse->getSession()->get('errors')->first('vendor_code')
        );
        $this->assertSame(0, DeveloperApplication::count());
    }

    public function test_update_changes_only_descriptive_fields_and_never_the_vendor_binding(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $dev = $this->developer();
        $app = $this->sandboxApp($dev, ['vendor_id' => $vendor->id, 'vendor_link_status' => 'approved']);
        $other = $this->vendor(['is_approved' => true]);

        $this->actingAsDeveloper($dev)->put("/developers/applications/{$app->id}", [
            'name' => 'Renamed', 'description' => 'New text',
            'vendor_id' => $other->id, 'vendor_code' => $other->vendor_code, 'type' => 'marketplace',
            'environment' => 'production', 'production_status' => 'approved', 'vendor_link_status' => 'rejected',
        ])->assertRedirect();

        $app->refresh();
        $this->assertSame('Renamed', $app->name);
        $this->assertSame('New text', $app->description);
        $this->assertSame($vendor->id, (int) $app->vendor_id);
        $this->assertSame('approved', $app->vendor_link_status);
        $this->assertSame('sandbox', $app->environment);
        $this->assertSame('none', $app->production_status);
    }

    // ── ownership / isolation ───────────────────────────────────────────────

    public function test_one_developer_cannot_see_or_touch_anothers_application(): void
    {
        $owner = $this->developer();
        $intruder = $this->developer();
        $app = $this->sandboxApp($owner);
        [$key] = $this->makeKey($app);
        [$hook] = $this->makeWebhook($app);

        $this->actingAsDeveloper($intruder);

        $this->get("/developers/applications/{$app->id}")->assertNotFound();
        $this->put("/developers/applications/{$app->id}", ['name' => 'pwn'])->assertNotFound();
        $this->delete("/developers/applications/{$app->id}")->assertNotFound();
        $this->post("/developers/applications/{$app->id}/production-request")->assertNotFound();
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

    public function test_listing_shows_only_own_applications(): void
    {
        $a = $this->developer();
        $this->sandboxApp($a, ['name' => 'Mine-App-Marker']);
        $this->sandboxApp($this->developer(), ['name' => 'Theirs-App-Marker']);

        $this->actingAsDeveloper($a)->get('/developers/applications')->assertOk()
            ->assertSee('Mine-App-Marker')->assertDontSee('Theirs-App-Marker');
    }

    // ── production approval ─────────────────────────────────────────────────

    public function test_production_request_then_admin_approval_unlocks_production_keys(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/production-request")->assertRedirect();
        $app->refresh();
        $this->assertSame('requested', $app->production_status);
        $this->assertFalse($app->hasProductionAccess());
        $this->assertSame('sandbox', $app->environment);

        // A second request while pending is refused.
        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/production-request")->assertSessionHasErrors('production');

        $this->asAdmin()
            ->post("/admin/developer-access/{$app->id}/approve")->assertRedirect();

        $app->refresh();
        $this->assertSame('approved', $app->production_status);
        $this->assertSame('production', $app->environment);
        $this->assertTrue($app->hasProductionAccess());
    }

    public function test_developers_can_never_approve_themselves(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev, ['production_status' => 'requested']);

        $this->actingAsDeveloper($dev)->post("/admin/developer-access/{$app->id}/approve")->assertRedirect();
        $this->assertSame('requested', $app->fresh()->production_status);
    }

    public function test_non_admin_users_cannot_review_production_requests(): void
    {
        $app = $this->sandboxApp($this->developer(), ['production_status' => 'requested']);

        $this->actingAs(User::factory()->create(['role' => 'user']), 'web')->post("/admin/developer-access/{$app->id}/approve")->assertStatus(403);
        $this->actingAs($this->vendor(), 'vendor')->post("/admin/developer-access/{$app->id}/approve")->assertStatus(403);
        $denied = $this->actingAsDeveloper($app->developer)->post("/admin/developer-access/{$app->id}/approve");
        $this->assertContains($denied->getStatusCode(), [302, 401, 403]);

        $this->assertSame('requested', $app->fresh()->production_status);
    }

    public function test_admin_can_reject_with_a_note_and_withdraw_approved_access(): void
    {
        $admin = $this->admin();
        $app = $this->sandboxApp($this->developer(), ['production_status' => 'requested']);

        $this->actingAs($admin, 'web')->post("/admin/developer-access/{$app->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($admin, 'web')->post("/admin/developer-access/{$app->id}/reject", ['note' => 'Incomplete use case'])->assertRedirect();
        $app->refresh();
        $this->assertSame('rejected', $app->production_status);
        $this->assertSame('Incomplete use case', $app->production_review_note);
        $this->assertSame('sandbox', $app->environment);

        $live = $this->makeApp('production');
        $this->actingAs($admin, 'web')->post("/admin/developer-access/{$live->id}/withdraw", ['note' => 'Abuse'])->assertRedirect();
        $live->refresh();
        $this->assertFalse($live->hasProductionAccess());
        $this->assertSame('sandbox', $live->environment);
    }

    public function test_admin_cannot_approve_an_application_that_never_requested(): void
    {
        $app = $this->sandboxApp($this->developer());

        $this->asAdmin()
            ->post("/admin/developer-access/{$app->id}/approve")->assertSessionHasErrors('production');
        $this->assertSame('none', $app->fresh()->production_status);
    }

    public function test_admin_review_page_lists_requests(): void
    {
        $this->sandboxApp($this->developer(), ['production_status' => 'requested', 'production_requested_at' => now(), 'name' => 'Needs-Review-Marker']);

        $this->asAdmin()->get('/admin/developer-access')
            ->assertOk()->assertSee('Needs-Review-Marker');
    }

    // ── vendor-bound approval ───────────────────────────────────────────────

    public function test_vendor_bound_application_cannot_request_production_until_its_vendor_approves(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $dev = $this->developer();
        $app = $this->sandboxApp($dev, ['vendor_id' => $vendor->id, 'vendor_link_status' => 'pending']);

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/production-request")->assertSessionHasErrors('production');
        $this->assertSame('none', $app->fresh()->production_status);

        $this->actingAs($vendor, 'vendor')->post("/vendor/developer-access/{$app->id}/approve")->assertRedirect();
        $this->assertSame('approved', $app->fresh()->vendor_link_status);

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/production-request")->assertSessionHasNoErrors();
        $this->assertSame('requested', $app->fresh()->production_status);
    }

    public function test_admin_cannot_approve_production_while_the_vendor_has_not_approved(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $app = $this->sandboxApp($this->developer(), [
            'vendor_id' => $vendor->id, 'vendor_link_status' => 'pending', 'production_status' => 'requested',
        ]);

        $this->asAdmin()
            ->post("/admin/developer-access/{$app->id}/approve")->assertSessionHasErrors('production');
        $this->assertFalse($app->fresh()->hasProductionAccess());
    }

    public function test_a_vendor_can_only_decide_for_its_own_applications(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $other = $this->vendor(['is_approved' => true]);
        $app = $this->sandboxApp($this->developer(), ['vendor_id' => $vendor->id, 'vendor_link_status' => 'pending']);

        $this->actingAs($other, 'vendor')->post("/vendor/developer-access/{$app->id}/approve")->assertNotFound();
        $this->actingAs($other, 'vendor')->post("/vendor/developer-access/{$app->id}/reject")->assertNotFound();
        $this->assertSame('pending', $app->fresh()->vendor_link_status);

        $this->actingAs($other, 'vendor')->get('/vendor/developer-access')->assertOk()->assertDontSee($app->name . '-nope');
    }

    public function test_vendor_can_withdraw_approval_and_the_applications_vendor_scope_stops(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $app = $this->sandboxApp($this->developer(), ['vendor_id' => $vendor->id, 'vendor_link_status' => 'approved']);

        $this->actingAs($vendor, 'vendor')->post("/vendor/developer-access/{$app->id}/reject")->assertRedirect();
        $this->assertSame('rejected', $app->fresh()->vendor_link_status);
        $this->assertFalse($app->fresh()->vendorScopeIsActive());
    }

    public function test_vendor_page_lists_only_that_vendors_requests(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $other = $this->vendor(['is_approved' => true]);
        $this->sandboxApp($this->developer(), ['name' => 'For-Me-Marker', 'vendor_id' => $vendor->id, 'vendor_link_status' => 'pending', 'vendor_link_requested_at' => now()]);
        $this->sandboxApp($this->developer(), ['name' => 'For-Other-Marker', 'vendor_id' => $other->id, 'vendor_link_status' => 'pending', 'vendor_link_requested_at' => now()]);

        $this->actingAs($vendor, 'vendor')->get('/vendor/developer-access')->assertOk()
            ->assertSee('For-Me-Marker')->assertDontSee('For-Other-Marker');
    }

    // ── no business-rule drift ──────────────────────────────────────────────

    public function test_approving_production_does_not_change_any_vendor_pricing_or_wallet(): void
    {
        $vendor = $this->vendor(['is_approved' => true]);
        $product = $this->product($vendor, 10);
        $app = $this->sandboxApp($this->developer(), ['production_status' => 'requested']);
        $before = [$product->fresh()->price, Vendor::find($vendor->id)->getAttributes()];

        $this->asAdmin()->post("/admin/developer-access/{$app->id}/approve");

        $this->assertEquals($before[0], $product->fresh()->price);
        $this->assertEquals($before[1], Vendor::find($vendor->id)->getAttributes());
    }

    public function test_revoking_an_application_kills_its_keys_and_silences_its_webhooks(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$key, $plain] = $this->makeKey($app);
        [$hook] = $this->makeWebhook($app);

        $this->actingAsDeveloper($dev)->delete("/developers/applications/{$app->id}")->assertRedirect('/developers/applications');

        $this->assertSame('revoked', $app->fresh()->status);
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }
}
