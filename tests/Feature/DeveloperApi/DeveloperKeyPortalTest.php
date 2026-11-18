<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperAuditEvent;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * API keys, managed by the vendor that owns the application, signed in through the existing
 * `vendor` guard. Vendor approval is the only gate: there is no extra production approval.
 */
class DeveloperKeyPortalTest extends PortalTestCase
{
    private function payload(array $o = []): array
    {
        return array_merge([
            'name' => 'CI key', 'environment' => 'sandbox', 'scopes' => ['services:read', 'products:read'], 'expires_in_days' => 30,
        ], $o);
    }

    private function create(Vendor $vendor, DeveloperApplication $app, array $o = [])
    {
        return $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys", $this->payload($o));
    }

    private function plaintextFrom($response): string
    {
        $this->assertSame(1, preg_match('/xt_(?:test|live)_[a-z0-9]{8}_[A-Za-z0-9]{40}/', $response->getContent(), $m));

        return $m[0];
    }

    /** @return array{0:Vendor,1:DeveloperApplication} */
    private function vendorAndApp(): array
    {
        $vendor = $this->approvedVendor();

        return [$vendor, $this->makeApp($vendor)];
    }

    public function test_a_key_is_generated_and_shown_exactly_once(): void
    {
        [$vendor, $app] = $this->vendorAndApp();

        $res = $this->create($vendor, $app)->assertOk();
        $plain = $this->plaintextFrom($res);

        $this->assertStringStartsWith('xt_test_', $plain);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        // Never in the session (a redirect/flash would persist it to the session store).
        $this->assertStringNotContainsString($plain, json_encode(session()->all()));

        // Not on any page afterwards.
        $this->get("/developers/applications/{$app->id}")->assertOk()->assertDontSee($plain);
        $this->get('/developers/dashboard')->assertOk()->assertDontSee($plain);
    }

    public function test_only_the_hash_is_stored_and_it_is_never_rendered(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        $plain = $this->plaintextFrom($this->create($vendor, $app));

        $key = DeveloperApiKey::firstOrFail();
        $this->assertSame(hash('sha256', $plain), $key->key_hash);
        $this->assertNotSame($plain, $key->key_hash);
        $this->assertStringNotContainsString($plain, json_encode($key->getAttributes()));
        $this->assertArrayNotHasKey('key_hash', $key->toArray());

        $page = $this->get("/developers/applications/{$app->id}")->getContent();
        $this->assertStringNotContainsString($key->key_hash, $page);
        $this->assertStringContainsString($key->prefix, $page); // metadata only
    }

    public function test_a_key_is_associated_with_the_vendor_that_generated_it(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        [$other, $otherApp] = $this->vendorAndApp();

        $this->create($vendor, $app);
        $this->create($other, $otherApp);

        $key = DeveloperApiKey::where('application_id', $app->id)->firstOrFail();
        $this->assertSame($vendor->id, (int) $key->application->vendor_id);
        $otherKey = DeveloperApiKey::where('application_id', $otherApp->id)->firstOrFail();
        $this->assertSame($other->id, (int) $otherKey->application->vendor_id);
    }

    public function test_both_environments_are_available_to_an_approved_vendor_without_extra_approval(): void
    {
        [$vendor, $app] = $this->vendorAndApp();

        $live = $this->plaintextFrom($this->create($vendor, $app, ['environment' => 'production', 'scopes' => ['services:read']]));
        $test = $this->plaintextFrom($this->create($vendor, $app, ['environment' => 'sandbox', 'scopes' => ['services:read']]));

        $this->assertStringStartsWith('xt_live_', $live);
        $this->assertStringStartsWith('xt_test_', $test);
        $this->assertSame('production', DeveloperApiKey::where('key_hash', hash('sha256', $live))->value('environment'));

        $this->getJson('/api/v1/services', $this->auth($live))->assertOk();
        // Sandbox keys are served by the sandbox engine (Milestone 4) and say so in the envelope.
        $this->getJson('/api/v1/services', $this->auth($test))->assertOk()->assertJsonPath('meta.environment', 'sandbox');
    }

    public function test_a_key_only_works_for_its_scopes(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        $plain = $this->plaintextFrom($this->create($vendor, $app, ['environment' => 'production', 'scopes' => ['services:read']]));

        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
        $this->getJson('/api/v1/products', $this->auth($plain))->assertStatus(403);
    }

    public function test_key_metadata_shows_status_expiry_and_scopes(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        $this->create($vendor, $app, ['name' => 'Visible-Key-Name']);

        $this->get("/developers/applications/{$app->id}")->assertOk()
            ->assertSee('Visible-Key-Name')->assertSee('services:read')->assertSee('active');
        $key = DeveloperApiKey::firstOrFail();
        $this->assertTrue($key->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_key_input_is_validated(): void
    {
        [$vendor, $app] = $this->vendorAndApp();

        $this->create($vendor, $app, ['scopes' => []])->assertSessionHasErrors('scopes');
        $this->create($vendor, $app, ['scopes' => ['admin:everything']])->assertSessionHasErrors('scopes.0');
        $this->create($vendor, $app, ['expires_in_days' => 0])->assertSessionHasErrors('expires_in_days');
        $this->create($vendor, $app, ['expires_in_days' => 9999])->assertSessionHasErrors('expires_in_days');
        $this->create($vendor, $app, ['environment' => 'staging'])->assertSessionHasErrors('environment');
        $this->create($vendor, $app, ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_request_input_cannot_choose_another_owner_or_status(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        $stranger = $this->approvedVendor();

        $this->create($vendor, $app, ['vendor_id' => $stranger->id, 'application_id' => 9999, 'revoked_at' => null])->assertOk();

        $key = DeveloperApiKey::firstOrFail();
        $this->assertSame($app->id, (int) $key->application_id);
        $this->assertSame($vendor->id, (int) $app->fresh()->vendor_id);
    }

    public function test_a_vendor_that_is_not_approved_cannot_generate_keys(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        // A real session reloads the vendor on every request; fresh() reproduces that.
        $this->actingAsVendor($vendor->fresh());
        $this->post("/developers/applications/{$app->id}/keys", $this->payload(['environment' => 'production']))->assertForbidden();
        $this->post("/developers/applications/{$app->id}/keys", $this->payload(['environment' => 'sandbox']))->assertForbidden();
        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_a_stale_session_still_cannot_mint_keys_because_the_service_checks_live(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        $this->actingAsVendor($vendor);   // portal sees the old, approved in-memory vendor...
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        // ...but the key service re-reads approval from the database.
        $this->post("/developers/applications/{$app->id}/keys", $this->payload())->assertSessionHasErrors('environment');
        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_the_service_refuses_keys_for_an_unapproved_vendor_even_if_the_portal_is_bypassed(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $this->expectException(\App\Support\DeveloperApi\ApiException::class);
        app(\App\Services\DeveloperApi\ApiKeyService::class)->create($app->fresh(), 'x', ['services:read'], null, null, 'production');
    }

    public function test_the_per_application_active_key_cap_is_enforced(): void
    {
        config(['developer_api.keys.max_active_per_application' => 2]);
        [$vendor, $app] = $this->vendorAndApp();

        $this->create($vendor, $app)->assertOk();
        $this->create($vendor, $app)->assertOk();
        $this->create($vendor, $app)->assertStatus(302);
        $this->assertSame(2, DeveloperApiKey::count());
    }

    public function test_rotation_issues_a_new_key_revokes_the_old_one_and_keeps_environment_and_scopes(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        $oldPlain = $this->plaintextFrom($this->create($vendor, $app, ['environment' => 'production', 'scopes' => ['services:read']]));
        $old = DeveloperApiKey::firstOrFail();

        $res = $this->post("/developers/applications/{$app->id}/keys/{$old->id}/rotate")->assertOk();
        $newPlain = $this->plaintextFrom($res);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->assertNotSame($oldPlain, $newPlain);
        $this->assertNotNull($old->fresh()->revoked_at);
        $new = DeveloperApiKey::where('id', '!=', $old->id)->firstOrFail();
        $this->assertSame('production', $new->environment);
        $this->assertSame(['services:read'], $new->scopes);
        $this->assertSame(hash('sha256', $newPlain), $new->key_hash);

        $this->getJson('/api/v1/services', $this->auth($oldPlain))->assertStatus(401);
        $this->getJson('/api/v1/services', $this->auth($newPlain))->assertOk();
    }

    public function test_a_revoked_key_cannot_be_rotated(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        [$key] = $this->makeKey($app);

        $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys/{$key->id}/revoke")->assertRedirect();
        $this->post("/developers/applications/{$app->id}/keys/{$key->id}/rotate")->assertSessionHasErrors('key');
        $this->assertSame(1, DeveloperApiKey::count());
    }

    public function test_revocation_takes_effect_immediately(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        [$key, $plain] = $this->makeKey($app);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();

        $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys/{$key->id}/revoke")->assertRedirect();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }

    public function test_expired_keys_are_rejected_and_listed_as_expired(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        [$key, $plain] = $this->makeKey($app, null, now()->addDay());
        $key->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
        $this->assertSame('expired', $key->fresh()->status());
        $this->actingAsVendor($vendor)->get("/developers/applications/{$app->id}")->assertOk()->assertSee('expired');
    }

    public function test_a_key_id_from_another_application_of_the_same_vendor_is_not_addressable(): void
    {
        $vendor = $this->approvedVendor();
        $a = $this->makeApp($vendor);
        $b = $this->makeApp($vendor);
        [$keyOfB] = $this->makeKey($b);

        $this->actingAsVendor($vendor)->post("/developers/applications/{$a->id}/keys/{$keyOfB->id}/revoke")->assertNotFound();
        $this->post("/developers/applications/{$a->id}/keys/{$keyOfB->id}/rotate")->assertNotFound();
        $this->assertNull($keyOfB->fresh()->revoked_at);
    }

    // ── cross-vendor isolation ──────────────────────────────────────────────

    public function test_another_vendor_cannot_create_rotate_or_revoke_keys_on_my_application(): void
    {
        [$mine, $myApp] = $this->vendorAndApp();
        [$key, $plain] = $this->makeKey($myApp);
        $intruder = $this->approvedVendor();

        $this->actingAsVendor($intruder)->post("/developers/applications/{$myApp->id}/keys", $this->payload())->assertNotFound();
        $this->post("/developers/applications/{$myApp->id}/keys/{$key->id}/rotate")->assertNotFound();
        $this->post("/developers/applications/{$myApp->id}/keys/{$key->id}/revoke")->assertNotFound();

        $this->assertNull($key->fresh()->revoked_at);
        $this->assertSame(1, DeveloperApiKey::count());
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
    }

    public function test_key_actions_are_written_to_the_audit_trail_without_secrets(): void
    {
        [$vendor, $app] = $this->vendorAndApp();
        $plain = $this->plaintextFrom($this->create($vendor, $app));

        $rows = DeveloperAuditEvent::all();
        $this->assertTrue($rows->contains('action', 'api_key.created'));
        $this->assertStringNotContainsString($plain, json_encode($rows->toArray()));
        $this->assertStringNotContainsString(hash('sha256', $plain), json_encode($rows->toArray()));
    }
}
