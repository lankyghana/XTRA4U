<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\ExternalFulfillmentStatusSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalFulfillmentStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $orderOverrides = [], array $fulfillmentOverrides = []): Order
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'MTN Data',
            'description' => json_encode(['network' => 'MTN', 'size' => '1 GB']),
            'price' => 10.00,
            'is_active' => true,
            'is_resellable' => false,
            'min_base_price' => 10.00,
        ]);

        $order = Order::create(array_merge([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ], $orderOverrides));

        $order->forceFill(array_merge([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_remote_reference' => 'REMOTE-123',
            'external_fulfillment_provider_used' => 'gigshub',
        ], $fulfillmentOverrides))->save();

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 10.00,
            'commission_amount' => 0.20,
            'vendor_earning' => 9.80,
            'payment_status' => 'successful',
            'timestamp' => now(),
            'payment_type' => 'order',
        ]);

        return $order->refresh();
    }

    private function synchronizer(): ExternalFulfillmentStatusSynchronizer
    {
        return app(ExternalFulfillmentStatusSynchronizer::class);
    }

    // ── Core behaviour ──────────────────────────────────────────────────────

    public function test_provider_delivery_completes_the_order_and_settles_transactions(): void
    {
        $order = $this->makeOrder();

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_COMPLETED, $outcome);

        $order->refresh();
        $this->assertSame('Completed', $order->status);
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertNotNull($order->external_fulfillment_delivered_at);

        $this->assertSame('completed', Transaction::where('order_id', $order->id)->first()->payment_status);
    }

    public function test_delivery_is_idempotent_across_duplicate_callbacks(): void
    {
        $order = $this->makeOrder();

        $first = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');
        $deliveredAt = $order->refresh()->external_fulfillment_delivered_at;

        $second = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');
        $third = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_COMPLETED, $first);
        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_UNCHANGED, $second);
        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_UNCHANGED, $third);

        $order->refresh();
        $this->assertSame('Completed', $order->status);
        // The delivery timestamp must not drift on repeat callbacks.
        $this->assertEquals($deliveredAt, $order->external_fulfillment_delivered_at);
    }

    public function test_wallet_balance_is_untouched_by_status_sync(): void
    {
        $order = $this->makeOrder();
        $vendor = Vendor::find($order->vendor_id);
        $balanceBefore = (float) $vendor->wallet_balance;

        $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame($balanceBefore, (float) $vendor->fresh()->wallet_balance);
    }

    // ── Guard rails ─────────────────────────────────────────────────────────

    public function test_unknown_provider_status_changes_nothing(): void
    {
        $order = $this->makeOrder();

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'some-new-status-we-never-saw');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_IGNORED, $outcome);

        $order->refresh();
        $this->assertSame('Processing', $order->status);
        $this->assertSame('succeeded', $order->external_fulfillment_status);
    }

    public function test_refunded_order_is_never_reopened_or_completed_by_a_late_callback(): void
    {
        $order = $this->makeOrder(['status' => 'Refunded']);

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_IGNORED, $outcome);
        $this->assertSame('Refunded', $order->refresh()->status);
    }

    public function test_cancelled_order_is_not_completed_by_a_late_callback(): void
    {
        $order = $this->makeOrder(['status' => 'Cancelled']);

        $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame('Cancelled', $order->refresh()->status);
    }

    public function test_unpaid_order_is_never_completed(): void
    {
        $order = $this->makeOrder(['payment_status' => 'pending']);

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_IGNORED, $outcome);
        $this->assertSame('Processing', $order->refresh()->status);
    }

    public function test_provider_failure_flags_the_order_without_closing_it(): void
    {
        $order = $this->makeOrder();

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'failed');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_FAILED, $outcome);

        $order->refresh();
        // A paid order that the provider failed stays open for support/refund.
        $this->assertSame('Processing', $order->status);
        $this->assertSame('failed', $order->external_fulfillment_status);
        $this->assertStringContainsString('failed', (string) $order->external_fulfillment_last_error);
    }

    public function test_processing_status_never_downgrades_an_already_succeeded_order(): void
    {
        $order = $this->makeOrder();

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'pending');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_UNCHANGED, $outcome);
        $this->assertSame('succeeded', $order->refresh()->external_fulfillment_status);
    }

    public function test_auto_complete_kill_switch_records_delivery_without_completing(): void
    {
        config()->set('external_fulfillment.auto_complete_on_delivery', false);

        $order = $this->makeOrder();

        $outcome = $this->synchronizer()->apply($order->id, 'gigshub', 'delivered');

        $this->assertSame(
            ExternalFulfillmentStatusSynchronizer::OUTCOME_DELIVERED_PENDING_CONFIRMATION,
            $outcome
        );

        $order->refresh();
        $this->assertSame('Processing', $order->status);
        $this->assertNotNull($order->external_fulfillment_delivered_at);
        // Transaction stays as-is until the vendor confirms.
        $this->assertSame('successful', Transaction::where('order_id', $order->id)->first()->payment_status);
    }

    // ── Reseller / affiliate flow ───────────────────────────────────────────

    public function test_reseller_order_completes_and_settles_both_transaction_legs(): void
    {
        $owner = Vendor::factory()->create(['is_approved' => true]);
        $reseller = Vendor::factory()->create(['is_approved' => true]);

        $product = Product::create([
            'vendor_id' => $owner->id,
            'name' => 'MTN Data',
            'description' => json_encode(['network' => 'MTN', 'size' => '1 GB']),
            'price' => 10.00,
            'is_active' => true,
            'is_resellable' => true,
            'min_base_price' => 10.00,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 12.00,
            'vendor_id' => $reseller->id,
            'owner_vendor_id' => $owner->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'is_reseller_order' => true,
        ]);

        $order->forceFill([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_remote_reference' => 'REMOTE-RESELLER',
            'external_fulfillment_provider_used' => 'skdataplug',
        ])->save();

        foreach ([$owner->id, $reseller->id] as $vendorId) {
            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $vendorId,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => 6.00,
                'commission_amount' => 0.12,
                'vendor_earning' => 5.88,
                'payment_status' => 'successful',
                'timestamp' => now(),
                'payment_type' => 'order',
            ]);
        }

        $ownerBalance = (float) $owner->wallet_balance;
        $resellerBalance = (float) $reseller->wallet_balance;

        $outcome = $this->synchronizer()->apply($order->id, 'skdataplug', 'delivered');

        $this->assertSame(ExternalFulfillmentStatusSynchronizer::OUTCOME_COMPLETED, $outcome);
        $this->assertSame('Completed', $order->refresh()->status);

        // Both legs settle, and neither wallet moves.
        $this->assertSame(
            ['completed', 'completed'],
            Transaction::where('order_id', $order->id)->orderBy('id')->pluck('payment_status')->all()
        );
        $this->assertSame($ownerBalance, (float) $owner->fresh()->wallet_balance);
        $this->assertSame($resellerBalance, (float) $reseller->fresh()->wallet_balance);
    }

    // ── Webhook endpoints ───────────────────────────────────────────────────

    public function test_gigshub_webhook_completes_a_delivered_order(): void
    {
        $order = $this->makeOrder();

        $this->postJson(route('api.webhooks.gigshub'), [
            'event' => 'order.status',
            'orderId' => 'REMOTE-123',
            'status' => 'delivered',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('Completed', $order->status);
        $this->assertSame('completed', Transaction::where('order_id', $order->id)->first()->payment_status);
    }

    public function test_skdataplug_webhook_completes_a_delivered_order(): void
    {
        config()->set('services.skdataplug.token', '');

        $order = $this->makeOrder(fulfillmentOverrides: [
            'external_fulfillment_remote_reference' => 'SKP36648705',
            'external_fulfillment_provider_used' => 'skdataplug',
        ]);

        $this->postJson(route('api.webhooks.skdataplug'), [
            'order_id' => 'SKP36648705',
            'status' => 'delivered',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('Completed', $order->status);
        $this->assertSame('completed', Transaction::where('order_id', $order->id)->first()->payment_status);
    }

    public function test_gigshub_webhook_is_idempotent_on_replay(): void
    {
        $order = $this->makeOrder();

        foreach (range(1, 3) as $ignored) {
            $this->postJson(route('api.webhooks.gigshub'), [
                'orderId' => 'REMOTE-123',
                'status' => 'delivered',
            ])->assertOk();
        }

        $this->assertSame('Completed', $order->refresh()->status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
    }

    public function test_gigshub_webhook_rejects_a_callback_with_a_bad_secret(): void
    {
        config()->set('services.gigshub.webhook_secret', 'top-secret');

        $order = $this->makeOrder();

        $this->postJson(route('api.webhooks.gigshub'), [
            'orderId' => 'REMOTE-123',
            'status' => 'delivered',
        ], ['X-Webhook-Secret' => 'wrong'])->assertStatus(401);

        $this->assertSame('Processing', $order->refresh()->status);
    }

    public function test_gigshub_webhook_accepts_a_callback_with_the_configured_secret(): void
    {
        config()->set('services.gigshub.webhook_secret', 'top-secret');

        $order = $this->makeOrder();

        $this->postJson(route('api.webhooks.gigshub'), [
            'orderId' => 'REMOTE-123',
            'status' => 'delivered',
        ], ['X-Webhook-Secret' => 'top-secret'])->assertOk();

        $this->assertSame('Completed', $order->refresh()->status);
    }

    public function test_skdataplug_webhook_rejects_a_callback_with_a_bad_signature(): void
    {
        config()->set('services.skdataplug.token', 'sk-secret');

        $order = $this->makeOrder(fulfillmentOverrides: [
            'external_fulfillment_remote_reference' => 'SKP1',
            'external_fulfillment_provider_used' => 'skdataplug',
        ]);

        $this->postJson(route('api.webhooks.skdataplug'), [
            'order_id' => 'SKP1',
            'status' => 'delivered',
        ], ['X-SKPlug-Signature' => 'nonsense'])->assertStatus(401);

        $this->assertSame('Processing', $order->refresh()->status);
    }

    public function test_webhook_for_unknown_reference_returns_404_and_changes_nothing(): void
    {
        $order = $this->makeOrder();

        $this->postJson(route('api.webhooks.gigshub'), [
            'orderId' => 'NOT-A-REAL-REFERENCE',
            'status' => 'delivered',
        ])->assertNotFound();

        $this->assertSame('Processing', $order->refresh()->status);
    }
}
