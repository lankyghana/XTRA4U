<?php

namespace Tests\Feature\UtilityBills;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UtilityBillPublicFlowTest extends UtilityBillTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true, 'supports_generic' => true, 'supports_payout' => true, 'supports_sms' => false,
            'is_active' => true, 'is_default' => true, 'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => ['public_key' => 'pk_test_123', 'secret_key' => 'sk_test_123', 'payment_url' => 'https://api.paystack.co'],
            'supported_features' => [],
        ]);

        $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    }

    private function fakeAll(array $extra = []): void
    {
        $this->fake($extra + [
            self::BASE.'/utilities/billers' => Http::response($this->billersBody()),
            self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => [
                'account_name' => null, 'account_number' => null, 'amount_due' => null, 'bouquet' => null,
                'meters' => [
                    ['name' => 'KWAME MENSAH', 'meterNumber' => '3701234567', 'outstanding' => 245.8],
                    ['name' => 'AMA SERWAA', 'meterNumber' => '3709999999', 'outstanding' => -20],
                ],
            ]]),
            self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-ECG-111')),
            'https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.example/pay']]),
        ]);
    }

    private function lookupEcg(?Vendor $vendor = null): array
    {
        $url = $vendor ? route('storefront.utility-bills.lookup', $vendor->vendor_code) : route('utility-bills.lookup');
        $r = $this->postJson($url, ['biller' => 'ecg', 'phone' => '0551617309']);
        $r->assertOk();

        return $r->json();
    }

    private function checkoutUrl(?Vendor $vendor): string
    {
        return $vendor ? route('storefront.utility-bills.checkout', $vendor->vendor_code) : route('utility-bills.checkout');
    }

    // ---- availability / pages -----------------------------------------

    public function test_page_lists_only_billers_enabled_by_provider_admin_and_service(): void
    {
        $this->openService(['ecg', 'dstv']);
        $this->fakeAll([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        // DSTV disabled by provider.
        $body = $this->billersBody();
        $body['data']['billers'][2]['enabled'] = false;
        $this->fake([self::BASE.'/utilities/billers' => Http::response($body)]);

        $this->get('/services/utility-bills')->assertOk()
            ->assertSee('ECG Prepaid')
            ->assertDontSee('DSTV')           // provider-disabled, even though admin enabled it
            ->assertDontSee('Ghana Water');   // never enabled by admin
    }

    public function test_service_disabled_unconfigured_or_empty_shows_unavailable(): void
    {
        $this->fakeAll();
        $this->get('/services/utility-bills')->assertStatus(503);       // off by default

        $this->openService(['ecg']);
        config(['services.kingflexy_utilities.api_key' => '']);
        $this->get('/services/utility-bills')->assertStatus(503);
    }

    public function test_vendor_storefront_shows_utility_bills_automatically_and_hides_when_closed(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->fakeAll();

        $this->get('/store/'.$vendor->vendor_code)->assertOk()->assertDontSee('utility_bills_service');

        $this->openService(['ecg']);
        $this->get('/store/'.$vendor->vendor_code)->assertOk()
            ->assertSee('utility_bills_service')
            ->assertSee('Pay electricity, water and TV bills')
            ->assertSee(str_replace('/', '\/', '/store/'.$vendor->vendor_code.'/utility-bills'), false);

        $this->get('/store/'.$vendor->vendor_code.'/utility-bills')->assertOk()->assertSee('Utility Bills');
    }

    public function test_unapproved_vendor_storefront_page_is_not_found(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => false]);
        $this->openService(['ecg']);
        $this->fakeAll();

        $this->get('/store/'.$vendor->vendor_code.'/utility-bills')->assertNotFound();
    }

    public function test_legacy_ecg_url_still_redirects(): void
    {
        $this->get('/services/ecg')->assertStatus(301)->assertRedirect(route('services.utility-bills'));
    }

    // ---- lookup --------------------------------------------------------

    public function test_ecg_lookup_returns_all_meters_masked_and_never_preselects(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();

        $j = $this->lookupEcg();

        $this->assertCount(2, $j['meters']);
        $this->assertSame('••••4567', $j['meters'][0]['meter_masked']);
        $this->assertSame('245.80', $j['meters'][0]['amount_owing']);
        // Negative outstanding is a CREDIT, not an amount owing.
        $this->assertNull($j['meters'][1]['amount_owing']);
        $this->assertSame('20.00', $j['meters'][1]['account_credit']);
        $this->assertStringNotContainsString('3701234567', json_encode($j));
        $this->assertStringNotContainsString('kf_cs_', json_encode($j));
    }

    public function test_lookup_validates_by_provider_capabilities(): void
    {
        $this->openService(['ghana_water', 'dstv']);
        $this->fakeAll();

        // Ghana Water needs account + phone.
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'ghana_water', 'account' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        // DSTV needs only the smartcard.
        $this->fakeAll([self::BASE.'/utilities/lookup*' => Http::response(['success' => true, 'data' => [
            'account_name' => 'KWAME MENSAH', 'account_number' => '7041234567', 'amount_due' => 65, 'bouquet' => 'Compact', 'meters' => []]])]);
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])
            ->assertOk()->assertJson(['account_name' => 'KWAME MENSAH', 'bouquet' => 'Compact', 'amount_owing' => '65.00']);
    }

    public function test_lookup_for_a_disabled_biller_is_refused_and_not_found_is_friendly(): void
    {
        $this->openService(['dstv']);
        $this->fakeAll();

        $this->postJson(route('utility-bills.lookup'), ['biller' => 'gotv', 'account' => '7041234567'])->assertStatus(422);

        $this->fakeAll([self::BASE.'/utilities/lookup*' => Http::response(['success' => false], 404)]);
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234567'])
            ->assertStatus(422)->assertJsonValidationErrors('account');

        $this->fakeAll([self::BASE.'/utilities/lookup*' => Http::response('oops', 200)]);
        $this->postJson(route('utility-bills.lookup'), ['biller' => 'dstv', 'account' => '7041234568'])
            ->assertStatus(502);
    }

    // ---- order creation / security ------------------------------------

    public function test_storefront_checkout_freezes_server_side_terms_and_attribution(): void
    {
        $vendorA = Vendor::factory()->create(['is_approved' => true]);
        $this->openService(['ecg'], 'percentage', '1.5');
        $this->fakeAll();

        $j = $this->lookupEcg($vendorA);
        $r = $this->postJson($this->checkoutUrl($vendorA), [
            'lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id'], 'amount' => '100',
            // Hostile extras that must be ignored entirely:
            'vendor_id' => 999, 'biller' => 'dstv', 'account' => '0000000000', 'expected_amount' => '1', 'commission_amount' => '99',
        ]);

        $r->assertOk()->assertJson(['success' => true, 'redirect' => 'https://paystack.example/pay']);

        $u = UtilityBillOrder::sole();
        $this->assertSame($vendorA->id, $u->vendor_id);
        $this->assertSame('ecg', $u->biller_key);
        $this->assertSame('3701234567', $u->account_number);
        $this->assertSame('KWAME MENSAH', $u->account_name);
        $this->assertSame('0551617309', $u->customer_phone);
        $this->assertSame('100.00', (string) $u->bill_amount);
        $this->assertSame('percentage', $u->commission_type);
        $this->assertSame('1.5000', (string) $u->commission_value);
        $this->assertSame('bill_face_value', $u->commission_basis);
        $this->assertSame('1.50', (string) $u->commission_amount);
        $this->assertSame('pending', $u->commission_status);
        $this->assertSame(FulfillmentStatus::AWAITING_PAYMENT, $u->fulfillment_status);

        $order = $u->order;
        $this->assertNull($order->vendor_id);
        $this->assertSame('100.00', (string) $order->expected_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('pending_verification', $order->payment_integrity_status);

        // Nothing was sent to the provider's pay endpoint.
        Http::assertNotSent(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'));

        // Admin changes the rate later: the existing order stays frozen.
        \App\Models\UtilityBillerConfig::where('biller_key', 'ecg')->update(['commission_value' => '9']);
        $this->assertSame('1.5000', (string) $u->fresh()->commission_value);
    }

    public function test_direct_purchase_has_no_vendor_and_no_commission(): void
    {
        $this->openService(['ecg'], 'percentage', '2');
        $this->fakeAll();

        $j = $this->lookupEcg();
        $this->postJson($this->checkoutUrl(null), ['lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id'], 'amount' => '50'])
            ->assertOk();

        $u = UtilityBillOrder::sole();
        $this->assertNull($u->vendor_id);
        $this->assertNull($u->commission_type);
        $this->assertSame('0.00', (string) $u->commission_amount);
        $this->assertSame('none', $u->commission_status);
    }

    public function test_a_lookup_cannot_be_replayed_at_another_storefront_or_by_another_session(): void
    {
        $a = Vendor::factory()->create(['is_approved' => true]);
        $b = Vendor::factory()->create(['is_approved' => true]);
        $this->openService(['ecg']);
        $this->fakeAll();

        $j = $this->lookupEcg($a);
        $payload = ['lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id'], 'amount' => '10'];

        // Vendor A's verified lookup submitted to vendor B's storefront / the direct page.
        $this->postJson($this->checkoutUrl($b), $payload)->assertStatus(422)->assertJsonValidationErrors('lookup_token');
        $this->postJson($this->checkoutUrl(null), $payload)->assertStatus(422)->assertJsonValidationErrors('lookup_token');

        // A different browser session.
        $this->withCookie(config('session.cookie'), Str::random(40));
        $this->postJson($this->checkoutUrl($a), $payload)->assertStatus(422)->assertJsonValidationErrors('lookup_token');

        $this->assertSame(0, UtilityBillOrder::count());
    }

    public function test_meter_selection_is_mandatory_and_substitution_is_rejected(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $j = $this->lookupEcg();
        $url = $this->checkoutUrl(null);

        // No meter chosen: never auto-select the first one.
        $this->postJson($url, ['lookup_token' => $j['token'], 'amount' => '10'])->assertStatus(422)->assertJsonValidationErrors('selected_meter');
        // A raw meter number / made-up id instead of one of the provider's.
        $this->postJson($url, ['lookup_token' => $j['token'], 'selected_meter_id' => '3701234567', 'amount' => '10'])->assertStatus(422);
        $this->postJson($url, ['lookup_token' => $j['token'], 'selected_meter_id' => str_repeat('a', 32), 'amount' => '10'])->assertStatus(422);

        $this->assertSame(0, UtilityBillOrder::count());
    }

    public function test_amount_is_validated_server_side_against_provider_limits(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $j = $this->lookupEcg();
        $base = ['lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id']];

        foreach (['0.50', '1000.01', '-5', 'abc', '10.999', ''] as $bad) {
            $this->postJson($this->checkoutUrl(null), $base + ['amount' => $bad])->assertStatus(422);
        }
        $this->postJson($this->checkoutUrl(null), $base + ['amount' => '1000'])->assertOk();
        $this->assertSame('1000.00', (string) UtilityBillOrder::sole()->bill_amount);
    }

    public function test_global_or_biller_disable_stops_new_orders_only(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $j = $this->lookupEcg();
        $payload = ['lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id'], 'amount' => '10'];

        \App\Services\UtilityBills\UtilityBillSettings::save(false, 'Back soon');
        $this->postJson($this->checkoutUrl(null), $payload)->assertStatus(422)->assertJson(['message' => 'Back soon']);

        \App\Services\UtilityBills\UtilityBillSettings::save(true, null);
        \App\Models\UtilityBillerConfig::where('biller_key', 'ecg')->update(['is_enabled' => false]);
        $this->postJson($this->checkoutUrl(null), $payload)->assertStatus(422);
        $this->assertSame(0, UtilityBillOrder::count());
    }

    public function test_repeat_submission_with_the_same_idempotency_key_creates_one_order(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $j = $this->lookupEcg();
        $payload = ['lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id'], 'amount' => '10', 'idempotency_key' => 'k-1'];

        $this->postJson($this->checkoutUrl(null), $payload)->assertOk();
        $second = $this->postJson($this->checkoutUrl(null), $payload);

        $second->assertOk()->assertJson(['status' => 'confirming']);
        $this->assertSame(1, UtilityBillOrder::count());
        $this->assertSame(1, Order::count());
    }

    // ---- payment integrity -> fulfillment ------------------------------

    private function placeOrder(?Vendor $vendor = null, string $amount = '100'): UtilityBillOrder
    {
        $j = $this->lookupEcg($vendor);
        $this->postJson($this->checkoutUrl($vendor), ['lookup_token' => $j['token'], 'selected_meter_id' => $j['meters'][0]['id'], 'amount' => $amount])->assertOk();

        return UtilityBillOrder::latest('id')->first();
    }

    public function test_verified_payment_triggers_fulfillment_and_status_page_is_honest(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->openService(['ecg']);
        $this->fakeAll();
        $u = $this->placeOrder($vendor);
        $ref = $u->order->payment_reference;
        $this->assertNotEmpty($ref);

        // Status page before payment: no "successful".
        $this->get($u->statusUrl())->assertOk()->assertDontSee('Utility payment successful')->assertSee('Waiting for your payment')
            ->assertDontSee('3701234567')->assertSee('4567');

        $this->fakeAll([
            'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10000, 'currency' => 'GHS', 'reference' => $ref]]),
        ]);
        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()->assertJson(['status' => 'success', 'redirect' => $u->statusUrl()]);

        $u->refresh();
        $this->assertSame('paid', $u->order->payment_status);
        $this->assertSame(FulfillmentStatus::PROVIDER_PENDING, $u->fulfillment_status);
        $this->assertSame('verified', $u->order->payment_integrity_status);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay') && $q['biller'] === 'ecg'
            && $q['account'] === '3701234567' && $q['phone'] === '0551617309' && (float) $q['amount'] === 100.0);

        // Paid but provider not done: still not "successful".
        $this->get($u->statusUrl())->assertOk()->assertSee('Payment received')->assertDontSee('Utility payment successful');

        // Verifying again must not pay the provider twice.
        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk();
        $this->assertSame(1, Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'))->count());

        // Provider completes.
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', 'UTIL-ECG-111', 1.3))]);
        app(\App\Services\UtilityBills\UtilityBillFulfillmentService::class)->syncStatus($u->id);

        $this->get($u->statusUrl())->assertOk()->assertSee('Utility payment successful');
        $this->assertSame('credited', $u->fresh()->commission_status);
        $this->getJson(route('utility-bills.poll', $u->access_token))->assertJson(['stage' => 'completed', 'terminal' => true]);
    }

    public function test_underpayment_is_refused_and_never_fulfils(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $u = $this->placeOrder(null, '100');
        $ref = $u->order->payment_reference;

        $this->fakeAll(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 5000, 'currency' => 'GHS', 'reference' => $ref]])]);
        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertOk()->assertJson(['status' => 'failed']);

        $u->refresh();
        $this->assertNotSame('paid', $u->order->payment_status);
        $this->assertSame(FulfillmentStatus::AWAITING_PAYMENT, $u->fulfillment_status);
        Http::assertNotSent(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'));
    }

    public function test_failed_or_pending_gateway_verification_never_fulfils(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $u = $this->placeOrder();
        $ref = $u->order->payment_reference;

        $this->fakeAll(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']])]);
        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertJson(['status' => 'pending']);
        Http::assertNotSent(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'));

        $this->fakeAll(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed']])]);
        $this->postJson(route('utility-bills.verify'), ['reference' => $ref])->assertJson(['status' => 'failed']);
        Http::assertNotSent(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'));
        $this->assertSame(FulfillmentStatus::AWAITING_PAYMENT, $u->fresh()->fulfillment_status);
    }

    public function test_status_page_requires_the_opaque_token(): void
    {
        $this->get('/utility-bills/status/short')->assertNotFound();
        $this->get('/utility-bills/status/'.str_repeat('a', 40))->assertNotFound();
        $this->getJson(route('utility-bills.verify'), [])->assertStatus(405);
    }

    public function test_gateway_callback_redirects_utility_orders_to_their_status_page(): void
    {
        $this->openService(['ecg']);
        $this->fakeAll();
        $u = $this->placeOrder();

        // The sequential-id success page must NEVER reveal or redirect to the opaque token.
        $this->get(route('checkout.success', ['order' => $u->order_id]))->assertNotFound();

        // The gateway callback (keyed by the gateway reference) sends the customer to the token URL.
        $ref = $u->order->payment_reference;
        $this->fakeAll(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10000, 'currency' => 'GHS', 'reference' => $ref]])]);
        $this->get('/payment/callback?reference='.$ref)->assertRedirect($u->statusUrl());
        $this->assertSame('paid', $u->order->fresh()->payment_status);
    }
}
