<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\GatewayManager;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentInitiationAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_payment_persists_reference_and_creates_pending_transaction_even_on_gateway_failure(): void
    {
        $vendor = Vendor::factory()->create();

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'TEST-SERVICE',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
        ]);

        $fakeGateway = new class extends GatewayManager {
            public function collect(\App\Models\Order $order, string $email, float $amount): array
            {
                return [
                    'success' => false,
                    'message' => 'Simulated timeout',
                    'reference' => 'XTRA4U-TEST-REF-123',
                ];
            }
        };

        $service = new PaymentService($fakeGateway);

        $result = $service->initiatePayment($order, 'noreply@xtra4u.test', 10.00);

        $this->assertFalse($result['success']);

        $order->refresh();
        $this->assertSame('XTRA4U-TEST-REF-123', $order->payment_reference);

        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertSame('pending', Transaction::where('order_id', $order->id)->first()->payment_status);
    }
}
