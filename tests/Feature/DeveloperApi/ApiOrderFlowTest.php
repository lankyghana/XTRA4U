<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

class ApiOrderFlowTest extends DeveloperApiTestCase
{
    private function setupSale(float $price = 10.0): array
    {
        $vendor = $this->vendor();
        $product = $this->product($vendor, $price);
        $app = $this->makeApp();
        [, $plain] = $this->makeKey($app);

        return [$vendor, $product, $app, $plain];
    }

    private function quote(string $plain, string $offerId, string $phone = '0244000000')
    {
        return $this->postJson('/api/v1/quotes', ['product_id' => $offerId, 'recipient_phone' => $phone], $this->auth($plain));
    }

    private function order(string $plain, string $quoteRef, string $key = 'idem-1', array $extra = [])
    {
        return $this->postJson('/api/v1/orders', ['quote_reference' => $quoteRef] + $extra, $this->auth($plain) + ['Idempotency-Key' => $key]);
    }

    public function test_quote_returns_the_authoritative_price_and_expiry(): void
    {
        [, $product, , $plain] = $this->setupSale(10);

        $res = $this->quote($plain, "own_{$product->id}")->assertCreated();

        $res->assertJsonPath('data.price', 10)
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.availability', 'available')
            ->assertJsonPath('data.network', 'MTN')
            ->assertJsonPath('data.recipient_phone', '0244000000');
        $this->assertStringStartsWith('qt_', $res->json('data.reference'));
        $this->assertNotNull($res->json('data.expires_at'));
    }

    public function test_client_cannot_influence_the_quoted_price(): void
    {
        [, $product, , $plain] = $this->setupSale(10);

        $res = $this->postJson('/api/v1/quotes', [
            'product_id' => "own_{$product->id}", 'recipient_phone' => '0244000000', 'price' => 0.01, 'amount' => 0.01,
        ], $this->auth($plain))->assertCreated();

        $res->assertJsonPath('data.price', 10);
    }

    public function test_reseller_quote_is_base_plus_markup(): void
    {
        $owner = $this->vendor();
        $reseller = $this->vendor();
        $l = $this->listing($this->product($owner, 10), $reseller, 3);
        [, $plain] = $this->makeKey($this->makeApp());

        $this->quote($plain, "rsl_{$l->id}")->assertCreated()->assertJsonPath('data.price', 13);
    }

    public function test_quote_rejects_invalid_phone_and_unavailable_offers(): void
    {
        [, $product, , $plain] = $this->setupSale();

        $this->quote($plain, "own_{$product->id}", '12345')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->quote($plain, 'own_99999')->assertStatus(404)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        $this->quote($plain, 'garbage')->assertStatus(404);
    }

    public function test_quotes_are_private_to_their_application(): void
    {
        [, $product, , $plainA] = $this->setupSale();
        [, $plainB] = $this->makeKey($this->makeApp());
        $ref = $this->quote($plainA, "own_{$product->id}")->json('data.reference');

        $this->order($plainB, $ref)->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
        $this->assertSame(0, Order::count());
    }

    public function test_order_is_created_from_a_valid_quote_with_frozen_pricing(): void
    {
        [$vendor, $product, $app, $plain] = $this->setupSale(10);
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');

        $res = $this->order($plain, $ref, 'idem-1', ['payer_network' => 'MTN'])->assertCreated();

        $res->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.amount', 10)
            ->assertJsonPath('data.payment.status', 'unpaid')
            ->assertJsonPath('data.fulfillment.status', 'not_started')
            ->assertJsonPath('data.seller.code', $vendor->vendor_code);

        $order = Order::first();
        $this->assertSame($app->id, (int) $order->developer_application_id);
        $this->assertSame($res->json('data.id'), $order->developer_order_ref);
        $this->assertSame('10.00', (string) $order->expected_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('Pending', $order->status);
        $this->assertSame($vendor->id, (int) $order->vendor_id);
        $this->assertSame('pending_verification', $order->payment_integrity_status);
        $this->assertNotNull(ApiQuote::first()->consumed_at);
    }

    public function test_reseller_order_records_owner_and_reseller_like_web_checkout(): void
    {
        $owner = $this->vendor();
        $reseller = $this->vendor();
        $l = $this->listing($this->product($owner, 10), $reseller, 3);
        [, $plain] = $this->makeKey($this->makeApp());
        $ref = $this->quote($plain, "rsl_{$l->id}")->json('data.reference');

        $this->order($plain, $ref)->assertCreated();

        $o = Order::first();
        $this->assertTrue((bool) $o->is_reseller_order);
        $this->assertSame($reseller->id, (int) $o->vendor_id);
        $this->assertSame($reseller->id, (int) $o->reseller_vendor_id);
        $this->assertSame($owner->id, (int) $o->owner_vendor_id);
        $this->assertSame('10.00', (string) $o->base_price);
        $this->assertSame('3.00', (string) $o->markup_price);
        $this->assertSame('13.00', (string) $o->expected_amount);
    }

    public function test_order_requires_an_idempotency_key(): void
    {
        [, $product, , $plain] = $this->setupSale();
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');

        $this->postJson('/api/v1/orders', ['quote_reference' => $ref], $this->auth($plain))
            ->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->assertSame(0, Order::count());
    }

    public function test_duplicate_idempotency_key_replays_the_same_order_without_creating_another(): void
    {
        [, $product, , $plain] = $this->setupSale();
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');

        $first = $this->order($plain, $ref, 'same-key')->assertCreated();
        $second = $this->order($plain, $ref, 'same-key')->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertJsonPath('meta.idempotent_replay', true);
        $this->assertSame(1, Order::count());
    }

    public function test_same_idempotency_key_for_a_different_quote_is_a_conflict(): void
    {
        [, $product, , $plain] = $this->setupSale();
        $q1 = $this->quote($plain, "own_{$product->id}")->json('data.reference');
        $q2 = $this->quote($plain, "own_{$product->id}")->json('data.reference');

        $this->order($plain, $q1, 'k')->assertCreated();
        $this->order($plain, $q2, 'k')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, Order::count());
    }

    public function test_idempotency_keys_are_scoped_per_application(): void
    {
        [, $product, , $plainA] = $this->setupSale();
        [, $plainB] = $this->makeKey($this->makeApp());
        $qa = $this->quote($plainA, "own_{$product->id}")->json('data.reference');
        $qb = $this->quote($plainB, "own_{$product->id}")->json('data.reference');

        $this->order($plainA, $qa, 'shared')->assertCreated();
        $this->order($plainB, $qb, 'shared')->assertCreated();   // not a replay of A's order

        $this->assertSame(2, Order::count());
    }

    public function test_a_quote_can_only_be_used_once(): void
    {
        [, $product, , $plain] = $this->setupSale();
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');

        $this->order($plain, $ref, 'k1')->assertCreated();
        $this->order($plain, $ref, 'k2')->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_ALREADY_USED');
        $this->assertSame(1, Order::count());
    }

    public function test_expired_quote_is_rejected(): void
    {
        [, $product, , $plain] = $this->setupSale();
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');
        ApiQuote::query()->update(['expires_at' => now()->subSecond()]);

        $this->order($plain, $ref)->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
        $this->assertSame(0, Order::count());
    }

    public function test_price_change_after_quote_is_refused_not_silently_charged(): void
    {
        [, $product, , $plain] = $this->setupSale(10);
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');
        $product->update(['price' => 25]);

        $this->order($plain, $ref)->assertStatus(409)
            ->assertJsonPath('error.code', 'QUOTE_PRICE_CHANGED')
            ->assertJsonPath('error.details.quoted_amount', '10.00')
            ->assertJsonPath('error.details.current_amount', '25.00');
        $this->assertSame(0, Order::count());
    }

    public function test_offer_withdrawn_after_quote_is_refused(): void
    {
        [, $product, , $plain] = $this->setupSale();
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');
        $product->update(['is_active' => false]);

        $this->order($plain, $ref)->assertStatus(409)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        $this->assertSame(0, Order::count());
    }

    public function test_vendor_bound_app_cannot_quote_or_order_another_vendors_offer(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $theirs = $this->product($other, 5);
        [, $plain] = $this->makeKey($this->makeApp('production', $mine));

        $this->quote($plain, "own_{$theirs->id}")->assertStatus(404)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        $this->assertSame(0, Order::count());
    }

    public function test_orders_are_invisible_to_other_applications(): void
    {
        [, $product, , $plainA] = $this->setupSale();
        [, $plainB] = $this->makeKey($this->makeApp());
        $ref = $this->quote($plainA, "own_{$product->id}")->json('data.reference');
        $orderId = $this->order($plainA, $ref)->json('data.id');

        $this->getJson("/api/v1/orders/{$orderId}", $this->auth($plainA))->assertOk();
        $this->getJson("/api/v1/orders/{$orderId}", $this->auth($plainB))->assertStatus(404);
        $this->getJson("/api/v1/orders/{$orderId}/status", $this->auth($plainB))->assertStatus(404);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plainB))->assertStatus(404);
    }

    public function test_web_orders_and_sequential_ids_are_never_reachable_through_the_api(): void
    {
        [$vendor, , , $plain] = $this->setupSale();
        $web = Order::create([
            'recipient_phone_number' => '0244000000', 'mobile_money_number' => '0244000000',
            'service_purchased' => 'X', 'amount_paid' => 5, 'vendor_id' => $vendor->id,
            'status' => 'Pending', 'payment_status' => 'unpaid', 'payment_reference' => 'WEB-REF-0001',
        ]);

        $this->getJson("/api/v1/orders/{$web->id}", $this->auth($plain))->assertStatus(404);
        $this->getJson('/api/v1/transactions/WEB-REF-0001', $this->auth($plain))->assertStatus(404);
    }

    public function test_order_endpoints_require_their_own_scopes(): void
    {
        [, $product, $app, $plain] = $this->setupSale();
        [, $limited] = $this->makeKey($app, ['quotes:create']);
        $ref = $this->quote($limited, "own_{$product->id}")->assertCreated()->json('data.reference');

        $this->order($limited, $ref)->assertStatus(403)->assertJsonPath('error.details.required_scope', 'orders:create');
    }

    // ------------------------------------------------------------- payments

    private function orderWithPayment(): array
    {
        $this->paystack();
        $this->fakePaystackInit();
        [, $product, $app, $plain] = $this->setupSale(10);
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');
        $orderId = $this->order($plain, $ref)->json('data.id');

        return [$orderId, $plain, $app];
    }

    public function test_payment_initiation_uses_the_existing_gateway_and_returns_a_redirect(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();

        $res = $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);

        $res->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.authorization_url', 'https://paystack.example/redirect')
            ->assertJsonPath('data.amount', 10);

        $order = Order::first();
        $this->assertNotEmpty($order->payment_reference);
        $this->assertSame($order->payment_reference, $res->json('data.reference'));
        $this->assertSame('unpaid', $order->payment_status, 'initiation must never mark an order paid');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/transaction/initialize'));
    }

    public function test_payment_cannot_be_initiated_twice_and_never_double_charges(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))
            ->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_ALREADY_INITIATED');

        Http::assertSentCount(1);
    }

    public function test_payment_scope_is_required(): void
    {
        [$orderId, , $app] = $this->orderWithPayment();
        [, $noPay] = $this->makeKey($app, ['orders:read', 'orders:create']);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($noPay))
            ->assertStatus(403)->assertJsonPath('error.code', 'MISSING_SCOPE');
    }

    public function test_gateway_failure_during_initiation_is_reported_without_leaking_details(): void
    {
        $this->paystack();
        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'secret gateway detail'], 500)]);
        [, $product, , $plain] = $this->setupSale();
        $ref = $this->quote($plain, "own_{$product->id}")->json('data.reference');
        $orderId = $this->order($plain, $ref)->json('data.id');

        $res = $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(502);

        $res->assertJsonPath('error.code', 'PAYMENT_INITIATION_FAILED');
        $this->assertStringNotContainsString('secret gateway detail', $res->getContent());
        $this->assertSame('unpaid', Order::first()->payment_status);
    }

    public function test_status_does_not_become_paid_just_because_the_client_asks(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200)]);

        $this->getJson("/api/v1/orders/{$orderId}/status", $this->auth($plain))
            ->assertOk()->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.status', 'payment_pending');

        // Client-supplied "paid" claims are ignored: there is no such input.
        $this->postJson("/api/v1/orders/{$orderId}/payments", ['status' => 'paid', 'payment_status' => 'paid'], $this->auth($plain))->assertStatus(409);
        $this->assertSame('unpaid', Order::first()->payment_status);
    }

    public function test_confirmed_gateway_payment_is_settled_by_the_existing_pipeline(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'amount' => 1000, 'currency' => 'GHS'],
        ], 200)]);

        $res = $this->getJson("/api/v1/orders/{$orderId}/status", $this->auth($plain))->assertOk();

        $res->assertJsonPath('data.payment.status', 'paid');
        $order = Order::first();
        $this->assertContains($order->payment_status, ['paid', 'completed']);
        $this->assertContains($order->payment_integrity_status, ['verified']);
    }

    public function test_underpayment_reported_by_the_gateway_is_never_settled(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'amount' => 100, 'currency' => 'GHS'],
        ], 200)]);

        $this->getJson("/api/v1/orders/{$orderId}/status", $this->auth($plain))->assertOk()
            ->assertJsonPath('data.payment.status', 'pending');
        $this->assertSame('unpaid', Order::first()->payment_status);
    }

    public function test_failed_gateway_payment_is_reported_as_failed(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed']], 200)]);

        $this->getJson("/api/v1/orders/{$orderId}/status", $this->auth($plain))->assertOk()
            ->assertJsonPath('data.payment.status', 'failed')->assertJsonPath('data.status', 'failed');

        // A failed order cannot be paid again through the API; a new quote/order is required.
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_NOT_PAYABLE');
    }

    public function test_gateway_outage_during_status_check_does_not_break_or_change_the_order(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();
        $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->assertStatus(202);

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response('boom', 500)]);

        $this->getJson("/api/v1/orders/{$orderId}/status", $this->auth($plain))
            ->assertOk()->assertJsonPath('data.payment.status', 'pending');
        $this->assertSame('unpaid', Order::first()->payment_status);
    }

    public function test_transaction_lookup_is_scoped_to_the_application_that_owns_the_order(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();
        $reference = $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($plain))->json('data.reference');
        [, $other] = $this->makeKey($this->makeApp());

        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200)]);

        $this->getJson("/api/v1/transactions/{$reference}", $this->auth($plain))
            ->assertOk()->assertJsonPath('data.order_id', $orderId)->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/transactions/{$reference}", $this->auth($other))->assertStatus(404);
    }

    public function test_order_responses_never_expose_financial_internals(): void
    {
        [$orderId, $plain] = $this->orderWithPayment();

        $body = $this->getJson("/api/v1/orders/{$orderId}", $this->auth($plain))->assertOk()->getContent();

        foreach (['owner_earning', 'reseller_earning', 'platform_commission', 'affiliate_chain', 'payment_gateway', 'payment_integrity', 'idempotency', 'base_price', 'markup_price', 'email', 'wallet'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }
}
