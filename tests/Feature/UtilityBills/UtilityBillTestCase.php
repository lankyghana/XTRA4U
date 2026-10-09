<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Order;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillSettings;
use App\Services\UtilityBills\VendorCommission;
use App\Support\PaymentIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

abstract class UtilityBillTestCase extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'https://api.kingflexygh.com/api/v2';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kingflexy_utilities.api_key' => 'kf_cs_live_testkey123',
            'services.kingflexy_utilities.base_url' => self::BASE,
            'queue.default' => 'sync',
            // Flow tests verify many accounts from one session; the customer lookup
            // limiter is covered on its own in UtilityBillScalabilityTest.
            'utility_bills.lookup_limit.per_session_per_minute' => 1000,
            'utility_bills.lookup_limit.per_ip_per_minute' => 1000,
        ]);
        Cache::flush();
        foreach (['billers', 'lookup', 'pay', 'status'] as $e) {
            RateLimiter::clear('utility-bills:provider:'.$e);
        }
    }

    /** Http::fake() stubs accumulate (first match wins); start clean when re-faking. */
    protected function fake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    protected function billersBody(array $overrides = []): array
    {
        $billers = [
            ['key' => 'ecg', 'label' => 'ECG Prepaid & Postpaid', 'enabled' => true, 'account_label' => 'Meter number',
                'requires_phone' => true, 'lookup_by' => 'phone', 'links_phone_to_account' => true, 'has_amount_due' => true],
            ['key' => 'ghana_water', 'label' => 'Ghana Water', 'enabled' => true, 'account_label' => 'Account number',
                'requires_phone' => true, 'lookup_by' => 'account', 'links_phone_to_account' => false, 'has_amount_due' => true],
            ['key' => 'dstv', 'label' => 'DSTV', 'enabled' => true, 'account_label' => 'Smartcard number',
                'requires_phone' => false, 'lookup_by' => 'account', 'links_phone_to_account' => false, 'has_amount_due' => true],
            ['key' => 'gotv', 'label' => 'GOtv', 'enabled' => true, 'account_label' => 'Account number',
                'requires_phone' => false, 'lookup_by' => 'account', 'links_phone_to_account' => false, 'has_amount_due' => true],
            ['key' => 'startimes', 'label' => 'StarTimes', 'enabled' => true, 'account_label' => 'Account number',
                'requires_phone' => false, 'lookup_by' => 'account', 'links_phone_to_account' => false, 'has_amount_due' => true],
        ];

        return ['success' => true, 'data' => array_merge(['billers' => $billers, 'min_amount' => 1, 'max_amount' => 1000, 'currency' => 'GHS'], $overrides)];
    }

    protected function openService(array $enabledBillers = ['ecg', 'ghana_water', 'dstv', 'gotv', 'startimes'], string $type = 'percentage', string $value = '1'): void
    {
        UtilityBillSettings::save(true, null);
        foreach ($enabledBillers as $key) {
            UtilityBillerConfig::create(['biller_key' => $key, 'is_enabled' => true, 'commission_type' => $type, 'commission_value' => $value]);
        }
    }

    /**
     * A utility order the way checkout creates it, optionally with its payment
     * proven through the canonical integrity stamp.
     */
    protected function makeOrder(array $o = []): UtilityBillOrder
    {
        $vendor = array_key_exists('vendor', $o) ? $o['vendor'] : Vendor::factory()->create(['is_approved' => true, 'wallet_balance' => 0]);
        $amount = $o['amount'] ?? '100.00';
        $type = $o['commission_type'] ?? 'percentage';
        $value = $o['commission_value'] ?? '1.0000';
        $commission = $vendor ? VendorCommission::calculate($type, $value, $amount) : '0.00';

        $order = Order::create([
            'recipient_phone_number' => '0551617309',
            'mobile_money_number' => '0551617309',
            'service_purchased' => 'Utility Bill: DSTV',
            'amount_paid' => $amount,
            'base_price' => $amount,
            'markup_price' => '0.00',
            'expected_amount' => $amount,
            'currency' => 'GHS',
            'pricing_snapshot_at' => now(),
            'vendor_id' => null,
            'status' => 'Pending',
            'payment_status' => 'unpaid',
            'payment_gateway' => 'paystack',
            'payment_reference' => 'REF-'.uniqid(),
        ]);

        $u = new UtilityBillOrder;
        $u->forceFill([
            'public_ref' => UtilityBillOrder::newPublicRef(),
            'access_token' => UtilityBillOrder::newAccessToken(),
            'order_id' => $order->id,
            'vendor_id' => $vendor?->id,
            'biller_key' => $o['biller'] ?? 'dstv',
            'biller_label' => 'DSTV',
            'account_number' => $o['account'] ?? '7041234567',
            'account_name' => 'KWAME MENSAH',
            'customer_phone' => $o['phone'] ?? null,
            'bill_amount' => $amount,
            'expected_amount' => $amount,
            'currency' => 'GHS',
            'fulfillment_status' => FulfillmentStatus::AWAITING_PAYMENT,
            'commission_type' => $vendor ? $type : null,
            'commission_value' => $vendor ? $value : null,
            'commission_basis' => 'bill_face_value',
            'commission_basis_amount' => $vendor ? $amount : '0.00',
            'commission_amount' => $commission,
            'commission_status' => $commission !== '0.00' ? 'pending' : 'none',
        ])->save();

        if ($o['paid'] ?? false) {
            $this->prove($order);
            app(\App\Services\PaymentService::class)->completeOrder($order->fresh());
        }

        return $u->fresh();
    }

    /** Stamp what only PaymentIntegrityGuard may write (forceFill, as the guard does). */
    protected function prove(Order $order): void
    {
        $order->forceFill([
            'payment_integrity_status' => PaymentIntegrity::VERIFIED,
            'payment_verified_at' => now(),
            'gateway_confirmed_amount' => $order->expected_amount,
        ])->save();
    }

    protected function payBody(string $providerRef = 'UTIL-DSTV-aaa111', string $status = 'pending'): array
    {
        return ['success' => true, 'data' => ['reference' => $providerRef, 'order_id' => 'uuid-1', 'status' => $status,
            'biller' => 'dstv', 'account' => '7041234567', 'amount' => 100.0, 'commission_share_percent' => 40, 'new_balance' => 400.0]];
    }

    protected function statusBody(string $status, string $providerRef = 'UTIL-DSTV-aaa111', $commission = null): array
    {
        return ['success' => true, 'data' => ['reference' => $providerRef, 'status' => $status, 'payment_status' => 'paid',
            'biller' => 'dstv', 'account_number' => '7041234567', 'amount' => 100.0, 'commission_earned' => $commission, 'reason' => null]];
    }
}
