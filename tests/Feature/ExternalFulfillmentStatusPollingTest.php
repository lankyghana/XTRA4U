<?php

namespace Tests\Feature;

use App\Jobs\SyncExternalFulfillmentStatuses;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalFulfillmentStatusPollingTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gigshub.base_url', 'https://gigshub.test');
        config()->set('services.gigshub.api_key', 'test-key');

        $this->vendor = Vendor::factory()->create(['is_approved' => true]);

        VendorSetting::setForVendor($this->vendor->id, 'external_fulfillment_enabled', '1', 'external_fulfillment');
        VendorSetting::setForVendor($this->vendor->id, 'external_fulfillment_gigshub_enabled', '1', 'external_fulfillment');
    }

    private function makeInFlightOrder(array $fulfillmentOverrides = []): Order
    {
        $product = Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'MTN Data',
            'description' => json_encode(['network' => 'MTN', 'size' => '1 GB']),
            'price' => 10.00,
            'is_active' => true,
            'is_resellable' => false,
            'min_base_price' => 10.00,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 10.00,
            'vendor_id' => $this->vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ]);

        $order->forceFill(array_merge([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_remote_reference' => 'GH-ORDER-1',
            'external_fulfillment_provider_used' => 'gigshub',
        ], $fulfillmentOverrides))->save();

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $this->vendor->id,
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

    public function test_polling_completes_an_order_the_provider_reports_as_delivered(): void
    {
        $order = $this->makeInFlightOrder();

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response([
                'order' => ['status' => 'delivered'],
            ], 200),
        ]);

        SyncExternalFulfillmentStatuses::dispatchSync();

        $order->refresh();
        $this->assertSame('Completed', $order->status);
        $this->assertNotNull($order->external_fulfillment_delivered_at);
        $this->assertNotNull($order->external_fulfillment_last_status_check_at);
        $this->assertSame('completed', Transaction::where('order_id', $order->id)->first()->payment_status);
    }

    public function test_polling_leaves_an_order_still_processing_at_the_provider_alone(): void
    {
        $order = $this->makeInFlightOrder();

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response([
                'order' => ['status' => 'processing'],
            ], 200),
        ]);

        SyncExternalFulfillmentStatuses::dispatchSync();

        $order->refresh();
        $this->assertSame('Processing', $order->status);
        $this->assertSame('succeeded', $order->external_fulfillment_status);
    }

    public function test_polling_survives_a_provider_outage_without_touching_the_order(): void
    {
        $order = $this->makeInFlightOrder();

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response(['error' => 'boom'], 500),
        ]);

        SyncExternalFulfillmentStatuses::dispatchSync();

        $order->refresh();
        $this->assertSame('Processing', $order->status);
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        // The attempt is still stamped, so the order is not re-polled immediately.
        $this->assertNotNull($order->external_fulfillment_last_status_check_at);
    }

    public function test_recently_checked_orders_are_not_re_polled(): void
    {
        $order = $this->makeInFlightOrder();
        $order->forceFill(['external_fulfillment_last_status_check_at' => now()])->save();

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response([
                'order' => ['status' => 'delivered'],
            ], 200),
        ]);

        SyncExternalFulfillmentStatuses::dispatchSync();

        Http::assertNothingSent();
        $this->assertSame('Processing', $order->refresh()->status);
    }

    public function test_already_completed_orders_are_not_polled(): void
    {
        $order = $this->makeInFlightOrder();
        $order->forceFill(['status' => 'Completed'])->save();

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response([
                'order' => ['status' => 'delivered'],
            ], 200),
        ]);

        SyncExternalFulfillmentStatuses::dispatchSync();

        Http::assertNothingSent();
    }

    public function test_polling_can_be_disabled_by_config(): void
    {
        config()->set('external_fulfillment.polling.enabled', false);

        $this->makeInFlightOrder();

        Http::fake();

        SyncExternalFulfillmentStatuses::dispatchSync();

        Http::assertNothingSent();
    }

    public function test_command_dispatches_the_sync_job(): void
    {
        $this->makeInFlightOrder();

        Http::fake([
            'gigshub.test/api/v1/order/status/*' => Http::response([
                'order' => ['status' => 'delivered'],
            ], 200),
        ]);

        $this->artisan('xtra4u:sync-external-fulfillment', ['--sync' => true])
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }
}
