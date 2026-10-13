<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VendorWithdrawalNameQueryTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveBulkclixPayoutGateway(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_BULKCLIX,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_collection' => false,
            'supports_generic' => false,
            'supports_payout' => true,
            'supports_sms' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'api_key' => 'test-api-key',
                'base_url' => 'https://api.bulkclix.com/api/v1',
                'sender_id' => 'XTRA4U',
            ],
            'supported_features' => [
                'mtn_momo' => true,
            ],
        ]);
    }

    public function test_withdrawal_name_query_returns_account_name_when_bulkclix_returns_name(): void
    {
        $this->seedActiveBulkclixPayoutGateway();

        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($vendor, 'vendor');

        Http::fake([
            'https://api.bulkclix.com/api/v1/kyc-api/msisdNameQuery*' => Http::response([
                'message' => 'ok',
                'data' => [
                    'name' => 'JOHN DOE',
                    'phone_number' => '0244123456',
                ],
            ], 200),
        ]);

        $this->postJson(route('vendor.withdrawals.name-query'), [
            'momo_number' => '0244123456',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'name' => 'JOHN DOE',
            ]);
    }

    private function seedActiveMoolrePayoutGateway(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_MOOLRE,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_collection' => false,
            'supports_generic' => false,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'api_user' => 'test-api-user',
                'api_key' => 'test-api-key',
                'account_number' => '1234567890',
                'currency' => 'GHS',
                'base_url' => 'https://api.moolre.com',
            ],
            'supported_features' => [],
        ]);
    }

    public function test_withdrawal_name_query_resolves_name_via_moolre_with_network_channel(): void
    {
        $this->seedActiveMoolrePayoutGateway();

        $this->actingAs(Vendor::factory()->create(['is_approved' => true]), 'vendor');

        Http::fake([
            'https://api.moolre.com/open/transact/validate' => Http::response([
                'status' => 1,
                'code' => 'AVS01',
                'message' => 'Name validated',
                'data' => 'JOHN DOE',
            ], 200),
        ]);

        $this->postJson(route('vendor.withdrawals.name-query'), [
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_TELECEL,
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'name' => 'JOHN DOE',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.moolre.com/open/transact/validate'
            && $request['receiver'] === '0244123456'
            && $request['channel'] === '6'
            && $request->hasHeader('X-API-USER', 'test-api-user'));
    }

    public function test_withdrawal_name_query_via_moolre_requires_network_and_skips_api(): void
    {
        $this->seedActiveMoolrePayoutGateway();

        $this->actingAs(Vendor::factory()->create(['is_approved' => true]), 'vendor');

        Http::fake();

        $this->postJson(route('vendor.withdrawals.name-query'), [
            'momo_number' => '0244123456',
        ])
            ->assertOk()
            ->assertJson(['success' => false, 'name' => null]);

        Http::assertNothingSent();
    }

    public function test_withdrawal_name_query_reports_moolre_validation_failure(): void
    {
        $this->seedActiveMoolrePayoutGateway();

        $this->actingAs(Vendor::factory()->create(['is_approved' => true]), 'vendor');

        Http::fake([
            'https://api.moolre.com/open/transact/validate' => Http::response([
                'status' => 0,
                'message' => 'Invalid account',
                'data' => null,
            ], 200),
        ]);

        $this->postJson(route('vendor.withdrawals.name-query'), [
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
        ])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'name' => null,
                'message' => 'Invalid account',
            ]);
    }

    private function seedActivePaystackPayoutGateway(): void
    {
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_collection' => false,
            'supports_generic' => false,
            'supports_payout' => true,
            'supports_sms' => false,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk_test_x',
                'secret_key' => 'sk_test_x',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ]);
    }

    public function test_withdrawal_name_query_resolves_name_via_paystack(): void
    {
        $this->seedActivePaystackPayoutGateway();

        $this->actingAs(Vendor::factory()->create(['is_approved' => true]), 'vendor');

        Http::fake([
            'https://api.paystack.co/bank/resolve*' => Http::response([
                'status' => true,
                'message' => 'Account number resolved',
                'data' => [
                    'account_number' => '0244123456',
                    'account_name' => 'JOHN DOE',
                    'bank_id' => 1,
                ],
            ], 200),
        ]);

        $this->postJson(route('vendor.withdrawals.name-query'), [
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_TELECEL,
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'name' => 'JOHN DOE',
            ]);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.paystack.co/bank/resolve')
            && $request['account_number'] === '0244123456'
            && $request['bank_code'] === 'VOD'
            && $request->hasHeader('Authorization', 'Bearer sk_test_x'));
    }

    public function test_withdrawal_name_query_reports_paystack_resolve_failure(): void
    {
        $this->seedActivePaystackPayoutGateway();

        $this->actingAs(Vendor::factory()->create(['is_approved' => true]), 'vendor');

        Http::fake([
            'https://api.paystack.co/bank/resolve*' => Http::response([
                'status' => false,
                'message' => 'Could not resolve account name. Check parameters or try again.',
            ], 422),
        ]);

        $this->postJson(route('vendor.withdrawals.name-query'), [
            'momo_number' => '0244123456',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
        ])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'name' => null,
                'message' => 'Could not resolve account name. Check parameters or try again.',
            ]);
    }

    public function test_vendor_withdrawal_request_persists_momo_account_name_and_type(): void
    {
        Queue::fake();
        Mail::fake();

        $this->seedActiveBulkclixPayoutGateway();

        // The controller runs a sync payout attempt; fake BulkClix payout endpoints to keep the test fast/stable.
        Http::fake([
            'https://api.bulkclix.com/api/v1/payment-api/send/mobilemoney' => Http::response([
                'message' => 'Payout initiated',
                'data' => [
                    'transaction_id' => 'TXN-TEST-1',
                    'client_reference' => 'WD-TEST',
                ],
            ], 200),
            'https://api.bulkclix.com/api/v1/payment-api/checkstatus/*' => Http::response([
                'message' => 'processing',
                'data' => [
                    'status' => 'pending',
                ],
            ], 200),
        ]);

        $vendor = Vendor::factory()->create([
            'is_approved' => true,
            'password' => bcrypt('password'),
            'wallet_balance' => 100.00,
        ]);

        $this->actingAs($vendor, 'vendor');

        $this->post(route('vendor.withdrawals.store'), [
            'withdraw_amount' => 10.00,
            'momo_number' => '0244123456',
            'momo_network' => 'MTN',
            'momo_account_name' => 'Jane Doe',
            'momo_account_type' => 'subscriber',
            'notes' => 'Test payout with name',
        ])->assertRedirect();

        $withdrawal = VendorWithdrawal::firstOrFail();

        $this->assertSame('Jane Doe', $withdrawal->momo_account_name);
        $this->assertSame('subscriber', $withdrawal->momo_account_type);
    }
}
