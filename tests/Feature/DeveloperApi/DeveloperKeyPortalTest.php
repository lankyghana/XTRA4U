<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;

class DeveloperKeyPortalTest extends PortalTestCase
{
    private function payload(array $o = []): array
    {
        return array_merge([
            'name' => 'CI key', 'environment' => 'sandbox', 'scopes' => ['services:read', 'products:read'], 'expires_in_days' => 30,
        ], $o);
    }

    private function create(\App\Models\Developer\Developer $dev, \App\Models\Developer\DeveloperApplication $app, array $o = [])
    {
        return $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/keys", $this->payload($o));
    }

    private function plaintextFrom($response): string
    {
        $this->assertSame(1, preg_match('/xt_(?:test|live)_[a-z0-9]{8}_[A-Za-z0-9]{40}/', $response->getContent(), $m));

        return $m[0];
    }

    public function test_a_sandbox_key_is_generated_and_shown_exactly_once(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $res = $this->create($dev, $app)->assertOk();
        $plain = $this->plaintextFrom($res);

        $this->assertStringStartsWith('xt_test_', $plain);
        $res->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        // Never in the session (a redirect/flash would persist it to the session store).
        $this->assertStringNotContainsString($plain, json_encode(session()->all()));

        // Not on any page afterwards.
        $this->get("/developers/applications/{$app->id}")->assertOk()->assertDontSee($plain);
        $this->get('/developers/dashboard')->assertOk()->assertDontSee($plain);
    }

    public function test_only_the_hash_is_stored_and_it_is_never_rendered(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $plain = $this->plaintextFrom($this->create($dev, $app));

        $key = DeveloperApiKey::firstOrFail();
        $this->assertSame(hash('sha256', $plain), $key->key_hash);
        $this->assertNotSame($plain, $key->key_hash);
        $this->assertStringNotContainsString($plain, json_encode($key->getAttributes()));
        $this->assertArrayNotHasKey('key_hash', $key->toArray());

        $page = $this->get("/developers/applications/{$app->id}")->getContent();
        $this->assertStringNotContainsString($key->key_hash, $page);
        $this->assertStringContainsString($key->prefix, $page); // metadata only
    }

    public function test_the_generated_key_works_against_the_api_with_only_its_scopes(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $plain = $this->plaintextFrom($this->create($dev, $app, ['scopes' => ['services:read']]));

        // Sandbox key: authenticated (not 401), but the sandbox engine is Milestone 4, so commerce is refused.
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(501)->assertJsonPath('error.code', 'SANDBOX_UNAVAILABLE');

        // Production-approved application: the key works, but only for its scopes.
        $live = $this->developer();
        $liveApp = $this->sandboxApp($live, ['environment' => 'production', 'production_status' => 'approved']);
        $livePlain = $this->plaintextFrom($this->create($live, $liveApp, ['environment' => 'production', 'scopes' => ['services:read']]));
        $this->getJson('/api/v1/services', $this->auth($livePlain))->assertOk();
        $this->getJson('/api/v1/products', $this->auth($livePlain))->assertStatus(403);
    }

    public function test_key_metadata_shows_status_expiry_and_scopes(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $this->create($dev, $app, ['name' => 'Visible-Key-Name']);

        $this->get("/developers/applications/{$app->id}")->assertOk()
            ->assertSee('Visible-Key-Name')->assertSee('services:read')->assertSee('active');
        $key = DeveloperApiKey::firstOrFail();
        $this->assertTrue($key->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_key_input_is_validated(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->create($dev, $app, ['scopes' => []])->assertSessionHasErrors('scopes');
        $this->create($dev, $app, ['scopes' => ['admin:everything']])->assertSessionHasErrors('scopes.0');
        $this->create($dev, $app, ['expires_in_days' => 0])->assertSessionHasErrors('expires_in_days');
        $this->create($dev, $app, ['expires_in_days' => 9999])->assertSessionHasErrors('expires_in_days');
        $this->create($dev, $app, ['environment' => 'staging'])->assertSessionHasErrors('environment');
        $this->create($dev, $app, ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_a_production_key_cannot_be_generated_without_admin_approval(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->create($dev, $app, ['environment' => 'production'])->assertSessionHasErrors('environment');
        $this->assertSame(0, DeveloperApiKey::count());

        // Requested but not yet approved: still refused.
        $app->forceFill(['production_status' => 'requested'])->save();
        $this->create($dev, $app, ['environment' => 'production'])->assertSessionHasErrors('environment');

        // Rejected: still refused.
        $app->forceFill(['production_status' => 'rejected'])->save();
        $this->create($dev, $app, ['environment' => 'production'])->assertSessionHasErrors('environment');
        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_request_input_cannot_fake_production_approval(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->create($dev, $app, ['environment' => 'production', 'production_status' => 'approved', 'approved' => true])
            ->assertSessionHasErrors('environment');
        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_a_production_key_is_issued_once_approved_and_is_live_format(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev, ['environment' => 'production', 'production_status' => 'approved']);

        $plain = $this->plaintextFrom($this->create($dev, $app, ['environment' => 'production']));

        $this->assertStringStartsWith('xt_live_', $plain);
        $this->assertSame('production', DeveloperApiKey::firstOrFail()->environment);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
    }

    public function test_an_unapproved_application_never_receives_working_production_credentials(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        // Even a key that somehow exists dies when access is not approved.
        [, $plain] = $this->makeKey($this->makeApp('production'));
        $other = $this->makeApp('production');
        $other->forceFill(['environment' => 'sandbox', 'production_status' => 'rejected'])->save();
        [, $stale] = $this->forceProductionKey($other);

        $this->getJson('/api/v1/services', $this->auth($stale))->assertStatus(403);
        $this->assertNotSame('', $plain);
        $this->assertSame('none', $app->fresh()->production_status);
    }

    /** Mint a production key while approved, then withdraw approval. */
    private function forceProductionKey(\App\Models\Developer\DeveloperApplication $app): array
    {
        $app->forceFill(['environment' => 'production', 'production_status' => 'approved'])->save();
        $r = $this->makeKey($app);
        $app->forceFill(['environment' => 'sandbox', 'production_status' => 'rejected'])->save();

        return $r;
    }

    public function test_the_per_application_active_key_cap_is_enforced(): void
    {
        config(['developer_api.keys.max_active_per_application' => 2]);
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);

        $this->create($dev, $app)->assertOk();
        $this->create($dev, $app)->assertOk();
        $this->create($dev, $app)->assertStatus(302);
        $this->assertSame(2, DeveloperApiKey::count());
    }

    public function test_rotation_issues_a_new_key_revokes_the_old_one_and_keeps_environment_and_scopes(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $oldPlain = $this->plaintextFrom($this->create($dev, $app, ['scopes' => ['services:read']]));
        $old = DeveloperApiKey::firstOrFail();

        $res = $this->post("/developers/applications/{$app->id}/keys/{$old->id}/rotate")->assertOk();
        $newPlain = $this->plaintextFrom($res);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->assertNotSame($oldPlain, $newPlain);
        $this->assertNotNull($old->fresh()->revoked_at);
        $new = DeveloperApiKey::where('id', '!=', $old->id)->firstOrFail();
        $this->assertSame('sandbox', $new->environment);
        $this->assertSame(['services:read'], $new->scopes);
        $this->assertSame(hash('sha256', $newPlain), $new->key_hash);

        $this->getJson('/api/v1/services', $this->auth($oldPlain))->assertStatus(401);
        $this->getJson('/api/v1/services', $this->auth($newPlain))->assertStatus(501); // authenticated sandbox key
    }

    public function test_a_revoked_key_cannot_be_rotated(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$key] = $this->makeKey($app);

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/keys/{$key->id}/revoke")->assertRedirect();
        $this->post("/developers/applications/{$app->id}/keys/{$key->id}/rotate")->assertSessionHasErrors('key');
        $this->assertSame(1, DeveloperApiKey::count());
    }

    public function test_revocation_takes_effect_immediately(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$key, $plain] = $this->makeKey($app);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(501); // authenticated sandbox key

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$app->id}/keys/{$key->id}/revoke")->assertRedirect();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }

    public function test_expired_keys_are_rejected_and_listed_as_expired(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        [$key, $plain] = $this->makeKey($app, null, now()->addDay());
        $key->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
        $this->assertSame('expired', $key->fresh()->status());
        $this->actingAsDeveloper($dev)->get("/developers/applications/{$app->id}")->assertOk()->assertSee('expired');
    }

    public function test_a_key_id_from_another_application_of_the_same_developer_is_not_addressable(): void
    {
        $dev = $this->developer();
        $a = $this->sandboxApp($dev);
        $b = $this->sandboxApp($dev);
        [$keyOfB] = $this->makeKey($b);

        $this->actingAsDeveloper($dev)->post("/developers/applications/{$a->id}/keys/{$keyOfB->id}/revoke")->assertNotFound();
        $this->post("/developers/applications/{$a->id}/keys/{$keyOfB->id}/rotate")->assertNotFound();
        $this->assertNull($keyOfB->fresh()->revoked_at);
    }

    public function test_key_actions_are_written_to_the_audit_trail_without_secrets(): void
    {
        $dev = $this->developer();
        $app = $this->sandboxApp($dev);
        $plain = $this->plaintextFrom($this->create($dev, $app));

        $rows = \App\Models\Developer\DeveloperAuditEvent::all();
        $this->assertTrue($rows->contains('action', 'api_key.created'));
        $this->assertStringNotContainsString($plain, json_encode($rows->toArray()));
        $this->assertStringNotContainsString(hash('sha256', $plain), json_encode($rows->toArray()));
    }
}
