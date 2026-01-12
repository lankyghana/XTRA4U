<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorOrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_fulfillment_page_does_not_crash_when_product_description_is_not_json(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'Legacy Product',
            'description' => 'NOT JSON',
            'price' => 10.00,
            'is_active' => true,
            'is_resellable' => false,
            'min_base_price' => 10.00,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'Legacy Product',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ]);

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

        $this->actingAs($vendor, 'vendor');

        $response = $this->get(route('vendor.fulfillment.index'));
        $response->assertOk();
    }

    public function test_vendor_fulfillment_download_excludes_orders_being_handled_by_external_api(): void
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

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ]);

        // Order model does not mass-assign external_fulfillment_* fields (by design),
        // so set it explicitly for this scenario.
        $order->forceFill(['external_fulfillment_status' => 'processing'])->save();

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

        $this->actingAs($vendor, 'vendor');

        $downloadResponse = $this->get(route('vendor.fulfillment.download', ['network' => 'MTN']));
        $downloadResponse->assertRedirect();

        $order->refresh();
        $this->assertNull($order->downloaded_at);
    }

    public function test_vendor_can_manually_mark_external_api_sent_orders_as_completed_with_confirmation(): void
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

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ]);

        $order->forceFill([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_completed_at' => now(),
        ])->save();

        $tx = Transaction::create([
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

        $this->actingAs($vendor, 'vendor');

        $response = $this->post(route('vendor.fulfillment.complete', ['network' => 'External API']), [
            'confirm_external_api_completed' => '1',
        ]);
        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('Completed', $order->status);

        $tx->refresh();
        $this->assertSame('completed', $tx->payment_status);
    }

    public function test_vendor_cannot_bulk_complete_external_api_orders_without_confirmation(): void
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

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ]);

        $order->forceFill([
            'external_fulfillment_status' => 'succeeded',
            'external_fulfillment_completed_at' => now(),
        ])->save();

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

        $this->actingAs($vendor, 'vendor');

        $response = $this->post(route('vendor.fulfillment.complete', ['network' => 'External API']));
        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('Processing', $order->status);
    }

    public function test_vendor_can_download_processing_orders_per_network_and_prevent_duplicates(): void
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

        $order = Order::create([
            'recipient_phone_number' => '0241234567',
            'mobile_money_number' => '0241234567',
            'service_purchased' => 'MTN Data',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
        ]);

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

        $this->actingAs($vendor, 'vendor');

        $downloadResponse = $this->get(route('vendor.fulfillment.download', ['network' => 'MTN']));
        $downloadResponse->assertOk();
        $downloadResponse->assertHeader('Content-Type');
        $downloadResponse->assertHeader('Content-Disposition');
        $downloadResponse->assertSee('Number');
        $downloadResponse->assertSee('Package');
        $downloadResponse->assertSee('0241234567');
        $downloadResponse->assertSee('MTN Data');

        $order->refresh();
        $this->assertNotNull($order->downloaded_at);

        $secondDownload = $this->get(route('vendor.fulfillment.download', ['network' => 'MTN']));
        $secondDownload->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->downloaded_at);
    }

    public function test_vendor_can_mark_downloaded_orders_as_completed_and_transactions_update(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);

        $product = Product::create([
            'vendor_id' => $vendor->id,
            'name' => 'Vodafone Data',
            'description' => json_encode(['network' => 'Vodafone', 'size' => '2 GB']),
            'price' => 15.00,
            'is_active' => true,
            'is_resellable' => false,
            'min_base_price' => 15.00,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0200000000',
            'mobile_money_number' => '0200000000',
            'service_purchased' => 'Vodafone Data',
            'amount_paid' => 15.00,
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'downloaded_at' => now(),
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 15.00,
            'commission_amount' => 0.30,
            'vendor_earning' => 14.70,
            'payment_status' => 'successful',
            'timestamp' => now(),
            'payment_type' => 'order',
        ]);

        $this->actingAs($vendor, 'vendor');

        $completeResponse = $this->post(route('vendor.fulfillment.complete', ['network' => 'Vodafone']));
        $completeResponse->assertRedirect();

        $order->refresh();
        $this->assertSame('Completed', $order->status);

        $tx->refresh();
        $this->assertSame('completed', $tx->payment_status);
    }

    public function test_reseller_cannot_download_affiliate_orders_but_owner_can(): void
    {
        $owner = Vendor::factory()->create(['is_approved' => true]);
        $reseller = Vendor::factory()->create(['is_approved' => true]);

        $product = Product::create([
            'vendor_id' => $owner->id,
            'name' => 'AirtelTigo Data',
            'description' => json_encode(['network' => 'AirtelTigo', 'size' => '1 GB']),
            'price' => 8.00,
            'is_active' => true,
            'is_resellable' => true,
            'min_base_price' => 8.00,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0277777777',
            'mobile_money_number' => '0277777777',
            'service_purchased' => 'AirtelTigo Data',
            'amount_paid' => 8.00,
            'vendor_id' => $reseller->id,
            'owner_vendor_id' => $owner->id,
            'vendor_service_id' => $product->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'is_reseller_order' => true,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $owner->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 8.00,
            'commission_amount' => 0.16,
            'vendor_earning' => 7.84,
            'payment_status' => 'successful',
            'timestamp' => now(),
            'payment_type' => 'order',
        ]);

        $this->actingAs($reseller, 'vendor');
        $resellerDownload = $this->get(route('vendor.fulfillment.download', ['network' => 'AirtelTigo']));
        $resellerDownload->assertRedirect();

        $order->refresh();
        $this->assertNull($order->downloaded_at);

        $this->actingAs($owner, 'vendor');
        $ownerDownload = $this->get(route('vendor.fulfillment.download', ['network' => 'AirtelTigo']));
        $ownerDownload->assertOk();

        $order->refresh();
        $this->assertNotNull($order->downloaded_at);
    }
}
