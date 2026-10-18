<?php

namespace Tests\Feature;

use App\Events\ResultCheckerOrderPaid;
use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\Transaction;
use App\Services\ResultCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A late, duplicated or racing "failed" verification must never move an
 * already-paid Results Checker order to `failed`; a genuinely unpaid order
 * whose payment failed still must.
 */
class ResultCheckerWebhookStatusProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'RC-REF-PROTECT';

    private string $verifyState = 'success';

    private ?\Closure $duringVerify = null;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ResultCheckerOrderPaid::class]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => function () {
                if ($this->duringVerify) {
                    ($this->duringVerify)();
                }

                return Http::response([
                    'status' => true,
                    'data' => ['status' => $this->verifyState, 'amount' => 10000],
                ], 200);
            },
        ]);

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_123', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);
    }

    private function order(array $attrs = [], bool $withStock = true): ResultCheckerOrder
    {
        $service = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 100]);

        if ($withStock) {
            ResultCheckerPin::factory()->create(['service_id' => $service->id]);
        }

        return ResultCheckerOrder::factory()->create(array_merge([
            'service_id' => $service->id,
            'payment_reference' => self::REF,
            'payment_gateway' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'status' => 'pending_payment',
            'total_price' => 100.00,
        ], $attrs));
    }

    /**
     * Http::fake() stubs accumulate (first match wins), so register one stub
     * that reads the current state instead of re-faking per step.
     */
    private function fakeVerify(string $state): void
    {
        $this->verifyState = $state;
    }

    private function webhook()
    {
        return $this->postJson(route('result-checkers.payment.webhook'), ['reference' => self::REF]);
    }

    private function browserCallback(ResultCheckerOrder $order, string $ref = self::REF)
    {
        return $this->postJson(route('result-checkers.payment.callback', ['order' => $order->id]), ['reference' => $ref], ['Accept' => 'application/json']);
    }

    public function test_successful_payment_followed_by_failed_webhook_stays_paid(): void
    {
        $order = $this->order();

        $this->fakeVerify('success');
        $this->webhook()->assertOk();
        $this->assertSame('completed', $order->fresh()->status);

        $this->fakeVerify('failed');
        $this->webhook()->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertCount(1, $fresh->pins);
    }

    public function test_failed_webhook_does_not_fail_a_paid_order_waiting_for_stock(): void
    {
        $order = $this->order(withStock: false);

        $this->fakeVerify('success');
        $this->webhook()->assertOk();
        $this->assertSame('pending_stock', $order->fresh()->status);

        $this->fakeVerify('failed');
        $this->webhook()->assertOk();
        $this->browserCallback($order)->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('pending_stock', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_duplicate_successful_webhooks_record_one_payment_and_one_allocation(): void
    {
        $order = $this->order();
        $this->fakeVerify('success');

        $this->webhook()->assertOk();
        $this->webhook()->assertOk();
        $this->browserCallback($order)->assertOk();

        $this->assertSame(1, Transaction::where('transactionable_id', $order->id)->where('transactionable_type', ResultCheckerOrder::class)->count());
        $this->assertSame(1, ResultCheckerPin::where('result_checker_order_id', $order->id)->count());
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatchedTimes(ResultCheckerOrderPaid::class, 1);
    }

    public function test_payment_landing_between_a_failed_verification_and_its_status_write_wins(): void
    {
        $order = $this->order();

        // The gateway says "failed" for this request, but a success for the same
        // order is processed while that verification response is in flight.
        $this->verifyState = 'failed';
        $this->duringVerify = fn () => app(ResultCheckerService::class)
            ->handlePaymentCallback($order->fresh(), self::REF, PaymentGatewayConfig::GATEWAY_PAYSTACK);

        $this->webhook()->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_the_browser_callback_is_protected_the_same_way(): void
    {
        $order = $this->order();

        $this->verifyState = 'failed';
        $this->duringVerify = fn () => app(ResultCheckerService::class)
            ->handlePaymentCallback($order->fresh(), self::REF, PaymentGatewayConfig::GATEWAY_PAYSTACK);

        $this->browserCallback($order)->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_genuinely_failed_unpaid_order_is_marked_failed(): void
    {
        $order = $this->order();
        $this->fakeVerify('failed');

        $this->webhook()->assertStatus(400);

        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertCount(0, $fresh->pins);

        $other = $this->order(['payment_reference' => 'RC-REF-OTHER']);
        $this->browserCallback($other, 'RC-REF-OTHER')->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame('failed', $other->fresh()->status);
    }

    public function test_a_late_success_still_rescues_an_order_wrongly_failed_earlier(): void
    {
        $order = $this->order();

        $this->fakeVerify('failed');
        $this->webhook()->assertStatus(400);
        $this->assertSame('failed', $order->fresh()->status);

        $this->fakeVerify('success');
        $this->webhook()->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
    }
}
