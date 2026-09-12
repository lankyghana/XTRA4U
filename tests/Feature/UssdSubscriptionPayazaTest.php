<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UssdSubscriptionPayazaTest extends TestCase
{
    use RefreshDatabase;

    protected const CHECK_STATUS_URL = 'https://api.payaza.africa/live/subsidiary/collections/v1/check-status*';

    protected function setUp(): void
    {
        parent::setUp();

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ]);
    }

    protected function starterPlan(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    protected function fakeCheckStatus(string $reference, string $status, float $amount, string $responseCode): void
    {
        Http::fake([
            self::CHECK_STATUS_URL => Http::response([
                'response_code' => $responseCode,
                'transaction_reference' => $reference,
                'transaction_amount' => $amount,
                'transaction_status' => $status,
            ], 200),
        ]);
    }

    // Popup precondition: checkout_config carries the plan's own server-side price.
    public function test_purchase_returns_payaza_checkout_config_with_plan_price(): void
    {
        $vendor = Vendor::factory()->create(['phone_number' => '0244123456']);
        $plan = $this->starterPlan();

        $resp = $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $plan->id]);

        $resp->assertOk()->assertJson(['success' => true, 'flow_type' => 'payaza']);
        $this->assertEquals((float) $plan->price, $resp->json('checkout_config.checkout_amount'));
    }

    // Successful payment activates the subscription via the existing status()
    // endpoint — the same one the InlinePaymentManager poll_url mode already
    // uses for every other inline gateway.
    public function test_successful_payment_activates_subscription(): void
    {
        $vendor = Vendor::factory()->create(['phone_number' => '0244123456']);
        $plan = $this->starterPlan();

        $init = $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $plan->id]);
        $reference = $init->json('reference');

        $this->fakeCheckStatus($reference, 'Completed', (float) $plan->price, '00');

        $status = $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => $reference]));

        $status->assertOk()->assertJson(['success' => true, 'status' => 'paid']);

        $subscription = UssdSubscription::where('payment_reference', $reference)->first();
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->status);
    }

    // Underpayment (gateway-confirmed amount below the plan price) must not activate.
    public function test_amount_mismatch_blocks_activation(): void
    {
        $vendor = Vendor::factory()->create(['phone_number' => '0244123456']);
        $plan = $this->starterPlan();

        $init = $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $plan->id]);
        $reference = $init->json('reference');

        // Attacker only actually paid a fraction of the plan price.
        $this->fakeCheckStatus($reference, 'Completed', 1.0, '00');

        $status = $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => $reference]));

        $status->assertOk();
        $this->assertNotSame('paid', $status->json('status'));

        $subscription = UssdSubscription::where('payment_reference', $reference)->first();
        $this->assertNotSame(UssdSubscription::STATUS_ACTIVE, $subscription->status);
    }

    // Duplicate settlement (status polled twice after success) must not double-activate
    // or double-consume the allowance.
    public function test_duplicate_settlement_does_not_activate_twice(): void
    {
        $vendor = Vendor::factory()->create(['phone_number' => '0244123456']);
        $plan = $this->starterPlan();

        $init = $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $plan->id]);
        $reference = $init->json('reference');

        $this->fakeCheckStatus($reference, 'Completed', (float) $plan->price, '00');

        $first = $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => $reference]));
        $first->assertJson(['status' => 'paid']);

        $subscription = UssdSubscription::where('payment_reference', $reference)->first();
        $codeAfterFirst = $subscription->fresh()->ussd_code;
        $sessionsAfterFirst = $subscription->fresh()->total_sessions;

        $second = $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => $reference]));
        $second->assertJson(['status' => 'paid']);

        $this->assertSame($codeAfterFirst, $subscription->fresh()->ussd_code);
        $this->assertSame($sessionsAfterFirst, $subscription->fresh()->total_sessions);
    }
}
