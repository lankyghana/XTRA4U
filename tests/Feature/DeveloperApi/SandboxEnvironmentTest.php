<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\SandboxOrder;
use App\Models\Order;
use App\Services\DeveloperApi\ApiKeyService;
use App\Support\DeveloperApi\ApiEnvironment;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The API key — and only the API key — decides the environment, and the two never cross. */
class SandboxEnvironmentTest extends SandboxTestCase
{
    // ── authentication of sandbox keys ──────────────────────────────────────

    public function test_a_valid_sandbox_key_is_served_by_the_sandbox(): void
    {
        $w = $this->sandboxWorld();

        $this->getJson('/api/v1/products', $this->auth($w['key']))
            ->assertOk()
            ->assertJsonPath('meta.environment', 'sandbox')
            ->assertJsonPath('data.0.id', $w['offer']);
    }

    public function test_production_responses_carry_no_sandbox_markers(): void
    {
        $app = $this->makeApp();

        $this->getJson('/api/v1/services', $this->auth($this->liveKey($app)))
            ->assertOk()
            ->assertJsonMissingPath('meta.environment');
    }

    public function test_invalid_sandbox_key_is_rejected(): void
    {
        $this->getJson('/api/v1/products', $this->auth('xt_test_aaaaaaaa_'.str_repeat('b', 40)))
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_API_KEY');
    }

    public function test_revoked_sandbox_key_is_rejected(): void
    {
        $app = $this->makeApp();
        [$key, $plain] = $this->makeKey($app, null, null, 'sandbox');
        app(ApiKeyService::class)->revoke($key);

        $this->getJson('/api/v1/products', $this->auth($plain))
            ->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_REVOKED');
        $this->postJson('/api/v1/quotes', [], $this->auth($plain))->assertStatus(401);
    }

    public function test_expired_sandbox_key_is_rejected(): void
    {
        [, $plain] = $this->makeKey($this->makeApp(), null, now()->subMinute(), 'sandbox');

        $this->getJson('/api/v1/products', $this->auth($plain))
            ->assertStatus(401)->assertJsonPath('error.code', 'API_KEY_EXPIRED');
    }

    public function test_a_suspended_vendor_cannot_keep_using_sandbox_keys(): void
    {
        $w = $this->sandboxWorld();
        $orderId = $this->sandboxOrderId($w['key'], $w['offer']);

        // Raw update: no model events, so no cleanup has run. Access must stop on the very next request anyway.
        DB::table('vendors')->where('id', $w['vendor']->id)->update(['is_approved' => false]);

        foreach ([
            fn () => $this->getJson('/api/v1/products', $this->auth($w['key'])),
            fn () => $this->quote($w['key'], $w['offer']),
            fn () => $this->getJson("/api/v1/orders/{$orderId}", $this->auth($w['key'])),
            fn () => $this->startPayment($w['key'], $orderId),
            fn () => $this->simulate($w['key'], $orderId, ['event' => 'payment.succeeded']),
        ] as $call) {
            $call()->assertStatus(403)->assertJsonPath('error.code', 'VENDOR_NOT_APPROVED');
        }

        $this->assertSame('awaiting_payment', SandboxOrder::first()->status, 'a refused request must change nothing');
    }

    // ── the key decides; nothing in the request can override it ─────────────

    public function test_the_environment_cannot_be_overridden_by_query_header_or_body(): void
    {
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);
        $this->paystack();

        // A sandbox key that "asks" for production stays in the sandbox.
        $quoteRef = $this->postJson('/api/v1/quotes?environment=production&env=live', [
            'product_id' => $w['offer'], 'recipient_phone' => '0244000000', 'environment' => 'production', 'sandbox' => false,
        ], $this->auth($w['key']) + ['X-Environment' => 'production', 'X-API-Environment' => 'production', 'X-Sandbox' => '0'])
            ->assertCreated()->assertJsonPath('data.environment', 'sandbox')->json('data.reference');
        $this->assertStringStartsWith('sbx_qt_', $quoteRef);

        $this->postJson('/api/v1/orders?environment=production', ['quote_reference' => $quoteRef, 'environment' => 'production'],
            $this->auth($w['key']) + ['Idempotency-Key' => 'o1', 'X-Environment' => 'production'])
            ->assertCreated()->assertJsonPath('data.environment', 'sandbox');

        // ...and a production key that "asks" for the sandbox stays in production.
        $live = $this->postJson('/api/v1/quotes?environment=sandbox', [
            'product_id' => $w['offer'], 'recipient_phone' => '0244000000', 'environment' => 'sandbox',
        ], $this->auth($live) + ['X-Environment' => 'sandbox'])->assertCreated();
        $this->assertStringStartsWith('qt_', $live->json('data.reference'));
        $live->assertJsonMissingPath('data.environment');

        $this->assertSame(1, SandboxOrder::count());
        $this->assertSame(0, Order::count());
    }

    public function test_an_unknown_or_missing_environment_fails_closed(): void
    {
        foreach ([null, '', 'staging', 'PRODUCTION', 'live', 0] as $bad) {
            $request = Request::create('/api/v1/orders', 'POST');
            if ($bad !== null) {
                $request->attributes->set('api.environment', $bad);
            }

            try {
                ApiEnvironment::of($request);
                $this->fail('An undeterminable environment was accepted: '.var_export($bad, true));
            } catch (ApiException $e) {
                $this->assertSame('ENVIRONMENT_MISMATCH', $e->errorCode);
                $this->assertSame(403, $e->httpStatus);
            }
        }

        foreach (['sandbox', 'production'] as $good) {
            $request = Request::create('/api/v1/orders', 'POST');
            $request->attributes->set('api.environment', $good);
            $this->assertSame($good, ApiEnvironment::of($request));
        }
    }

    // ── production keys are kept out of sandbox-only routes ──────────────────

    public function test_a_production_key_cannot_use_the_simulation_routes(): void
    {
        $w = $this->sandboxWorld();
        $orderId = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $orderId)->assertStatus(202);
        $live = $this->liveKey($w['app']);

        // Even with the right order id, a malformed id, or no body at all: 403 from the environment gate.
        foreach ([$orderId, 'ord_'.str_repeat('a', 26), 'garbage', 'sbx_ord_'.str_repeat('z', 26)] as $id) {
            $this->simulate($live, $id, ['event' => 'payment.succeeded'])
                ->assertStatus(403)->assertJsonPath('error.code', 'ENVIRONMENT_MISMATCH');
        }
        $this->getJson('/api/v1/sandbox/simulations', $this->auth($live))->assertStatus(403);

        $order = SandboxOrder::firstOrFail();
        $this->assertSame('pending', $order->payment_status, 'the production key must not have changed the sandbox order');
    }

    public function test_simulation_routes_require_authentication_and_scope(): void
    {
        $w = $this->sandboxWorld(10.0, ['quotes:create', 'orders:create', 'orders:read']);
        $orderId = $this->sandboxOrderId($w['key'], $w['offer']);

        $this->postJson("/api/v1/sandbox/orders/{$orderId}/simulate", ['event' => 'payment.succeeded'])->assertStatus(401);
        $this->simulate($w['key'], $orderId, ['event' => 'payment.succeeded'])
            ->assertStatus(403)->assertJsonPath('error.code', 'MISSING_SCOPE');
    }

    // ── cross-environment isolation of orders and transactions ───────────────

    public function test_test_keys_cannot_read_production_orders_or_transactions_and_vice_versa(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        // One production order (with a payment reference) and one sandbox order + payment.
        $prodQuote = $this->quote($live, $w['offer'])->assertCreated()->json('data.reference');
        $prodId = $this->order($live, $prodQuote, 'p1')->assertCreated()->json('data.id');
        $this->assertStringStartsWith('ord_', $prodId);
        Order::query()->where('developer_order_ref', $prodId)->update(['payment_reference' => 'PROD-REF-0001', 'payment_status' => 'paid']);

        $sbxId = $this->sandboxOrderId($w['key'], $w['offer'], 's1');
        $sbxRef = $this->startPayment($w['key'], $sbxId)->assertStatus(202)->json('data.reference');

        // Sandbox key -> production ids/references: not found. No fallback to production.
        $this->getJson("/api/v1/orders/{$prodId}", $this->auth($w['key']))->assertNotFound();
        $this->getJson("/api/v1/orders/{$prodId}/status", $this->auth($w['key']))->assertNotFound();
        $this->getJson('/api/v1/transactions/PROD-REF-0001', $this->auth($w['key']))->assertNotFound();
        $this->startPayment($w['key'], $prodId)->assertNotFound();

        // Production key -> sandbox ids/references: not found.
        $this->getJson("/api/v1/orders/{$sbxId}", $this->auth($live))->assertNotFound();
        $this->getJson("/api/v1/orders/{$sbxId}/status", $this->auth($live))->assertNotFound();
        $this->getJson("/api/v1/transactions/{$sbxRef}", $this->auth($live))->assertNotFound();
        $this->startPayment($live, $sbxId)->assertNotFound();

        // Each environment still sees its own.
        $this->getJson("/api/v1/orders/{$sbxId}", $this->auth($w['key']))->assertOk()->assertJsonPath('data.environment', 'sandbox');
        $this->getJson("/api/v1/transactions/{$sbxRef}", $this->auth($w['key']))->assertOk();
        $this->getJson("/api/v1/orders/{$prodId}", $this->auth($live))->assertOk();
    }

    public function test_sandbox_ids_use_their_own_prefixes_and_never_collide_with_production(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        $prodQuote = $this->quote($live, $w['offer'])->json('data.reference');
        $sbxQuote = $this->quote($w['key'], $w['offer'])->json('data.reference');
        $sbxOrder = $this->order($w['key'], $sbxQuote)->json('data.id');
        $sbxPay = $this->startPayment($w['key'], $sbxOrder)->json('data.reference');

        $this->assertMatchesRegularExpression('/^qt_[a-z0-9]{26}$/', $prodQuote);
        $this->assertMatchesRegularExpression('/^sbx_qt_[a-z0-9]{26}$/', $sbxQuote);
        $this->assertMatchesRegularExpression('/^sbx_ord_[a-z0-9]{26}$/', $sbxOrder);
        $this->assertMatchesRegularExpression('/^sbx_pay_[a-z0-9]{26}$/', $sbxPay);
    }
}
