<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\SandboxOrder;
use App\Services\DeveloperApi\ApiKeyService;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * The dedicated `sandbox:simulate` scope: required for every /sandbox/* endpoint, grantable only to test keys,
 * independent of `payments:initiate`, and never able to reach a real gateway or fulfilment provider.
 */
class SandboxScopeTest extends SandboxTestCase
{
    private const FLOW = ['quotes:create', 'orders:create', 'orders:read', 'payments:initiate'];

    /** A sandbox world whose key holds exactly $scopes, plus a sandbox order created with an all-scope key. */
    private function worldWith(array $scopes, bool $pay = true): array
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        if ($pay) {
            $this->startPayment($w['key'], $id)->assertSuccessful(); // simulation needs a started (pending) payment
        }
        [, $limited] = $this->makeKey($w['app'], $scopes, null, 'sandbox');

        return $w + ['orderId' => $id, 'limited' => $limited, 'baseline' => SandboxOrder::firstOrFail()->status];
    }

    // ── missing / present scope ──────────────────────────────────────────────

    public function test_simulation_requires_the_sandbox_simulate_scope(): void
    {
        $w = $this->worldWith(self::FLOW); // has payments:initiate, NOT sandbox:simulate

        $this->simulate($w['limited'], $w['orderId'], ['event' => 'payment.succeeded'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MISSING_SCOPE')
            ->assertJsonPath('error.details.required_scope', 'sandbox:simulate');

        $this->assertSame($w['baseline'], SandboxOrder::first()->status, 'a refused simulation must not change the order');
    }

    public function test_the_scenario_listing_requires_the_sandbox_simulate_scope(): void
    {
        $w = $this->worldWith(self::FLOW);

        $this->getJson('/api/v1/sandbox/simulations', $this->auth($w['limited']))
            ->assertStatus(403)->assertJsonPath('error.code', 'MISSING_SCOPE');
    }

    public function test_a_sandbox_key_with_the_scope_can_simulate_and_list(): void
    {
        $w = $this->worldWith(['sandbox:simulate']); // simulate scope ALONE is enough for these endpoints

        $this->getJson('/api/v1/sandbox/simulations', $this->auth($w['limited']))->assertOk();
        $this->simulate($w['limited'], $w['orderId'], ['scenario' => 'success'])
            ->assertOk()->assertJsonPath('data.simulated', true)->assertJsonPath('data.environment', 'sandbox');

        $this->assertSame('completed', SandboxOrder::first()->status);
    }

    public function test_the_scope_does_not_unlock_the_normal_endpoints_and_payments_initiate_stays_separate(): void
    {
        $w = $this->worldWith(['sandbox:simulate'], false);

        // Simulation scope does not imply starting a payment...
        $this->startPayment($w['limited'], $w['orderId'])->assertStatus(403)->assertJsonPath('error.code', 'MISSING_SCOPE');

        // ...and payments:initiate (the other direction) still starts a sandbox payment.
        [, $payer] = $this->makeKey($w['app'], ['payments:initiate'], null, 'sandbox');
        $this->startPayment($payer, $w['orderId'])->assertSuccessful();
    }

    // ── production keys ──────────────────────────────────────────────────────

    public function test_a_production_key_can_never_be_issued_the_scope(): void
    {
        $w = $this->sandboxWorld();

        try {
            app(ApiKeyService::class)->create($w['app'], 'bad', ['services:read', 'sandbox:simulate'], null, null, 'production');
            $this->fail('A production key was issued with a sandbox-only scope.');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_SCOPES', $e->errorCode);
        }

        $this->assertSame(0, DeveloperApiKey::where('environment', 'production')->count());
    }

    public function test_the_portal_refuses_the_scope_on_a_production_key_and_accepts_it_on_a_sandbox_key(): void
    {
        $w = $this->sandboxWorld();
        $this->actingAs($w['vendor'], 'vendor');
        $url = "/developers/applications/{$w['app']->id}/keys";
        $base = ['name' => 'k', 'scopes' => ['services:read', 'sandbox:simulate'], 'expires_in_days' => 30];

        $before = DeveloperApiKey::count();
        $this->post($url, $base + ['environment' => 'production'])->assertSessionHasErrors('scopes');
        $this->assertSame($before, DeveloperApiKey::count());

        $this->post($url, $base + ['environment' => 'sandbox'])->assertSessionDoesntHaveErrors();
        $this->assertSame($before + 1, DeveloperApiKey::count());
    }

    public function test_a_production_key_cannot_simulate_even_if_the_scope_is_forced_into_the_database(): void
    {
        $w = $this->worldWith(self::FLOW);
        $live = $this->liveKey($w['app']);
        // Simulate a hand-edited / legacy row: the scope list contains sandbox:simulate on a live key.
        $liveRow = DeveloperApiKey::where('environment', 'production')->firstOrFail();
        DB::table('developer_api_keys')->where('id', $liveRow->id)->update(['scopes' => json_encode(['sandbox:simulate', 'payments:initiate'])]);

        $this->assertFalse($liveRow->fresh()->hasScope('sandbox:simulate'), 'a sandbox-only scope never counts on a production key');

        foreach ([
            fn () => $this->simulate($live, $w['orderId'], ['scenario' => 'success']),
            fn () => $this->getJson('/api/v1/sandbox/simulations', $this->auth($live)),
        ] as $call) {
            $call()->assertStatus(403)->assertJsonPath('error.code', 'ENVIRONMENT_MISMATCH');
        }
        $this->assertSame($w['baseline'], SandboxOrder::first()->status);
    }

    public function test_a_normal_production_key_is_refused_by_environment_before_scope(): void
    {
        $w = $this->worldWith(self::FLOW);
        $live = $this->liveKey($w['app']);

        $this->simulate($live, $w['orderId'], ['scenario' => 'success'])
            ->assertStatus(403)->assertJsonPath('error.code', 'ENVIRONMENT_MISMATCH');
    }

    // ── cross-vendor ─────────────────────────────────────────────────────────

    public function test_another_vendors_sandbox_key_with_the_scope_cannot_simulate_my_order(): void
    {
        $mine = $this->worldWith(['sandbox:simulate']);

        $other = $this->sandboxWorld(); // a different approved vendor + app, all scopes incl. sandbox:simulate
        $this->assertNotSame($mine['vendor']->id, $other['vendor']->id);

        $this->simulate($other['key'], $mine['orderId'], ['scenario' => 'success'])
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame($mine['baseline'], SandboxOrder::first()->status, 'the other vendor must not change my order');
    }

    // ── never touches real gateways / fulfilment ─────────────────────────────

    public function test_simulation_with_the_scope_never_touches_gateways_fulfilment_or_notifications(): void
    {
        $w = $this->worldWith(['sandbox:simulate']);
        $before = $this->productionFingerprint($w['vendor']);
        $this->forbidProductionServices();

        $this->simulate($w['limited'], $w['orderId'], ['scenario' => 'success'])->assertOk();
        $this->simulate($w['limited'], $w['orderId'], ['scenario' => 'success'])->assertOk(); // idempotent replay

        $this->assertNoProductionServiceTouched();
        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
    }

    // ── catalogue of scopes ──────────────────────────────────────────────────

    public function test_the_scope_is_declared_and_marked_sandbox_only(): void
    {
        $this->assertArrayHasKey('sandbox:simulate', config('developer_api.scopes'));
        $this->assertSame(['sandbox:simulate'], config('developer_api.sandbox_only_scopes'));
    }
}
