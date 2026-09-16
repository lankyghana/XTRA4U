<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDashboardTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function test_vendor_dashboard_loads_for_approved_vendor()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard');
        // Debug: persist dashboard HTML for inspection
        file_put_contents(storage_path('logs/vendor_dashboard_debug.html'), $response->getContent());
        $response->assertStatus(200);
        $response->assertSeeText('Sales Overview');
        $response->assertSeeText('Total Earnings (Net)');
        $response->assertSee('Wallet', false);
        $response->assertSee('Recent Orders', false);
        $response->assertSee('Quick Actions', false);
    }

    public function test_vendor_withdrawals_page_loads()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 150.00,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WITHDRAW123',
            'notes' => 'First request',
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
        ]);

        $this->actingAs($vendor, 'vendor');

        $response = $this->followingRedirects()->get('/vendor/withdrawals');

        $response->assertStatus(200);
        $response->assertSeeText('Wallet');
        $response->assertSeeText('Withdrawal History');
        $response->assertSeeText('WITHDRAW123');
    }

    public function test_vendor_dashboard_sales_stats_includes_afa_earnings()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'reseller_vendor_id' => null,
            'full_name' => 'Test Customer',
            'id_number' => 'GHA-123456789-0',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0241234567',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 100.00,
            'vendor_price' => 100.00,
            'platform_commission' => 2.00,
            'vendor_earning' => 98.00,
            'reseller_earning' => 0.00,
            'is_reseller_order' => false,
            'payment_reference' => 'PAYREF-TEST-001',
            'payment_gateway' => 'test',
            'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'payment_completed_at' => now(),
            'status' => AfaRegistration::STATUS_PENDING,
            'reference' => 'AFA-TEST-0001',
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard/sales-stats?filter=all_time', [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'earnings' => '98.00',
        ]);
    }

    public function test_vendor_dashboard_does_not_show_unpaid_orders()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $ghostOrder = Order::create([
            'recipient_phone_number' => '0550000000',
            'mobile_money_number' => '0550000000',
            'service_purchased' => 'GHOST-ORDER-DO-NOT-SHOW',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
        ]);

        // Even if a pending transaction exists, it must not be visible.
        Transaction::create([
            'order_id' => $ghostOrder->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $ghostOrder->recipient_phone_number,
            'amount' => 10.00,
            'commission_amount' => 0,
            'vendor_earning' => 0,
            'payment_status' => 'pending',
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard');
        $response->assertStatus(200);
        $response->assertDontSeeText('#' . $ghostOrder->id);
    }

    public function test_vendor_dashboard_shows_paid_orders_in_processing_or_completed()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0551111111',
            'mobile_money_number' => '0551111111',
            'service_purchased' => 'PAID-ORDER-SHOULD-SHOW',
            'amount_paid' => 20.00,
            'vendor_id' => $vendor->id,
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => 20.00,
            'commission_amount' => 0.40,
            'vendor_earning' => 19.60,
            'payment_status' => 'successful',
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard');
        // Debug: persist dashboard HTML for failing-case inspection
        file_put_contents(storage_path('logs/vendor_dashboard_paid_order_debug.html'), $response->getContent());
        $response->assertStatus(200);
        // Use raw HTML assert to avoid strip_tags normalization differences in test runner
        $response->assertSee('#' . $order->id, false);
    }

    /**
     * Regression test: updateOrderStatus() / AdminOrderController::update() repoint a
     * paid order's linked Transaction.payment_status to 'pending' (On Hold, Verifying)
     * or 'failed' (Refunded) purely for earnings bookkeeping — that must not also hide
     * the order from the vendor, since the order itself (payment_status paid, status
     * one of these) is still a legitimate, already-visible order.
     */
    public function test_vendor_dashboard_shows_orders_on_hold_verifying_or_refunded()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $statusPairs = [
            'On Hold' => 'pending',
            'Verifying' => 'pending',
            'Refunded' => 'failed',
        ];

        $orders = [];
        foreach ($statusPairs as $status => $transactionPaymentStatus) {
            $order = Order::create([
                'recipient_phone_number' => '0552222222',
                'mobile_money_number' => '0552222222',
                'service_purchased' => 'ORDER-' . $status,
                'amount_paid' => 15.00,
                'vendor_id' => $vendor->id,
                'status' => $status,
                'payment_status' => 'paid',
                'payment_completed_at' => now(),
            ]);

            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $vendor->id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => 15.00,
                'commission_amount' => 0.30,
                'vendor_earning' => 14.70,
                // Mirrors what the status-update endpoints actually write for these
                // statuses — no longer 'successful'/'completed'.
                'payment_status' => $transactionPaymentStatus,
            ]);

            $orders[$status] = $order;
        }

        $this->actingAs($vendor, 'vendor');

        $dashboardResponse = $this->get('/vendor/dashboard');
        $dashboardResponse->assertStatus(200);

        $ordersResponse = $this->get(route('vendor.orders.index'));
        $ordersResponse->assertStatus(200);

        foreach ($orders as $status => $order) {
            $dashboardResponse->assertSee('#' . $order->id, false);
            $ordersResponse->assertSee('#' . $order->id, false);
            $ordersResponse->assertSee($status, false);
        }
    }

    public function test_vendor_dashboard_shows_paid_afa_registration_in_recent_orders()
    {
        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'reseller_vendor_id' => null,
            'full_name' => 'Dashboard AFA User',
            'id_number' => 'GHA-123456789-0',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'date_of_birth' => '1992-05-06',
            'phone_number' => '0247000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 120.00,
            'vendor_price' => 120.00,
            'platform_commission' => 2.40,
            'vendor_earning' => 117.60,
            'reseller_earning' => 0.00,
            'is_reseller_order' => false,
            'payment_reference' => 'PAYREF-AFA-DASH-1',
            'payment_gateway' => 'test',
            'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'payment_completed_at' => now(),
            'status' => AfaRegistration::STATUS_PROCESSING,
            'reference' => 'AFA-DASH-0001',
        ]);

        $this->actingAs($vendor, 'vendor');
        $response = $this->get('/vendor/dashboard');

        $response->assertStatus(200);
        $response->assertSee('AFA-DASH-0001', false);
        // Recipient phone is intentionally no longer a Recent Orders column on the
        // dashboard overview (approved column set: Order/Service/Amount/Status/Date/
        // Action — recipient detail lives on the full Orders page instead).
        $response->assertSee('AFA Registration', false);
        $response->assertSee('GHS 120.00', false);
    }
}
