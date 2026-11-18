<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApiRequest;
use App\Models\Developer\DeveloperApplication;
use App\Services\DeveloperApi\ApiKeyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ApiAuthenticationTest extends DeveloperApiTestCase
{
    public function test_health_is_public_and_uses_the_standard_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', 'v1')
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['request_id']])
            ->assertHeader('X-Request-Id');
    }

    public function test_valid_key_is_accepted(): void
    {
        [, $plain] = $this->makeKey($this->makeApp());

        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_missing_key_is_rejected_with_error_envelope(): void
    {
        $this->getJson('/api/v1/services')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_API_KEY')
            ->assertJsonStructure(['success', 'message', 'error' => ['code', 'details'], 'meta' => ['request_id']]);
    }

    public function test_invalid_and_malformed_keys_are_rejected(): void
    {
        [, $plain] = $this->makeKey($this->makeApp());

        $tampered = substr($plain, 0, -1).(substr($plain, -1) === 'a' ? 'b' : 'a');

        $this->getJson('/api/v1/services', $this->auth($tampered))->assertStatus(401)->assertJsonPath('error.code', 'INVALID_API_KEY');
        $this->getJson('/api/v1/services', $this->auth('not-a-key'))->assertStatus(401)->assertJsonPath('error.code', 'INVALID_API_KEY');
    }

    public function test_key_may_also_be_sent_in_x_api_key_header(): void
    {
        [, $plain] = $this->makeKey($this->makeApp());

        $this->getJson('/api/v1/services', ['X-API-Key' => $plain])->assertOk();
    }

    public function test_revoked_key_is_rejected(): void
    {
        [$key, $plain] = $this->makeKey($this->makeApp());
        app(ApiKeyService::class)->revoke($key);

        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_REVOKED');
    }

    public function test_expired_key_is_rejected(): void
    {
        [, $plain] = $this->makeKey($this->makeApp(), null, now()->subMinute());

        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_EXPIRED');
    }

    public function test_missing_scope_is_rejected_per_endpoint(): void
    {
        [, $plain] = $this->makeKey($this->makeApp(), ['services:read']);

        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
        $this->getJson('/api/v1/products', $this->auth($plain))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MISSING_SCOPE')
            ->assertJsonPath('error.details.required_scope', 'products:read');
    }

    public function test_suspended_application_is_rejected(): void
    {
        $app = $this->makeApp();
        [, $plain] = $this->makeKey($app);
        $app->forceFill(['status' => DeveloperApplication::STATUS_SUSPENDED])->save();

        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_INACTIVE');
    }

    // ----- The vendor's approval is the gate: checked LIVE on every protected request -----

    public function test_unapproved_vendor_is_denied_on_the_very_next_request_without_any_cleanup_running(): void
    {
        $vendor = $this->vendor();
        $app = $this->makeApp($vendor);
        [, $plain] = $this->makeKey($app);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();

        // Raw query-builder write: no model events, no observer. Only the live check can catch this.
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(403)->assertJsonPath('error.code', 'VENDOR_NOT_APPROVED');
    }

    public function test_every_protected_endpoint_denies_an_unapproved_vendor(): void
    {
        $vendor = $this->vendor();
        [, $plain] = $this->makeKey($this->makeApp($vendor));
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $ord = 'ord_'.str_repeat('a', 26);
        foreach ([
            ['GET', '/api/v1/services'], ['GET', '/api/v1/networks'], ['GET', '/api/v1/products'],
            ['GET', '/api/v1/products/own_1'], ['POST', '/api/v1/quotes'], ['POST', '/api/v1/orders'],
            ['GET', "/api/v1/orders/{$ord}"], ['POST', "/api/v1/orders/{$ord}/payments"], ['GET', "/api/v1/orders/{$ord}/status"],
            ['GET', '/api/v1/transactions/REF-123456'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, [], $this->auth($plain))
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'VENDOR_NOT_APPROVED');
        }
    }

    public function test_reapproval_does_not_resurrect_a_key_that_was_withdrawn(): void
    {
        $vendor = $this->vendor();
        [$key, $plain] = $this->makeKey($this->makeApp($vendor));

        $vendor->update(['is_approved' => false]);   // observer revokes
        $vendor->update(['is_approved' => true]);

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_REVOKED');
    }

    public function test_deleted_vendor_is_denied(): void
    {
        $vendor = $this->vendor();
        [, $plain] = $this->makeKey($this->makeApp($vendor));
        DB::table('vendors')->where('id', $vendor->id)->delete();

        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(403);
    }

    public function test_keys_cannot_be_minted_for_an_unapproved_vendor_in_either_environment(): void
    {
        $vendor = $this->vendor();
        $app = $this->makeApp($vendor);
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        foreach (['sandbox', 'production'] as $env) {
            try {
                app(ApiKeyService::class)->create($app->fresh(), 'x', ['services:read'], null, null, $env);
                $this->fail("A {$env} key was minted for an unapproved vendor.");
            } catch (\App\Support\DeveloperApi\ApiException $e) {
                $this->assertSame('VENDOR_NOT_APPROVED', $e->errorCode);
            }
        }

        $this->assertSame(0, DB::table('developer_api_keys')->count());
    }

    public function test_production_and_sandbox_keys_need_no_separate_application_approval(): void
    {
        $app = $this->makeApp();

        [, $live] = $this->makeKey($app, null, null, 'production');
        [, $test] = $this->makeKey($app, null, null, 'sandbox');

        $this->getJson('/api/v1/services', $this->auth($live))->assertOk();
        // Sandbox keys are served by the sandbox engine (Milestone 4) and say so in the envelope.
        $this->getJson('/api/v1/services', $this->auth($test))
            ->assertOk()->assertJsonPath('meta.environment', 'sandbox');
    }

    public function test_secret_is_never_stored_and_only_the_hash_is(): void
    {
        [$key, $plain] = $this->makeKey($this->makeApp());

        $this->assertStringStartsWith('xt_live_'.$key->prefix.'_', $plain);
        $this->assertSame(hash('sha256', $plain), $key->key_hash);

        $secret = substr($plain, -40);
        foreach (DB::table('developer_api_keys')->get() as $row) {
            $this->assertStringNotContainsString($secret, json_encode($row));
        }

        // Serialising a key never exposes the hash.
        $this->assertArrayNotHasKey('key_hash', $key->toArray());
    }

    public function test_sandbox_keys_are_marked_test_and_production_keys_live(): void
    {
        [, $sandbox] = $this->makeKey($this->makeApp(), null, null, 'sandbox');
        [, $live] = $this->makeKey($this->makeApp());

        $this->assertStringStartsWith('xt_test_', $sandbox);
        $this->assertStringStartsWith('xt_live_', $live);
    }

    public function test_rotation_issues_a_new_key_and_revokes_the_old_one(): void
    {
        $app = $this->makeApp();
        [$old, $oldPlain] = $this->makeKey($app, ['services:read']);

        $new = app(ApiKeyService::class)->rotate($old);

        $this->getJson('/api/v1/services', $this->auth($oldPlain))->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_REVOKED');
        $this->getJson('/api/v1/services', $this->auth($new['plaintext']))->assertOk();
        $this->assertSame(['services:read'], $new['key']->scopes);
    }

    public function test_unknown_scopes_cannot_be_granted(): void
    {
        $this->expectException(\App\Support\DeveloperApi\ApiException::class);
        app(ApiKeyService::class)->create($this->makeApp(), 'x', ['services:read', 'admin:everything']);
    }

    public function test_rate_limit_is_enforced_per_application_with_headers(): void
    {
        $app = $this->makeApp(null, ['rate_limit_per_minute' => 3]);
        [, $plain] = $this->makeKey($app);
        RateLimiter::clear('api-rate:'.$app->id);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/services', $this->auth($plain))->assertOk()->assertHeader('X-RateLimit-Limit', '3');
        }

        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_plan_limit_is_used_when_there_is_no_application_override(): void
    {
        $app = $this->makeApp();
        $app->forceFill(['plan_id' => $this->plan(['requests_per_minute' => 2])->id])->save();
        [, $plain] = $this->makeKey($app);
        RateLimiter::clear('api-rate:'.$app->id);

        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(429);
    }

    public function test_rotating_keys_does_not_reset_the_application_quota(): void
    {
        $app = $this->makeApp(null, ['rate_limit_per_minute' => 1]);
        [$k1, $p1] = $this->makeKey($app);
        RateLimiter::clear('api-rate:'.$app->id);

        $this->getJson('/api/v1/services', $this->auth($p1))->assertOk();
        $new = app(ApiKeyService::class)->rotate($k1);
        $this->getJson('/api/v1/services', $this->auth($new['plaintext']))->assertStatus(429);
    }

    public function test_repeated_bad_keys_are_throttled_per_ip(): void
    {
        config(['developer_api.rate_limit.auth_failures_per_minute' => 3]);
        RateLimiter::clear('api-auth-fail:127.0.0.1');

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/services', $this->auth('xt_live_aaaaaaaa_'.str_repeat('b', 40)))->assertStatus(401);
        }

        $this->getJson('/api/v1/services', $this->auth('xt_live_aaaaaaaa_'.str_repeat('b', 40)))
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_requests_are_logged_without_secrets_or_query_strings(): void
    {
        $app = $this->makeApp();
        [, $plain] = $this->makeKey($app);

        $res = $this->getJson('/api/v1/products?network=MTN&q=secret-search', $this->auth($plain))->assertOk();

        $row = DeveloperApiRequest::query()->latest('id')->first();
        $this->assertSame($app->id, $row->application_id);
        $this->assertSame('GET', $row->method);
        $this->assertSame('/api/v1/products', $row->path);
        $this->assertSame(200, $row->status_code);
        $this->assertSame($res->headers->get('X-Request-Id'), $row->request_id);

        $dump = json_encode($row->getAttributes());
        $this->assertStringNotContainsString($plain, $dump);
        $this->assertStringNotContainsString('secret-search', $dump);
    }

    public function test_failed_auth_is_logged_with_error_code(): void
    {
        $this->getJson('/api/v1/services', $this->auth('nope'))->assertStatus(401);

        $row = DeveloperApiRequest::query()->latest('id')->first();
        $this->assertSame(401, $row->status_code);
        $this->assertSame('INVALID_API_KEY', $row->error_code);
        $this->assertNull($row->application_id);
    }

    public function test_sandbox_keys_cannot_reach_production_services(): void
    {
        [, $plain] = $this->makeKey($this->makeApp(), null, null, 'sandbox');

        // Served by the sandbox engine, not production: the envelope says so.
        $this->getJson('/api/v1/products', $this->auth($plain))
            ->assertOk()->assertJsonPath('meta.environment', 'sandbox');
        // An (invalid) order request is validated by the sandbox flow and creates nothing in production.
        $this->postJson('/api/v1/orders', [], $this->auth($plain) + ['Idempotency-Key' => 'k1'])
            ->assertStatus(422);

        $this->assertSame(0, \App\Models\Order::count());
    }

    public function test_unknown_internal_errors_never_leak_details(): void
    {
        $app = $this->makeApp();
        [, $plain] = $this->makeKey($app);

        $this->mock(\App\Services\DeveloperApi\PublicCatalogService::class)
            ->shouldReceive('services')->andThrow(new \RuntimeException('SQLSTATE secret table name'));

        $res = $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(500);
        $res->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
    }
}
