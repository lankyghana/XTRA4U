<?php

namespace Tests\Feature\Ai;

use App\Models\Developer\DeveloperApiKey;
use App\Services\DeveloperApi\ApiKeyService;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\DeveloperApi\PortalTestCase;

/**
 * `ai:chat` is declared but has no endpoint. It must not be grantable to NEW keys, while keys that already
 * carry it are left exactly as they are (not revoked, not rewritten, still authenticating).
 */
class AiChatScopeTest extends PortalTestCase
{
    private function payload(array $scopes): array
    {
        return ['name' => 'CI key', 'environment' => 'sandbox', 'scopes' => $scopes, 'expires_in_days' => 30];
    }

    /** A key as it could exist from before this change: stored scopes include ai:chat. @return array{0:DeveloperApiKey,1:string} */
    private function legacyKey(array $scopes, string $environment = 'production'): array
    {
        $app = $this->makeApp();
        [$key, $plain] = $this->makeKey($app, ['services:read'], null, $environment);
        DB::table('developer_api_keys')->where('id', $key->id)->update(['scopes' => json_encode($scopes)]);

        return [$key->fresh(), $plain];
    }

    public function test_the_scope_is_still_declared_but_not_grantable(): void
    {
        $this->assertArrayHasKey('ai:chat', config('developer_api.scopes'));
        $this->assertArrayNotHasKey('ai:chat', ApiKeyService::availableScopes());

        foreach (array_keys(config('developer_api.scopes')) as $scope) {
            if ($scope !== 'ai:chat') {
                $this->assertArrayHasKey($scope, ApiKeyService::availableScopes(), "{$scope} must stay grantable");
            }
        }
    }

    public function test_the_portal_does_not_offer_the_scope_when_creating_a_key(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);

        $this->actingAsVendor($vendor)->get("/developers/applications/{$app->id}")
            ->assertOk()
            ->assertSee('services:read')
            ->assertDontSee('ai:chat');
    }

    public function test_creating_a_key_with_the_scope_is_rejected_and_creates_nothing(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);

        $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys", $this->payload(['ai:chat']))
            ->assertSessionHasErrors('scopes.0');

        $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys", $this->payload(['services:read', 'ai:chat']))
            ->assertSessionHasErrors('scopes.1');

        $this->assertSame(0, DeveloperApiKey::count());
    }

    public function test_other_scopes_still_create_keys_normally(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);

        $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys", $this->payload(['services:read', 'products:read']))
            ->assertOk();

        $this->assertSame(['services:read', 'products:read'], DeveloperApiKey::firstOrFail()->scopes);
    }

    public function test_the_service_layer_refuses_the_scope_too(): void
    {
        $app = $this->makeApp();

        $this->expectException(ApiException::class);
        app(ApiKeyService::class)->create($app, 'k', ['services:read', 'ai:chat']);
    }

    public function test_an_existing_key_keeps_its_stored_scopes_and_keeps_working(): void
    {
        [$key, $plain] = $this->legacyKey(['services:read', 'ai:chat']);

        $this->getJson('/api/v1/networks', $this->auth($plain))->assertOk();

        $key = $key->fresh();
        $this->assertSame(['services:read', 'ai:chat'], $key->scopes, 'stored scopes are untouched');
        $this->assertNull($key->revoked_at, 'existing keys are not revoked');
        $this->assertTrue($key->hasScope('ai:chat'), 'the stored scope is preserved as-is (it simply has no endpoint)');
    }

    public function test_rotating_a_legacy_key_does_not_carry_the_unavailable_scope_forward(): void
    {
        [$old] = $this->legacyKey(['services:read', 'products:read', 'ai:chat']);

        $new = app(ApiKeyService::class)->rotate($old)['key'];

        $this->assertSame(['services:read', 'products:read'], $new->scopes);
        $this->assertNotNull($old->fresh()->revoked_at, 'rotation still revokes the old key');
        $this->assertSame(['services:read', 'products:read', 'ai:chat'], $old->fresh()->scopes, 'the old key record is not rewritten');
    }

    public function test_a_key_that_only_had_the_unavailable_scope_cannot_be_rotated_and_stays_active(): void
    {
        [$old] = $this->legacyKey(['ai:chat']);

        try {
            app(ApiKeyService::class)->rotate($old);
            $this->fail('Expected rotation to be refused.');
        } catch (ApiException $e) {
            $this->assertSame('ROTATION_NOT_POSSIBLE', $e->errorCode);
            $this->assertStringContainsString('remains active', $e->getMessage());
            $this->assertStringContainsString('revoke the original explicitly', $e->getMessage());
        }

        $this->assertNull($old->fresh()->revoked_at, 'a refused rotation leaves the existing key untouched');
        $this->assertSame(['ai:chat'], $old->fresh()->scopes, 'and does not rewrite its scopes');
        $this->assertSame(1, DeveloperApiKey::count(), 'no replacement key was issued');
    }

    public function test_rotating_a_key_with_ordinary_scopes_preserves_exactly_those_scopes(): void
    {
        $app = $this->makeApp();
        $scopes = ['services:read', 'products:read', 'orders:read'];
        [$old] = $this->makeKey($app, $scopes);

        $issued = app(ApiKeyService::class)->rotate($old);

        $this->assertSame($scopes, $issued['key']->scopes);
        $this->assertSame($old->id, $issued['key']->rotated_from_id);
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNull($issued['key']->revoked_at);
    }

    public function test_only_the_unavailable_scope_is_dropped_everything_else_is_carried_or_still_validated(): void
    {
        [$old] = $this->legacyKey(['ai:chat', 'services:read']);
        $this->assertSame(['services:read'], app(ApiKeyService::class)->rotate($old)['key']->scopes);

        // A scope that is NOT merely unavailable but unknown is not silently dropped: the rotation is refused
        // and the key stays active, exactly as before this change.
        [$odd] = $this->legacyKey(['services:read', 'no:such-scope']);
        try {
            app(ApiKeyService::class)->rotate($odd);
            $this->fail('Expected rotation to be refused.');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_SCOPES', $e->errorCode);
        }
        $this->assertNull($odd->fresh()->revoked_at);
        $this->assertSame(1, DeveloperApiKey::query()->where('rotated_from_id', $odd->id)->count() + 1, 'no replacement was issued');
    }

    public function test_a_refused_rotation_in_the_portal_explains_that_the_original_key_stays_active(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        [$key, $plain] = $this->makeKey($app, ['services:read'], null, 'production');
        DB::table('developer_api_keys')->where('id', $key->id)->update(['scopes' => json_encode(['ai:chat'])]);

        $response = $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys/{$key->id}/rotate");

        $response->assertSessionHasErrors('key');
        $message = session('errors')->first('key');
        $this->assertStringContainsString('remains active', $message);
        $this->assertStringContainsString('revoke', $message);
        $this->assertSame(1, substr_count($message, 'remains active'), 'the explanation is not duplicated');

        $key = $key->fresh();
        $this->assertNull($key->revoked_at, 'a refused rotation must never silently revoke the key');
        $this->assertSame(['ai:chat'], $key->scopes);
        $this->assertSame(1, DeveloperApiKey::count());
        $this->getJson('/api/v1/networks', $this->auth($plain))->assertStatus(403); // active, but its only scope is inert
        $this->assertNull(DB::table('developer_api_keys')->where('id', $key->id)->value('revoked_at'));
    }

    public function test_a_rotation_refused_for_another_reason_also_leaves_the_key_active_and_says_so(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        [$key] = $this->makeKey($app, ['services:read'], null, 'production');
        DB::table('developer_api_keys')->where('id', $key->id)->update(['scopes' => json_encode(['services:read', 'no:such-scope'])]);

        // The service refuses with its own message (no "remains active" wording)...
        try {
            app(ApiKeyService::class)->rotate($key->fresh());
            $this->fail('Expected rotation to be refused.');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('remains active', $e->getMessage());
        }

        // ...and the portal adds the explanation for EVERY refusal reason.
        $this->actingAsVendor($vendor)->post("/developers/applications/{$app->id}/keys/{$key->id}/rotate")->assertSessionHasErrors('key');
        $this->assertStringContainsString('remains active', session('errors')->first('key'));
        $this->assertNull($key->fresh()->revoked_at, 'a failed rotation rolls back: the old key is not revoked');
        $this->assertSame(1, DeveloperApiKey::count());
    }

    public function test_a_suspended_application_cannot_rotate_and_its_key_is_not_revoked_by_the_attempt(): void
    {
        $app = $this->makeApp();
        [$key] = $this->makeKey($app, ['services:read'], null, 'production');
        $app->forceFill(['status' => 'suspended'])->save();

        try {
            app(ApiKeyService::class)->rotate($key->fresh());
            $this->fail('Expected rotation to be refused.');
        } catch (ApiException) {
        }

        $this->assertNull($key->fresh()->revoked_at, 'the transaction rolled back: no silent revoke');
        $this->assertSame(1, DeveloperApiKey::count());
    }
}
