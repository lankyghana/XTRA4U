<?php

namespace Tests\Feature;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\AfaPaymentService;
use App\Services\AffiliateChainService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiLevelAffiliatePayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_level_product_reseller_order_splits_across_chain(): void
    {
        $owner = Vendor::factory()->create(['wallet_balance' => 0]);
        $parentReseller = Vendor::factory()->create(['wallet_balance' => 0]);
        $childReseller = Vendor::factory()->create(['wallet_balance' => 0]);

        $product = Product::create([
            'vendor_id' => $owner->id,
            'name' => 'Test Bundle',
            'description' => json_encode(['network' => 'MTN', 'category' => 'data']),
            'price' => 100,
            'min_base_price' => 100,
            'is_active' => true,
            'is_resellable' => true,
        ]);

        $parentListing = ResellerProduct::create([
            'product_id' => $product->id,
            'reseller_vendor_id' => $parentReseller->id,
            'owner_vendor_id' => $owner->id,
            'base_price' => 100,
            'markup_price' => 10,
            'is_active' => true,
        ]);

        $childListing = ResellerProduct::create([
            'product_id' => $product->id,
            'source_reseller_product_id' => $parentListing->id,
            'reseller_vendor_id' => $childReseller->id,
            'owner_vendor_id' => $owner->id,
            'base_price' => 110,
            'markup_price' => 5,
            'is_active' => true,
        ]);

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => $product->name,
            'amount_paid' => 115,
            'vendor_id' => $childReseller->id,
            'vendor_service_id' => $product->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'reseller_product_id' => $childListing->id,
            'is_reseller_order' => true,
        ]);

        /** @var PaymentService $paymentService */
        $paymentService = $this->app->make(PaymentService::class);
        $this->assertTrue($paymentService->completeOrder($order->fresh()));

        $order = $order->fresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($owner->id, $order->owner_vendor_id);
        $this->assertSame($childReseller->id, $order->reseller_vendor_id);

        // Base=100, markups=10 + 5
        $this->assertEquals(2.30, (float) $order->platform_commission);

        $snapshot = $order->affiliate_chain_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertCount(3, $snapshot);

        $owner->refresh();
        $parentReseller->refresh();
        $childReseller->refresh();

        $this->assertEquals(98.00, (float) $owner->wallet_balance); // 100 - 2%
        $this->assertEquals(9.80, (float) $parentReseller->wallet_balance); // 10 - 2%
        $this->assertEquals(4.90, (float) $childReseller->wallet_balance); // 5 - 2%

        $this->assertSame(3, Transaction::where('order_id', $order->id)->count());
        $this->assertNotNull(Transaction::where('order_id', $order->id)->where('vendor_id', $owner->id)->first());
        $this->assertNotNull(Transaction::where('order_id', $order->id)->where('vendor_id', $parentReseller->id)->first());
        $this->assertNotNull(Transaction::where('order_id', $order->id)->where('vendor_id', $childReseller->id)->first());
    }

    public function test_multi_level_afa_registration_splits_across_chain(): void
    {
        $provider = Vendor::factory()->create([
            'wallet_balance' => 0,
            'afa_enabled' => true,
            'afa_price' => 50,
            'is_approved' => true,
        ]);

        $reseller1 = Vendor::factory()->create([
            'wallet_balance' => 0,
            'affiliate_vendor_id' => $provider->id,
            'afa_reseller_enabled' => true,
            'afa_source_vendor_id' => $provider->id,
            'afa_base_price' => 50,
            'afa_markup' => 10,
            'afa_selling_price' => 60,
            'is_approved' => true,
        ]);

        $reseller2 = Vendor::factory()->create([
            'wallet_balance' => 0,
            'affiliate_vendor_id' => $reseller1->id,
            'afa_reseller_enabled' => true,
            'afa_source_vendor_id' => $reseller1->id,
            'afa_base_price' => 60,
            'afa_markup' => 5,
            'afa_selling_price' => 65,
            'is_approved' => true,
        ]);

        $chainService = new AffiliateChainService();
        $payout = $chainService->computeAfaPayoutFromSeller($reseller2);
        $this->assertTrue($payout['ok']);

        $registration = AfaRegistration::create([
            'vendor_id' => $payout['owner_vendor_id'],
            'reseller_vendor_id' => $reseller2->id,
            'full_name' => 'Test Customer',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-1',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 65,
            'vendor_price' => $payout['base_amount'],
            'platform_commission' => $payout['platform_commission'],
            'vendor_earning' => $payout['owner_earning'],
            'reseller_earning' => $payout['immediate_seller_earning'],
            'affiliate_chain_snapshot' => $payout['snapshot'],
            'is_reseller_order' => true,
            'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'status' => AfaRegistration::STATUS_PENDING,
            'reference' => AfaRegistration::generateReference(),
        ]);

        $afaPaymentService = $this->app->make(AfaPaymentService::class);
        $this->assertTrue($afaPaymentService->completeRegistration($registration->fresh()));

        $provider->refresh();
        $reseller1->refresh();
        $reseller2->refresh();

        $this->assertEquals(49.00, (float) $provider->wallet_balance); // 50 - 2%
        $this->assertEquals(9.80, (float) $reseller1->wallet_balance); // 10 - 2%
        $this->assertEquals(4.90, (float) $reseller2->wallet_balance); // 5 - 2%

        $this->assertSame(
            3,
            Transaction::where('transactionable_type', 'App\\Models\\AfaRegistration')
                ->where('transactionable_id', $registration->id)
                ->count()
        );

        $completed = $registration->fresh();
        $this->assertSame(AfaRegistration::PAYMENT_COMPLETED, $completed->payment_status);
        $this->assertEquals(1.30, (float) $completed->platform_commission);
    }
}
