<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\SandboxOrder;
use App\Models\Developer\SandboxOrderEvent;
use App\Models\Order;
use App\Models\Product;

class SandboxOrderTest extends SandboxTestCase
{
    public function test_creating_a_sandbox_order_starts_unpaid_in_the_sandbox_tables_only(): void
    {
        $w = $this->sandboxWorld(10.0);
        $this->forbidProductionServices();
        $before = $this->productionFingerprint($w['vendor']);

        $quote = $this->quote($w['key'], $w['offer'])->json('data.reference');
        $res = $this->order($w['key'], $quote, 'k1', ['client_reference' => 'MY-ORDER-77'])->assertCreated();

        $res->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.amount', 10)
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.client_reference', 'MY-ORDER-77')
            ->assertJsonPath('data.recipient_phone', '0244000000')
            ->assertJsonPath('data.payment.status', 'unpaid')
            ->assertJsonPath('data.payment.reference', null)
            ->assertJsonPath('data.fulfillment.status', 'not_started')
            ->assertJsonPath('meta.idempotent_replay', false);
        $this->assertMatchesRegularExpression('/^sbx_ord_[a-z0-9]{26}$/', $res->json('data.id'));

        $row = SandboxOrder::firstOrFail();
        $this->assertSame($w['vendor']->id, (int) $row->vendor_id);
        $this->assertSame($w['app']->id, (int) $row->application_id);
        $this->assertSame($quote, $row->quote_reference);
        $this->assertSame('awaiting_payment', $row->status);

        // Nothing leaked into production.
        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
        $this->assertSame(0, Order::count());
        $this->assertNoProductionServiceTouched();
    }

    public function test_the_response_hides_internal_ids(): void
    {
        $w = $this->sandboxWorld();
        $id = $this->sandboxOrderId($w['key'], $w['offer']);

        $data = $this->getJson("/api/v1/orders/{$id}", $this->auth($w['key']))->assertOk()->json('data');

        foreach (['vendor_id', 'application_id', 'api_key_id', 'product_id', 'idempotency_key', 'product_snapshot', 'quote_reference', 'unit_price', 'total_amount'] as $leak) {
            $this->assertArrayNotHasKey($leak, $data);
        }
        $this->assertArrayNotHasKey('id', $data['seller'] ?? []);
    }

    public function test_every_sandbox_order_reference_is_unique(): void
    {
        $w = $this->sandboxWorld();

        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->sandboxOrderId($w['key'], $w['offer'], "k{$i}");
        }

        $this->assertCount(5, array_unique($ids));
        $this->assertSame(5, SandboxOrder::count());
        $this->assertCount(5, array_unique(SandboxOrder::pluck('quote_reference')->all()));
    }

    public function test_order_creation_is_idempotent(): void
    {
        $w = $this->sandboxWorld();
        $quote = $this->quote($w['key'], $w['offer'])->json('data.reference');

        $first = $this->order($w['key'], $quote, 'same-key')->assertCreated();
        $replay = $this->order($w['key'], $quote, 'same-key')->assertOk()->assertJsonPath('meta.idempotent_replay', true);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, SandboxOrder::count());
        $this->assertSame(1, SandboxOrderEvent::where('type', 'order.created')->count());
    }

    public function test_an_idempotency_key_cannot_be_reused_for_a_different_quote(): void
    {
        $w = $this->sandboxWorld();
        $q1 = $this->quote($w['key'], $w['offer'])->json('data.reference');
        $q2 = $this->quote($w['key'], $w['offer'])->json('data.reference');

        $this->order($w['key'], $q1, 'reused')->assertCreated();
        $this->order($w['key'], $q2, 'reused')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, SandboxOrder::count());
        $this->assertNull(ApiQuote::query()->where('reference', $q2)->value('consumed_at'));
    }

    public function test_an_idempotency_key_is_required_and_validated(): void
    {
        $w = $this->sandboxWorld();
        $quote = $this->quote($w['key'], $w['offer'])->json('data.reference');

        $this->postJson('/api/v1/orders', ['quote_reference' => $quote], $this->auth($w['key']))
            ->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->order($w['key'], $quote, 'bad key!')->assertStatus(400);
        $this->assertSame(0, SandboxOrder::count());
    }

    public function test_the_same_idempotency_key_in_each_environment_does_not_collide(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        $sbxId = $this->order($w['key'], $this->quote($w['key'], $w['offer'])->json('data.reference'), 'shared')->assertCreated()->json('data.id');
        $prodId = $this->order($live, $this->quote($live, $w['offer'])->json('data.reference'), 'shared')->assertCreated()->json('data.id');

        $this->assertStringStartsWith('sbx_ord_', $sbxId);
        $this->assertStringStartsWith('ord_', $prodId);
        $this->assertSame(1, SandboxOrder::count());
        $this->assertSame(1, Order::count());
    }

    public function test_the_same_idempotency_key_in_different_applications_does_not_collide(): void
    {
        $a = $this->sandboxWorld();
        $b = $this->sandboxWorld();

        $this->sandboxOrderId($a['key'], $a['offer'], 'shared');
        $this->sandboxOrderId($b['key'], $b['offer'], 'shared');

        $this->assertSame(2, SandboxOrder::count());
    }

    public function test_orders_are_isolated_by_vendor_and_application(): void
    {
        $a = $this->sandboxWorld();
        $b = $this->sandboxWorld();
        $sameVendorOtherApp = $this->makeApp($a['vendor']);
        [, $otherAppKey] = $this->makeKey($sameVendorOtherApp, null, null, 'sandbox');

        $id = $this->sandboxOrderId($a['key'], $a['offer']);
        $ref = $this->startPayment($a['key'], $id)->json('data.reference');

        foreach ([$b['key'], $otherAppKey] as $stranger) {
            $this->getJson("/api/v1/orders/{$id}", $this->auth($stranger))->assertNotFound();
            $this->getJson("/api/v1/orders/{$id}/status", $this->auth($stranger))->assertNotFound();
            $this->getJson("/api/v1/transactions/{$ref}", $this->auth($stranger))->assertNotFound();
            $this->startPayment($stranger, $id)->assertNotFound();
            $this->simulate($stranger, $id, ['event' => 'payment.succeeded'])->assertNotFound();
        }

        $this->assertSame('pending', SandboxOrder::firstOrFail()->payment_status);
    }

    public function test_ownership_comes_from_the_key_never_from_the_request_body(): void
    {
        $a = $this->sandboxWorld();
        $b = $this->sandboxWorld();
        $quote = $this->quote($a['key'], $a['offer'])->json('data.reference');

        $this->order($a['key'], $quote, 'k', [
            'vendor_id' => $b['vendor']->id, 'application_id' => $b['app']->id, 'product_id' => $b['offer'], 'api_key_id' => 999,
        ])->assertCreated();

        $row = SandboxOrder::firstOrFail();
        $this->assertSame($a['vendor']->id, (int) $row->vendor_id);
        $this->assertSame($a['app']->id, (int) $row->application_id);
        $this->assertSame($a['offer'], $row->offer_id);
    }

    public function test_an_order_cannot_be_created_for_another_vendors_product_via_a_forged_quote(): void
    {
        $a = $this->sandboxWorld();
        $b = $this->sandboxWorld();
        $quote = $this->quote($a['key'], $a['offer'])->json('data.reference');

        // Corrupt the stored quote to point at the other vendor's product: re-resolution refuses it.
        ApiQuote::query()->where('reference', $quote)->update(['offer_id' => $b['offer']]);

        $this->order($a['key'], $quote)->assertStatus(409)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        $this->assertSame(0, SandboxOrder::count());
    }

    public function test_the_order_snapshot_is_immutable_when_the_product_changes_later(): void
    {
        $w = $this->sandboxWorld(10.0);
        $id = $this->sandboxOrderId($w['key'], $w['offer']);

        Product::query()->whereKey($w['product']->id)->update(['price' => 99.0, 'name' => 'Renamed', 'is_active' => false]);

        $order = $this->getJson("/api/v1/orders/{$id}", $this->auth($w['key']))->assertOk();
        $order->assertJsonPath('data.amount', 10)->assertJsonPath('data.product.name', 'MTN 5GB');

        // The simulated payment is for the frozen amount too, even though the product is gone from the catalog.
        $this->startPayment($w['key'], $id)->assertStatus(202)->assertJsonPath('data.amount', 10);
        $this->assertSame('10.00', SandboxOrder::firstOrFail()->payment->amount);
        $this->assertSame(10.0, (float) SandboxOrder::firstOrFail()->product_snapshot['offer']['price']);
    }

    public function test_a_price_change_between_quote_and_order_is_refused(): void
    {
        $w = $this->sandboxWorld(10.0);
        $quote = $this->quote($w['key'], $w['offer'])->json('data.reference');
        Product::query()->whereKey($w['product']->id)->update(['price' => 12.0]);

        $this->order($w['key'], $quote)->assertStatus(409)
            ->assertJsonPath('error.code', 'QUOTE_PRICE_CHANGED')
            ->assertJsonPath('error.details.quoted_amount', '10.00')
            ->assertJsonPath('error.details.current_amount', '12.00');
        $this->assertSame(0, SandboxOrder::count());
    }

    public function test_quotes_are_single_use_and_expire(): void
    {
        $w = $this->sandboxWorld();
        $used = $this->quote($w['key'], $w['offer'])->json('data.reference');
        $expired = $this->quote($w['key'], $w['offer'])->json('data.reference');

        $this->order($w['key'], $used, 'a')->assertCreated();
        $this->order($w['key'], $used, 'b')->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_ALREADY_USED');

        ApiQuote::query()->where('reference', $expired)->update(['expires_at' => now()->subMinute()]);
        $this->order($w['key'], $expired, 'c')->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
    }

    public function test_client_reference_is_validated_in_the_sandbox_and_ignored_in_production(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        $q = $this->quote($w['key'], $w['offer'])->json('data.reference');
        $this->order($w['key'], $q, 'k', ['client_reference' => '<script>'])->assertStatus(422);

        // Production keeps ignoring unknown fields exactly as it did before this milestone.
        $pq = $this->quote($live, $w['offer'])->json('data.reference');
        $this->order($live, $pq, 'k', ['client_reference' => '<script>'])->assertCreated();
        $this->assertSame(0, SandboxOrder::count());
    }

    public function test_the_production_orders_table_is_unchanged_by_the_whole_sandbox_flow(): void
    {
        $w = $this->sandboxWorld();
        $this->forbidProductionServices();
        $before = $this->productionFingerprint($w['vendor']);

        $id = $this->sandboxOrderId($w['key'], $w['offer']);
        $this->startPayment($w['key'], $id)->assertStatus(202);
        $this->simulate($w['key'], $id, ['scenario' => 'success'])->assertOk();

        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
        $this->assertNoProductionServiceTouched();
    }
}
