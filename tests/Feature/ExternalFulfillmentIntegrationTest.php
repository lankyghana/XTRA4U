<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\ProcessExternalFulfillment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExternalFulfillmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function enableExternalFulfillmentForVendor(Vendor $vendor, array $overrides = []): void
    {
        $defaults = [
            'external_fulfillment_enabled' => '1',
            'external_fulfillment_token' => 'test-token',
            'external_fulfillment_timeout_seconds' => '10',
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            VendorSetting::setForVendor($vendor->id, $key, $value, 'external_fulfillment');
        }
    }

    public function test_order_completed_dispatches_external_fulfillment_job_when_enabled(): void
    {
        Queue::fake();

        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $this->enableExternalFulfillmentForVendor($vendor);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-1',
            'payment_gateway' => 'test',
        ]);

        OrderCompleted::dispatch($order);

        Queue::assertPushed(ProcessExternalFulfillment::class, function (ProcessExternalFulfillment $job) use ($order) {
            return $job->orderId === $order->id;
        });
    }

    public function test_order_completed_does_not_dispatch_when_disabled(): void
    {
        Queue::fake();

        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $this->enableExternalFulfillmentForVendor($vendor, [
            'external_fulfillment_enabled' => '0',
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-2',
            'payment_gateway' => 'test',
        ]);

        OrderCompleted::dispatch($order);

        Queue::assertNotPushed(ProcessExternalFulfillment::class);
    }

    public function test_job_posts_to_external_api_and_marks_order_succeeded_idempotently(): void
    {
        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response([], 200),
        ]);

        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $this->enableExternalFulfillmentForVendor($vendor);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-3',
            'payment_gateway' => 'test',
        ]);

        $job = new ProcessExternalFulfillment($order->id);
        $job->handle();

        Http::assertSent(function ($request) use ($order) {
            if ((string) $request->url() !== 'https://api.datafyhub.com/api/v1/placeOrder') {
                return false;
            }

            $data = $request->data();

            return isset($data['network'], $data['reference'], $data['recipient'], $data['capacity'])
                && $data['reference'] === 'TEST-REF-EXT-3'
                && $data['recipient'] === (string) $order->recipient_phone_number;
        });

        $order->refresh();
        $this->assertSame('succeeded', $order->external_fulfillment_status);
        $this->assertNotNull($order->external_fulfillment_completed_at);
        $this->assertSame('Completed', $order->status);

        // Second run should short-circuit without re-posting.
        $job->handle();

        Http::assertSentCount(1);
    }

    public function test_job_maps_airteltigo_products_to_datafyhub_ishare_and_capacity_from_size(): void
    {
        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response([], 200),
        ]);

        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $this->enableExternalFulfillmentForVendor($vendor);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'AirtelTigo iShare 2GB',
            'description' => json_encode([
                'network' => 'AirtelTigo',
                'size' => '2GB',
                'category' => 'data',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'price' => 10.00,
            'is_active' => true,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0591178627',
            'mobile_money_number' => '0591178627',
            'service_purchased' => $product->name,
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-AT-1',
            'payment_gateway' => 'test',
        ]);

        $job = new ProcessExternalFulfillment($order->id);
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['network'] === 'ishare'
                && $data['capacity'] === 2;
        });
    }

    public function test_job_prefers_explicit_datafyhub_network_metadata_over_heuristics(): void
    {
        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        Http::fake([
            'https://api.datafyhub.com/api/v1/placeOrder' => Http::response([], 200),
        ]);

        $vendor = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $this->enableExternalFulfillmentForVendor($vendor);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'AirtelTigo Data Bundle',
            'description' => json_encode([
                'network' => 'AirtelTigo',
                'datafyhub_network' => 'bigtime',
                'size' => '5GB',
                'category' => 'data',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'price' => 10.00,
            'is_active' => true,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0591178627',
            'mobile_money_number' => '0591178627',
            'service_purchased' => $product->name,
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-AT-2',
            'payment_gateway' => 'test',
        ]);

        $job = new ProcessExternalFulfillment($order->id);
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['network'] === 'bigtime'
                && $data['capacity'] === 5;
        });
    }

    public function test_affiliate_order_dispatches_external_fulfillment_for_owner_vendor_when_enabled(): void
    {
        Queue::fake();

        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        $owner = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $reseller = Vendor::factory()->create([
            'affiliate_vendor_id' => $owner->id,
        ]);

        $this->enableExternalFulfillmentForVendor($owner);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $reseller->id,
            'owner_vendor_id' => $owner->id,
            'reseller_vendor_id' => $reseller->id,
            'is_reseller_order' => true,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-4',
            'payment_gateway' => 'test',
        ]);

        OrderCompleted::dispatch($order);

        Queue::assertPushed(ProcessExternalFulfillment::class, function (ProcessExternalFulfillment $job) use ($order) {
            return $job->orderId === $order->id;
        });
    }

    public function test_order_completed_does_not_dispatch_for_reseller_vendor_even_if_configured(): void
    {
        Queue::fake();

        config([
            'services.external_fulfillment.base_url' => 'https://api.datafyhub.com',
            'services.external_fulfillment.endpoint' => '/api/v1/placeOrder',
        ]);

        $owner = Vendor::factory()->create([
            'affiliate_vendor_id' => null,
        ]);

        $reseller = Vendor::factory()->create([
            'affiliate_vendor_id' => $owner->id,
        ]);

        // Even if reseller somehow has settings, they must not be eligible.
        $this->enableExternalFulfillmentForVendor($reseller);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $reseller->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_reference' => 'TEST-REF-EXT-5',
            'payment_gateway' => 'test',
        ]);

        OrderCompleted::dispatch($order);

        Queue::assertNotPushed(ProcessExternalFulfillment::class);
    }
}
