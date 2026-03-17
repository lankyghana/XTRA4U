<?php

namespace Tests\Unit;

use App\Contracts\Gateways\CollectsPayments;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\GatewayManager;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentInitiationAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_payment_falls_back_when_moolre_returns_verification_code_message(): void
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
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_MOOLRE,
        ]);

        $counter = (object) ['calls' => 0];

        $fallbackService = new class($counter) implements CollectsPayments {
            public function __construct(private object $counter)
            {
            }

            public function requestPayment(Order $order, string $email, float $amount): array
            {
                $this->counter->calls++;

                $order->update([
                    'payment_reference' => 'ALT-REF-123',
                    'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
                    'payment_status' => 'pending',
                ]);

                return [
                    'success' => true,
                    'message' => 'Fallback gateway initialized payment.',
                    'reference' => 'ALT-REF-123',
                    'authorization_url' => 'https://example.test/pay',
                ];
            }

            public function verifyPayment(string $reference): array
            {
                return ['success' => false, 'message' => 'Not used'];
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };

        $fakeGateway = new class($fallbackService) extends GatewayManager {
            public function __construct(private CollectsPayments $fallbackService)
            {
            }

            public function collect(Order $order, string $email, float $amount): array
            {
                return [
                    'success' => true,
                    'message' => 'Phone no. Verification Code: 222692',
                    'reference' => 'MOO-REF-123',
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                ];
            }

            public function getAllPaymentServices(): array
            {
                return [
                    PaymentGatewayConfig::GATEWAY_PAYSTACK => [
                        'service' => $this->fallbackService,
                        'config' => null,
                        'name' => 'Paystack',
                    ],
                ];
            }
        };

        $service = new PaymentService($fakeGateway);
        $result = $service->initiatePayment($order, 'noreply@xtra4u.test', 10.00);

        $this->assertTrue($result['success']);
        $this->assertSame('ALT-REF-123', $result['reference']);
        $this->assertSame(PaymentGatewayConfig::GATEWAY_PAYSTACK, $result['gateway_name']);
        $this->assertSame(1, $counter->calls);

        $order->refresh();
        $this->assertSame('ALT-REF-123', $order->payment_reference);
        $this->assertSame(PaymentGatewayConfig::GATEWAY_PAYSTACK, $order->payment_gateway);
    }

    public function test_initiate_payment_does_not_fallback_for_normal_moolre_prompt_message(): void
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
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_MOOLRE,
        ]);

        $counter = (object) ['calls' => 0];

        $fallbackService = new class($counter) implements CollectsPayments {
            public function __construct(private object $counter)
            {
            }

            public function requestPayment(Order $order, string $email, float $amount): array
            {
                $this->counter->calls++;
                return [
                    'success' => true,
                    'message' => 'Fallback initialized.',
                    'reference' => 'ALT-REF-999',
                    'authorization_url' => 'https://example.test/pay',
                ];
            }

            public function verifyPayment(string $reference): array
            {
                return ['success' => false, 'message' => 'Not used'];
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };

        $fakeGateway = new class($fallbackService) extends GatewayManager {
            public function __construct(private CollectsPayments $fallbackService)
            {
            }

            public function collect(Order $order, string $email, float $amount): array
            {
                return [
                    'success' => true,
                    'message' => 'Waiting for MoMo confirmation... Check your phone and enter your PIN.',
                    'reference' => 'MOO-REF-777',
                    'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                ];
            }

            public function getAllPaymentServices(): array
            {
                return [
                    PaymentGatewayConfig::GATEWAY_PAYSTACK => [
                        'service' => $this->fallbackService,
                        'config' => null,
                        'name' => 'Paystack',
                    ],
                ];
            }
        };

        $service = new PaymentService($fakeGateway);
        $result = $service->initiatePayment($order, 'noreply@xtra4u.test', 10.00);

        $this->assertTrue($result['success']);
        $this->assertSame('MOO-REF-777', $result['reference']);
        $this->assertSame(PaymentGatewayConfig::GATEWAY_MOOLRE, $result['gateway_name']);
        $this->assertSame(0, $counter->calls);
    }

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
