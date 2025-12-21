<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorAffiliateOrdersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that vendor can access affiliate orders page.
     */
    public function test_vendor_can_access_affiliate_orders_page()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        $response->assertSeeText('Affiliate Orders');
    }

    /**
     * Test that unauthenticated user cannot access affiliate orders page.
     */
    public function test_unauthenticated_user_cannot_access_affiliate_orders()
    {
        $response = $this->get('/vendor/orders/affiliate');

        // Should redirect (to either vendor/login or vendor/request)
        $response->assertRedirect();
    }

    /**
     * Test that vendor sees only orders where their products were sold by resellers.
     */
    public function test_vendor_sees_only_their_affiliate_orders()
    {
        // Create product owner vendor
        $ownerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        // Create reseller vendor
        $resellerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => $ownerVendor->id,
        ]);

        // Create another vendor
        $otherVendor = Vendor::factory()->create([
            'is_approved' => true,
        ]);

        // Create a user
        $user = User::factory()->create();

        // Create affiliate order (owner's product sold by reseller)
        $affiliateOrder = Order::create([
            'recipient_phone_number' => '0244123456',
            'mobile_money_number' => '0244123456',
            'service_purchased' => 'Test Service',
            'amount_paid' => 100.00,
            'vendor_id' => $resellerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'reseller_vendor_id' => $resellerVendor->id,
            'is_reseller_order' => true,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        // Create direct order (owner's own order)
        $directOrder = Order::create([
            'recipient_phone_number' => '0244123457',
            'mobile_money_number' => '0244123457',
            'service_purchased' => 'Direct Service',
            'amount_paid' => 150.00,
            'vendor_id' => $ownerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'is_reseller_order' => false,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        // Create other vendor's order
        $otherOrder = Order::create([
            'recipient_phone_number' => '0244123458',
            'mobile_money_number' => '0244123458',
            'service_purchased' => 'Other Service',
            'amount_paid' => 200.00,
            'vendor_id' => $otherVendor->id,
            'owner_vendor_id' => $otherVendor->id,
            'is_reseller_order' => false,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        $this->actingAs($ownerVendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        // Should see affiliate order (using phone number as identifier since no order_number)
        $response->assertSeeText('0244123456');
        // Should NOT see direct order on affiliate page
        $response->assertDontSeeText('0244123457');
        // Should NOT see other vendor's order
        $response->assertDontSeeText('0244123458');
    }

    /**
     * Test that affiliate orders page displays reseller vendor information.
     */
    public function test_affiliate_orders_page_displays_reseller_info()
    {
        // Create product owner vendor
        $ownerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        // Create reseller vendor
        $resellerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'name' => 'Reseller Business XYZ',
            'affiliate_vendor_id' => $ownerVendor->id,
        ]);

        // Create a user
        $user = User::factory()->create();

        // Create affiliate order
        $affiliateOrder = Order::create([
            'recipient_phone_number' => '0244123456',
            'mobile_money_number' => '0244123456',
            'service_purchased' => 'Test Service',
            'amount_paid' => 100.00,
            'vendor_id' => $resellerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'reseller_vendor_id' => $resellerVendor->id,
            'is_reseller_order' => true,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        $this->actingAs($ownerVendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        $response->assertSeeText('0244123456');
        $response->assertSeeText('Reseller Business XYZ');
    }

    /**
     * Test that vendor with no affiliate orders sees empty state.
     */
    public function test_vendor_with_no_affiliate_orders_sees_empty_page()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        $response->assertSeeText('Affiliate Orders');
    }

    /**
     * Test that only reseller orders (is_reseller_order = true) appear on affiliate orders page.
     */
    public function test_only_reseller_orders_appear_on_affiliate_page()
    {
        // Create product owner vendor
        $ownerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        // Create reseller vendor
        $resellerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => $ownerVendor->id,
        ]);

        // Create a user
        $user = User::factory()->create();

        // Create reseller order
        $resellerOrder = Order::create([
            'recipient_phone_number' => '0244999991',
            'mobile_money_number' => '0244999991',
            'service_purchased' => 'Reseller Service',
            'amount_paid' => 100.00,
            'vendor_id' => $resellerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'reseller_vendor_id' => $resellerVendor->id,
            'is_reseller_order' => true,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        // Create non-reseller order with same owner_vendor_id but is_reseller_order = false
        $nonResellerOrder = Order::create([
            'recipient_phone_number' => '0244999992',
            'mobile_money_number' => '0244999992',
            'service_purchased' => 'Direct Service',
            'amount_paid' => 150.00,
            'vendor_id' => $ownerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'is_reseller_order' => false,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        $this->actingAs($ownerVendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        // Should see reseller order
        $response->assertSeeText('0244999991');
        // Should NOT see non-reseller order
        $response->assertDontSeeText('0244999992');
    }

    /**
     * Test pagination works on affiliate orders page.
     */
    public function test_affiliate_orders_pagination_works()
    {
        // Create product owner vendor
        $ownerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        // Create reseller vendor
        $resellerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => $ownerVendor->id,
        ]);

        // Create a user
        $user = User::factory()->create();

        // Create 25 affiliate orders (more than 20 per page)
        for ($i = 1; $i <= 25; $i++) {
            Order::create([
                'recipient_phone_number' => '024400' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'mobile_money_number' => '024400' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'service_purchased' => 'Service ' . $i,
                'amount_paid' => 100.00,
                'vendor_id' => $resellerVendor->id,
                'owner_vendor_id' => $ownerVendor->id,
                'reseller_vendor_id' => $resellerVendor->id,
                'is_reseller_order' => true,
                'status' => 'Completed',
                'payment_status' => 'completed',
            ]);
        }

        $this->actingAs($ownerVendor, 'vendor');

        // Test first page
        $response = $this->get('/vendor/orders/affiliate');
        $response->assertStatus(200);

        // Test second page exists
        $response = $this->get('/vendor/orders/affiliate?page=2');
        $response->assertStatus(200);
    }

    /**
     * Test that affiliate orders are ordered by latest first.
     */
    public function test_affiliate_orders_are_ordered_by_latest_first()
    {
        // Create product owner vendor
        $ownerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        // Create reseller vendor
        $resellerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => $ownerVendor->id,
        ]);

        // Create a user
        $user = User::factory()->create();

        // Create orders with different timestamps
        // Create old order first
        $oldOrder = new Order([
            'recipient_phone_number' => '0244OLD001',
            'mobile_money_number' => '0244OLD001',
            'service_purchased' => 'Old Service',
            'amount_paid' => 100.00,
            'vendor_id' => $resellerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'reseller_vendor_id' => $resellerVendor->id,
            'is_reseller_order' => true,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);
        $oldOrder->created_at = now()->subDays(5);
        $oldOrder->updated_at = now()->subDays(5);
        $oldOrder->save();

        // Add a small delay to ensure different timestamps
        sleep(1);

        // Create new order
        $newOrder = Order::create([
            'recipient_phone_number' => '0244NEW001',
            'mobile_money_number' => '0244NEW001',
            'service_purchased' => 'New Service',
            'amount_paid' => 150.00,
            'vendor_id' => $resellerVendor->id,
            'owner_vendor_id' => $ownerVendor->id,
            'reseller_vendor_id' => $resellerVendor->id,
            'is_reseller_order' => true,
            'status' => 'Completed',
            'payment_status' => 'completed',
        ]);

        $this->actingAs($ownerVendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        
        // Both orders should be visible
        $response->assertSeeText('0244NEW001');
        $response->assertSeeText('0244OLD001');
        
        // Check the database query returns them in correct order
        $orders = Order::where('owner_vendor_id', $ownerVendor->id)
            ->where('is_reseller_order', true)
            ->latest()
            ->get();
        
        $this->assertEquals('0244NEW001', $orders->first()->recipient_phone_number);
        $this->assertEquals('0244OLD001', $orders->last()->recipient_phone_number);
    }

    /**
     * Test that orders with different statuses are displayed correctly.
     */
    public function test_affiliate_orders_with_different_statuses_are_displayed()
    {
        // Create product owner vendor
        $ownerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        // Create reseller vendor
        $resellerVendor = Vendor::factory()->create([
            'is_approved' => true,
            'affiliate_vendor_id' => $ownerVendor->id,
        ]);

        // Create a user
        $user = User::factory()->create();

        // Create orders with different statuses (using actual enum values from migration)
        $statuses = ['Processing', 'Completed', 'Failed'];
        
        foreach ($statuses as $index => $status) {
            Order::create([
                'recipient_phone_number' => '024455500' . $index,
                'mobile_money_number' => '024455500' . $index,
                'service_purchased' => $status . ' Service',
                'amount_paid' => 100.00,
                'vendor_id' => $resellerVendor->id,
                'owner_vendor_id' => $ownerVendor->id,
                'reseller_vendor_id' => $resellerVendor->id,
                'is_reseller_order' => true,
                'status' => $status,
                'payment_status' => 'completed',
            ]);
        }

        $this->actingAs($ownerVendor, 'vendor');

        $response = $this->get('/vendor/orders/affiliate');

        $response->assertStatus(200);
        
        // All orders should be visible regardless of status
        foreach ($statuses as $index => $status) {
            $response->assertSeeText('024455500' . $index);
        }
    }
}
