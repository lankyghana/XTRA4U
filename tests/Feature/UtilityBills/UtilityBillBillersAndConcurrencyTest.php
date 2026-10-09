<?php

namespace Tests\Feature\UtilityBills;

use App\Models\PaymentGatewayConfig;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Models\WalletLedger;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\ProviderRateBudget;
use App\Services\UtilityBills\UtilityBillCommissionService;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UtilityBillBillersAndConcurrencyTest extends UtilityBillTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    }

    private function lookupBody(array $data): array
    {
        return ['success' => true, 'data' => $data + ['account_name' => null, 'account_number' => null, 'amount_due' => null, 'bouquet' => null, 'meters' => []]];
    }

    public function test_each_supported_biller_looks_up_with_its_provider_defined_fields(): void
    {
        $this->openService();
        $cases = [
            ['ghana_water', ['account' => '0123456', 'phone' => '0551617309'], ['account_name' => 'ABENA OWUSU', 'account_number' => '0123456', 'amount_due' => 80], ['account' => '0123456', 'phone' => '0551617309']],
            ['dstv', ['account' => '7041234567'], ['account_name' => 'KWAME MENSAH', 'account_number' => '7041234567', 'amount_due' => 65, 'bouquet' => 'DStv Compact'], ['account' => '7041234567']],
            ['gotv', ['account' => '8001234567'], ['account_name' => 'YAW BOAKYE', 'account_number' => '8001234567', 'amount_due' => 30, 'bouquet' => 'GOtv Max'], ['account' => '8001234567']],
            ['startimes', ['account' => '9001234567'], ['account_name' => 'EFUA ADDO', 'account_number' => '9001234567', 'amount_due' => -5], ['account' => '9001234567']],
        ];

        foreach ($cases as [$biller, $input, $provider, $expectedQuery]) {
            app(ProviderRateBudget::class)->clear('lookup');
            $this->fake([
                self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
                self::BASE.'/utilities/lookup*' => Http::response($this->lookupBody($provider)),
            ]);

            $r = $this->postJson(route('utility-bills.lookup'), ['biller' => $biller] + $input)->assertOk();
            $r->assertJson(['account_name' => $provider['account_name']]);

            Http::assertSent(function (Request $q) use ($biller, $expectedQuery) {
                if (! str_contains($q->url(), '/utilities/lookup')) {
                    return false;
                }
                parse_str((string) parse_url($q->url(), PHP_URL_QUERY), $query);

                return $query['biller'] === $biller && $query['account'] === $expectedQuery['account']
                    && ($query['phone'] ?? null) === ($expectedQuery['phone'] ?? null);
            });
        }

        // StarTimes' negative amount_due is shown as credit, not as a debt.
        $this->assertSame('5.00', $r->json('account_credit'));
        $this->assertNull($r->json('amount_owing'));
    }

    public function test_identical_lookups_are_served_from_cache_within_the_provider_rate_limit(): void
    {
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response($this->lookupBody(['account_name' => 'KWAME MENSAH', 'account_number' => '7041234567'])),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])->assertOk();
        }

        $this->assertSame(1, Http::recorded(fn (Request $q) => str_contains($q->url(), '/utilities/lookup'))->count());
    }

    public function test_when_the_provider_budget_is_exhausted_customers_get_a_friendly_message(): void
    {
        config(['utility_bills.rate.lookup_per_minute' => 1]);
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response($this->lookupBody(['account_name' => 'A B'])),
        ]);

        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '1111111'])->assertOk();
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '2222222'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Account verification is busy right now. Please try again in a minute.']);
    }

    public function test_provider_limits_come_from_the_live_catalog_not_a_platform_constant(): void
    {
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody(['min_amount' => 5, 'max_amount' => 50])),
            self::BASE.'/utilities/lookup*' => Http::response($this->lookupBody(['account_name' => 'A B', 'account_number' => '7041234567'])),
            'https://api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://x.test']]),
        ]);
        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK, 'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk', 'secret_key' => 'sk', 'payment_url' => 'https://api.paystack.co'], 'supported_features' => [],
        ]);

        $token = $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])->json('token');

        $this->postJson(route('utility-bills.checkout'), ['lookup_token' => $token, 'amount' => '4.99'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson(route('utility-bills.checkout'), ['lookup_token' => $token, 'amount' => '50.01'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson(route('utility-bills.checkout'), ['lookup_token' => $token, 'amount' => '5'])->assertOk();
        $this->assertSame('5.00', (string) UtilityBillOrder::sole()->bill_amount);
    }

    public function test_malformed_provider_payloads_never_create_a_verified_account(): void
    {
        $this->openService(['dstv']);

        foreach ([
            ['success' => true, 'data' => []],
            ['success' => false, 'data' => ['account_name' => 'X Y']],
            ['data' => ['account_name' => 'X Y']],
            ['success' => true, 'data' => 'nope'],
        ] as $payload) {
            app(ProviderRateBudget::class)->clear('lookup');
            $this->fake([
                self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
                self::BASE.'/utilities/lookup*' => Http::response($payload),
            ]);
            $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])->assertStatus(502);
        }
    }

    public function test_simulated_simultaneous_settlement_credits_once(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 10]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody())]);
        $u = $this->makeOrder(['vendor' => $vendor, 'paid' => true, 'amount' => '200.00', 'commission_value' => '0.5']);
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::COMPLETED])->save();

        // Two "workers" that both loaded the order before either settled.
        $svc = app(UtilityBillCommissionService::class);
        $results = [$svc->settle($u->id), $svc->settle($u->id), $svc->settle($u->id)];

        $this->assertSame([UtilityBillCommissionService::CREDITED, UtilityBillCommissionService::ALREADY, UtilityBillCommissionService::ALREADY], $results);
        $this->assertSame('11.00', (string) $vendor->fresh()->wallet_balance);   // 10 + 0.5% of 200
        $this->assertSame(1, WalletLedger::count());
        $this->assertSame('11.00', number_format(WalletLedger::first()->balance_after, 2, '.', ''));
    }

    public function test_the_unique_ledger_reference_backstops_a_double_credit(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-1'))]);
        $one = $this->makeOrder(['vendor' => $vendor, 'paid' => true]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-2'))]);
        $two = $this->makeOrder(['vendor' => $vendor, 'paid' => true]);

        $one->forceFill(['commission_wallet_ledger_id' => 77])->save();

        $this->expectException(QueryException::class);
        $two->forceFill(['commission_wallet_ledger_id' => 77])->save();
    }

    public function test_global_disable_never_abandons_existing_obligations(): void
    {
        $this->openService(['dstv']);
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400)]);
        $u = $this->makeOrder(['paid' => true])->refresh();
        $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);

        // Admin switches the whole service and the biller off.
        UtilityBillSettings::save(false, null);
        UtilityBillerConfig::query()->update(['is_enabled' => false]);

        // The already-paid order still recovers and completes, and the vendor is paid.
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-LATE'))]);
        $this->assertTrue(app(UtilityBillFulfillmentService::class)->adminRetry($u->id, ['id' => 1])['ok']);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-DSTV-LATE'))]);
        app(UtilityBillFulfillmentService::class)->syncStatus($u->id);

        $this->assertSame(FulfillmentStatus::COMPLETED, $u->fresh()->fulfillment_status);
        $this->assertSame('credited', $u->fresh()->commission_status);
    }

    public function test_provider_key_is_never_present_in_any_public_response(): void
    {
        $this->openService(['dstv']);
        $this->fake([
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => false, 'message' => 'kf_cs_live_testkey123 is not allowed'], 403),
        ]);

        $page = $this->get('/services/utility-bills')->assertOk()->getContent();
        $json = $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])->getContent();

        foreach ([$page, $json] as $body) {
            $this->assertStringNotContainsString('kf_cs_', $body);
            $this->assertStringNotContainsString('testkey123', $body);
        }
    }
}
