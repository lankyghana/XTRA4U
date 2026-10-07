<?php

namespace Tests\Feature\UtilityBills;

use App\Services\UtilityBills\Exceptions\NotConfigured;
use App\Services\UtilityBills\Exceptions\ProviderInsufficientBalance;
use App\Services\UtilityBills\Exceptions\ProviderMalformedResponse;
use App\Services\UtilityBills\Exceptions\ProviderNotFound;
use App\Services\UtilityBills\Exceptions\ProviderRateLimited;
use App\Services\UtilityBills\Exceptions\ProviderServiceDisabled;
use App\Services\UtilityBills\Exceptions\ProviderUnauthorized;
use App\Services\UtilityBills\Exceptions\ProviderUnreachable;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class KingFlexyProviderTest extends TestCase
{
    private const BASE = 'https://api.kingflexygh.com/api/v2';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kingflexy_utilities.api_key' => 'kf_cs_live_testkey123']);
        config(['services.kingflexy_utilities.base_url' => self::BASE]);
        Cache::flush();
        RateLimiter::clear('utility-bills:provider:lookup');
    }

    /** Http::fake() stubs accumulate (first match wins); start clean when re-faking. */
    private function refake(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function billersPayload(): array
    {
        return ['success' => true, 'data' => [
            'billers' => [
                ['key' => 'ecg', 'label' => 'ECG Prepaid & Postpaid', 'enabled' => true, 'account_label' => 'Meter number',
                    'requires_phone' => true, 'lookup_by' => 'phone', 'links_phone_to_account' => true, 'has_amount_due' => true],
                ['key' => 'dstv', 'label' => 'DSTV', 'enabled' => false, 'account_label' => 'Smartcard number',
                    'requires_phone' => false, 'lookup_by' => 'account', 'links_phone_to_account' => false, 'has_amount_due' => true],
            ],
            'min_amount' => 1, 'max_amount' => 1000, 'currency' => 'GHS',
        ]];
    }

    public function test_catalog_is_parsed_from_provider_capabilities_and_sent_with_the_raw_key(): void
    {
        Http::fake([self::BASE.'/utilities/billers' => Http::response($this->billersPayload())]);

        $catalog = app(KingFlexyUtilityProvider::class)->catalog();

        $this->assertTrue($catalog->biller('ecg')->enabled);
        $this->assertFalse($catalog->biller('dstv')->enabled);
        $this->assertSame('phone', $catalog->biller('ecg')->lookupBy);
        $this->assertTrue($catalog->biller('ecg')->requiresPhone);
        $this->assertSame('Meter number', $catalog->biller('ecg')->accountLabel);
        $this->assertSame('1.00', $catalog->minAmount);
        $this->assertSame('1000.00', $catalog->maxAmount);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'kf_cs_live_testkey123'));
    }

    public function test_catalog_is_cached_briefly(): void
    {
        Http::fake([self::BASE.'/utilities/billers' => Http::response($this->billersPayload())]);
        $provider = app(KingFlexyUtilityProvider::class);

        $provider->catalog();
        $provider->catalog();

        Http::assertSentCount(1);
    }

    public function test_a_normal_data_key_is_refused_without_calling_the_provider(): void
    {
        config(['services.kingflexy_utilities.api_key' => 'kf_live_normaldatakey']);
        Http::fake();

        $this->assertFalse(app(KingFlexyUtilityProvider::class)->isConfigured());
        $this->expectException(NotConfigured::class);

        try {
            app(KingFlexyUtilityProvider::class)->catalog();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_ecg_lookup_returns_every_linked_meter(): void
    {
        Http::fake([self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => [
            'account_name' => null, 'account_number' => null, 'amount_due' => null, 'bouquet' => null,
            'meters' => [
                ['name' => 'KWAME MENSAH', 'meterNumber' => '3701234567', 'outstanding' => 245.8],
                ['name' => 'AMA <b>SERWAA</b>', 'meterNumber' => '3709999999', 'outstanding' => -20],
            ],
        ]])]);

        $result = app(KingFlexyUtilityProvider::class)->lookup('ecg', '0551617309', '0551617309');

        $this->assertCount(2, $result->meters);
        $this->assertSame('3701234567', $result->meters[0]['meterNumber']);
        $this->assertSame('245.80', $result->meters[0]['outstanding']);
        // Provider text is untrusted: markup is stripped.
        $this->assertSame('AMA SERWAA', $result->meters[1]['name']);
        $this->assertSame('-20.00', $result->meters[1]['outstanding']);
        $this->assertNotNull($result->meter('3709999999'));
        $this->assertNull($result->meter('0000000000'));
    }

    public function test_standard_lookup_and_negative_amount_due_is_a_credit(): void
    {
        Http::fake([self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => [
            'account_name' => 'KWAME MENSAH', 'account_number' => '7041234567', 'amount_due' => -20, 'bouquet' => 'DStv Compact', 'meters' => [],
        ]])]);

        $result = app(KingFlexyUtilityProvider::class)->lookup('dstv', '7041234567');

        $this->assertSame('KWAME MENSAH', $result->accountName);
        $this->assertSame('DStv Compact', $result->bouquet);
        $this->assertNull(\App\Services\UtilityBills\Data\LookupResult::owing($result->amountDue));
        $this->assertSame('20.00', \App\Services\UtilityBills\Data\LookupResult::credit($result->amountDue));
    }

    public function test_lookup_not_found_malformed_and_unavailable(): void
    {
        $provider = app(KingFlexyUtilityProvider::class);

        $this->refake([self::BASE.'/utilities/lookup*' => Http::response(['success' => false, 'message' => 'not found'], 404)]);
        try {
            $provider->lookup('dstv', '1');
            $this->fail('expected not found');
        } catch (ProviderNotFound) {
            $this->addToAssertionCount(1);
        }

        RateLimiter::clear('utility-bills:provider:lookup');
        $this->refake([self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['meters' => [['name' => 'x']]]])]);
        try {
            $provider->lookup('ecg', '0551617309', '0551617309');
            $this->fail('expected malformed');
        } catch (ProviderMalformedResponse) {
            $this->addToAssertionCount(1);
        }

        RateLimiter::clear('utility-bills:provider:lookup');
        $this->refake([self::BASE.'/utilities/lookup*' => Http::response('<html>bad gateway</html>', 200)]);
        try {
            $provider->lookup('dstv', '7041234567');
            $this->fail('expected malformed');
        } catch (ProviderMalformedResponse) {
            $this->addToAssertionCount(1);
        }

        RateLimiter::clear('utility-bills:provider:lookup');
        $this->refake([self::BASE.'/utilities/lookup*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(ProviderUnreachable::class);
        $provider->lookup('dstv', '7041234567');
    }

    public function test_pay_sends_our_reference_and_returns_the_providers_own_reference(): void
    {
        Http::fake([self::BASE.'/utilities/pay' => Http::response(['success' => true, 'data' => [
            'reference' => 'UTIL-DSTV-3f9a2b1c4d5e6f70', 'order_id' => 'uuid-1', 'status' => 'pending', 'biller' => 'dstv',
            'account' => '7041234567', 'amount' => 65.00, 'commission_share_percent' => 40, 'new_balance' => 435.00,
        ]])]);

        $result = app(KingFlexyUtilityProvider::class)->pay('dstv', '7041234567', '65.00', 'XU-UB-REF1');

        $this->assertSame('UTIL-DSTV-3f9a2b1c4d5e6f70', $result->providerReference);
        $this->assertSame('pending', $result->status);
        $this->assertFalse($result->alreadyProcessed);
        $this->assertSame('40.00', $result->commissionSharePercent);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r['reference'] === 'XU-UB-REF1'
                && $r['biller'] === 'dstv'
                && $r['account'] === '7041234567'
                && (float) $r['amount'] === 65.0
                && ! isset($r['phone']);
        });
    }

    public function test_pay_error_mapping(): void
    {
        $provider = app(KingFlexyUtilityProvider::class);
        $cases = [
            [400, ['success' => false, 'message' => 'Insufficient wallet balance'], ProviderInsufficientBalance::class],
            [429, ['success' => false, 'message' => 'slow down'], ProviderRateLimited::class],
            [503, ['success' => false, 'message' => 'disabled'], ProviderServiceDisabled::class],
            [403, ['success' => false, 'message' => 'wrong key type'], ProviderUnauthorized::class],
        ];

        foreach ($cases as [$status, $body, $class]) {
            RateLimiter::clear('utility-bills:provider:pay');
            $this->refake([self::BASE.'/utilities/pay' => Http::response($body, $status)]);
            try {
                $provider->pay('dstv', '7041234567', '65.00', 'XU-UB-REF1');
                $this->fail("expected {$class}");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e);
            }
        }
    }

    public function test_pay_timeout_is_flagged_ambiguous(): void
    {
        Http::fake([self::BASE.'/utilities/pay' => fn () => throw new ConnectionException('timeout')]);

        try {
            app(KingFlexyUtilityProvider::class)->pay('dstv', '7041234567', '65.00', 'XU-UB-REF1');
            $this->fail('expected timeout');
        } catch (ProviderUnreachable $e) {
            $this->assertTrue($e->isAmbiguous());
        }
    }

    public function test_status_is_parsed(): void
    {
        Http::fake([self::BASE.'/utilities/orders/*' => Http::response(['success' => true, 'data' => [
            'reference' => 'UTIL-DSTV-3f9a2b1c4d5e6f70', 'status' => 'completed', 'payment_status' => 'paid',
            'biller' => 'dstv', 'account_number' => '7041234567', 'amount' => 65, 'commission_earned' => 1.3, 'reason' => null,
        ]])]);

        $result = app(KingFlexyUtilityProvider::class)->status('UTIL-DSTV-3f9a2b1c4d5e6f70');

        $this->assertSame('completed', $result->status);
        $this->assertSame('1.30', $result->commissionEarned);
    }

    public function test_local_budget_stops_requests_before_the_provider_limit(): void
    {
        config(['utility_bills.rate.lookup_per_minute' => 2]);
        Http::fake([self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => ['account_name' => 'A B', 'meters' => []]])]);
        $provider = app(KingFlexyUtilityProvider::class);

        $provider->lookup('dstv', '1111111');
        $provider->lookup('dstv', '2222222');

        $this->expectException(ProviderRateLimited::class);
        $provider->lookup('dstv', '3333333');
    }
}
