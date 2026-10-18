<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResultCheckerOrder;
use App\Models\Setting;
use App\Models\UssdPlan;
use App\Models\UssdSession;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GWO SEVO sessions sell ONLY the designated vendor's own catalog. The shared
 * menu previously listed every active product on the platform.
 */
class GwoSevoVendorCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'gwosevo-shared-secret-0123456789';

    private Vendor $vendor;

    private Vendor $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->other = Vendor::factory()->create();
        $this->subscribe($this->vendor);

        $this->setting('ussd_gwosevo_enabled', '1');
        $this->setting('ussd_gwosevo_vendor_id', (string) $this->vendor->id);
        $this->setting('ussd_gwosevo_secret', Crypt::encryptString(self::SECRET));
    }

    private function subscribe(Vendor $vendor): UssdSubscription
    {
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        return UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'ussd_code' => UssdSubscription::buildUssdCode('*203*', '45', $vendor->id),
            'extension_code' => '45',
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'activated_at' => now(),
            'expires_at' => now()->addDays(20),
            'payment_reference' => Str::uuid()->toString(),
        ]);
    }

    private function setting(string $key, string $value): void
    {
        Setting::set($key, $value, 'ussd');
        Setting::clearCache();
    }

    private function gwo(string $input = '', int $op = 18, string $sessionId = 'S1')
    {
        return $this->postJson('/api/ussd/gwosevo', [
            'sessionID' => $sessionId,
            'ussdServiceOp' => $op,
            'ussdString' => $input,
            'network' => '01',
            'msisdn' => '233531005130',
        ], ['X-Ussd-Secret' => self::SECRET]);
    }

    private function dialAndPress(array $keys, string $sessionId = 'S1')
    {
        $response = $this->gwo('', 1, $sessionId);

        foreach ($keys as $key) {
            $response = $this->gwo($key, 18, $sessionId);
        }

        return $response;
    }

    private function bundle(Vendor $vendor, string $size, float $price, array $meta = [], array $attrs = []): Product
    {
        return Product::create(array_merge([
            'vendor_id' => $vendor->id,
            'name' => 'Pkg '.$size,
            'description' => json_encode(array_merge(['category' => 'data', 'network' => 'MTN', 'size' => $size], $meta)),
            'price' => $price,
            'is_active' => true,
        ], $attrs));
    }

    private function message($response): string
    {
        return (string) $response->json('message');
    }

    public function test_only_the_assigned_vendors_bundles_are_listed_with_size_and_own_price(): void
    {
        $this->bundle($this->vendor, '1GB', 5);
        $this->bundle($this->vendor, '2GB', 10);
        $this->bundle($this->other, '3GB', 1);
        $this->bundle($this->other, '1GB', 0.5, [], ['name' => 'STREET LORD RG YHAW']);

        $message = $this->message($this->dialAndPress(['1', '1']));

        $this->assertStringContainsString('1. 1GB - GHS 5.00', $message);
        $this->assertStringContainsString('2. 2GB - GHS 10.00', $message);
        $this->assertStringNotContainsString('3GB', $message);
        $this->assertStringNotContainsString('STREET LORD', $message);
        $this->assertStringNotContainsString('0.50', $message);
    }

    public function test_each_vendor_sees_only_their_own_catalog(): void
    {
        $this->bundle($this->vendor, '1GB', 5);
        $this->bundle($this->other, '3GB', 15);

        $this->assertStringNotContainsString('3GB', $this->message($this->dialAndPress(['1', '1'])));

        $this->setting('ussd_gwosevo_vendor_id', (string) $this->other->id);
        $this->subscribe($this->other);

        $message = $this->message($this->dialAndPress(['1', '1'], 'S2'));
        $this->assertStringContainsString('3GB - GHS 15.00', $message);
        $this->assertStringNotContainsString('1GB', $message);
    }

    public function test_network_inactive_category_and_missing_size_are_excluded(): void
    {
        $this->bundle($this->vendor, '1GB', 5);
        $this->bundle($this->vendor, '2GB', 10, ['network' => 'Telecel']);
        $this->bundle($this->vendor, '3GB', 11, [], ['is_active' => false]);
        $this->bundle($this->vendor, '4GB', 12, ['category' => 'afa']);
        $this->bundle($this->vendor, 'lots', 13);
        Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'No meta',
            'description' => 'not json',
            'price' => 14,
            'is_active' => true,
        ]);
        $deleted = $this->bundle($this->vendor, '6GB', 15);
        $deleted->delete();

        $message = $this->message($this->dialAndPress(['1', '1']));

        $this->assertStringContainsString('1. 1GB - GHS 5.00', $message);
        $this->assertStringNotContainsString("\n2. ", $message);
        foreach (['2GB', '3GB', '4GB', 'lots', 'No meta', '6GB'] as $absent) {
            $this->assertStringNotContainsString($absent, $message);
        }
    }

    public function test_empty_vendor_catalog_never_falls_back_to_other_vendors(): void
    {
        $this->bundle($this->other, '1GB', 5);

        $message = $this->message($this->dialAndPress(['1', '1']));

        $this->assertStringContainsString('No data bundles are currently available for this network.', $message);
        $this->assertStringNotContainsString('1GB', $message);
        $this->assertStringContainsString('0. Back', $message);

        // Back returns to the previous (main) menu.
        $this->assertStringContainsString('Buy Data Bundle', $this->message($this->gwo('0')));
    }

    public function test_menu_is_capped_at_seven_cheapest_bundles(): void
    {
        foreach (range(1, 9) as $n) {
            $this->bundle($this->vendor, "{$n}GB", $n * 2);
        }

        $message = $this->message($this->dialAndPress(['1', '1']));

        $this->assertStringContainsString('7. 7GB', $message);
        $this->assertStringNotContainsString("\n8. ", $message);
    }

    public function test_a_bundle_belonging_to_another_vendor_cannot_be_purchased_through_the_session(): void
    {
        $this->bundle($this->vendor, '1GB', 5);
        $foreign = $this->bundle($this->other, '9GB', 1);

        $this->dialAndPress(['1', '1', '1'], 'S1');

        // Tamper with the stored selection, as a stale or forged session might.
        $session = UssdSession::firstOrFail();
        $session->setDataMany(['product_id' => $foreign->id, 'product_price' => '1.00']);
        $session->forceFill(['current_step' => 'DATA_CONFIRM'])->save();

        Http::fake();
        $response = $this->gwo('1');

        $this->assertStringContainsString('Bundle no longer available', $this->message($response));
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_a_tampered_bundle_list_cannot_select_another_vendors_product(): void
    {
        $this->bundle($this->vendor, '1GB', 5);
        $foreign = $this->bundle($this->other, '9GB', 1);

        $this->dialAndPress(['1', '1'], 'S1');
        UssdSession::firstOrFail()->setDataMany(['available_bundles' => [$foreign->id]]);

        $this->assertStringContainsString('Bundle not found', $this->message($this->gwo('1')));
    }

    public function test_product_disabled_after_selection_is_not_sold(): void
    {
        $product = $this->bundle($this->vendor, '1GB', 5);
        $this->dialAndPress(['1', '1', '1', '0244111111'], 'S1');
        $product->update(['is_active' => false]);

        Http::fake();
        $this->assertStringContainsString('Bundle no longer available', $this->message($this->gwo('1')));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_price_change_after_confirmation_screen_is_refused(): void
    {
        $product = $this->bundle($this->vendor, '1GB', 5);
        $this->dialAndPress(['1', '1', '1', '0244111111'], 'S1');
        $product->update(['price' => 50]);

        Http::fake();
        $this->assertStringContainsString('price of this bundle has changed', $this->message($this->gwo('1')));
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_purchase_books_the_order_to_the_assigned_vendor_once_with_its_price(): void
    {
        $this->paystack();
        $this->bundle($this->vendor, '1GB', 5);

        $confirm = $this->dialAndPress(['1', '1', '1', '0244111111'], 'S1');
        $this->assertStringContainsString('Bundle: 1GB', $this->message($confirm));
        $this->assertStringContainsString('Price: GHS 5.00', $this->message($confirm));

        $this->assertStringContainsString('Order placed successfully', $this->message($this->gwo('1')));
        // A retried confirm must not create or charge a second time.
        $this->gwo('1');

        $order = Order::firstOrFail();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($this->vendor->id, $order->vendor_id);
        $this->assertFalse((bool) $order->is_reseller_order);
        $this->assertSame('5.00', number_format((float) $order->expected_amount, 2, '.', ''));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    public function test_result_checkers_are_limited_to_those_enabled_for_the_assigned_vendor(): void
    {
        $enabled = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20, 'name' => 'WAEC Enabled']);
        $disabledSetting = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20, 'name' => 'BECE Disabled']);
        $otherVendorOnly = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20, 'name' => 'NOV Other']);
        $inactive = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => false, 'base_price' => 20, 'name' => 'OFF Inactive']);

        foreach ([[$this->vendor, $enabled, true], [$this->vendor, $disabledSetting, false], [$this->other, $otherVendorOnly, true], [$this->vendor, $inactive, true]] as [$v, $s, $active]) {
            VendorResultCheckerSetting::create(['vendor_id' => $v->id, 'service_id' => $s->id, 'profit_amount' => 2, 'is_active' => $active]);
        }

        $message = $this->message($this->dialAndPress(['2']));

        $this->assertStringContainsString('1. WAEC Enabled', $message);
        foreach (['BECE', 'NOV', 'OFF'] as $absent) {
            $this->assertStringNotContainsString($absent, $message);
        }

        // Selecting a service the vendor has not enabled is refused even if the list is tampered with.
        UssdSession::firstOrFail()->setDataMany(['available_checkers' => [$otherVendorOnly->id]]);
        $this->assertStringContainsString('unavailable', $this->message($this->gwo('1')));
    }

    public function test_no_enabled_result_checkers_shows_none_available(): void
    {
        NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20]);

        $this->assertStringContainsString('No Results Checkers available', $this->message($this->dialAndPress(['2'])));
    }

    public function test_result_checker_purchase_is_booked_to_the_assigned_vendor(): void
    {
        $this->paystack();
        $service = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => 20]);
        VendorResultCheckerSetting::create(['vendor_id' => $this->vendor->id, 'service_id' => $service->id, 'profit_amount' => 2, 'is_active' => true]);

        $this->dialAndPress(['2', '1', '1', '0244222222'], 'S1');
        $this->assertStringContainsString('Order placed successfully', $this->message($this->gwo('1')));

        $this->assertSame($this->vendor->id, ResultCheckerOrder::firstOrFail()->vendor_id);
    }

    private function checker(float $base = 20, float $profit = 5): array
    {
        $service = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => $base]);
        $setting = VendorResultCheckerSetting::create(['vendor_id' => $this->vendor->id, 'service_id' => $service->id, 'profit_amount' => $profit, 'is_active' => true]);

        return [$service, $setting];
    }

    public function test_result_checker_price_is_admin_base_plus_vendor_profit_and_is_what_is_charged(): void
    {
        $this->paystack();
        $this->checker(20, 5);

        $confirm = $this->dialAndPress(['2', '1', '1', '0244222222']);
        $this->assertStringContainsString('Total: GHS 25.00', $this->message($confirm));

        $this->assertStringContainsString('Order placed successfully', $this->message($this->gwo('1')));

        $order = ResultCheckerOrder::firstOrFail();
        $this->assertSame('25.00', number_format((float) $order->unit_price, 2, '.', ''));
        $this->assertSame('25.00', number_format((float) $order->total_price, 2, '.', ''));
        $this->assertSame('5.00', number_format((float) $order->vendor_profit, 2, '.', ''));

        $recorded = Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'));
        $this->assertCount(1, $recorded);
        $this->assertEquals(2500, $recorded->first()[0]->data()['amount']);
    }

    public function test_result_checker_quantity_uses_admin_tier_plus_profit_per_pin(): void
    {
        $this->paystack();
        [$service] = $this->checker(20, 5);
        \App\Models\ResultCheckerPricingTier::create(['network_service_id' => $service->id, 'min_quantity' => 3, 'max_quantity' => null, 'price' => 18]);

        $confirm = $this->dialAndPress(['2', '1', '3', '0244222222']);
        $this->assertStringContainsString('Total: GHS 69.00', $this->message($confirm)); // (18 + 5) x 3

        $this->gwo('1');

        $order = ResultCheckerOrder::firstOrFail();
        $this->assertSame(3, (int) $order->quantity);
        $this->assertSame('69.00', number_format((float) $order->total_price, 2, '.', ''));
        $this->assertSame('15.00', number_format((float) $order->vendor_profit, 2, '.', ''));
    }

    public function test_result_checker_price_change_after_confirmation_is_refused_without_payment(): void
    {
        $this->paystack();
        [$service, $setting] = $this->checker(20, 5);

        $this->dialAndPress(['2', '1', '1', '0244222222']);
        $setting->update(['profit_amount' => 9]);

        $this->assertStringContainsString('price has changed', $this->message($this->gwo('1')));
        $this->assertDatabaseCount('result_checker_orders', 0);
        Http::assertNothingSent();

        // Admin base price change is caught the same way.
        $setting->update(['profit_amount' => 5]);
        $this->dialAndPress(['2', '1', '1', '0244222222'], 'S2');
        $service->update(['base_price' => 30]);

        $this->assertStringContainsString('price has changed', $this->message($this->gwo('1', 18, 'S2')));
        $this->assertDatabaseCount('result_checker_orders', 0);
    }

    public function test_result_checker_disabled_after_confirmation_is_not_sold(): void
    {
        $this->paystack();
        [, $setting] = $this->checker();

        $this->dialAndPress(['2', '1', '1', '0244222222']);
        $setting->update(['is_active' => false]);

        $this->assertStringContainsString('no longer available', $this->message($this->gwo('1')));
        $this->assertDatabaseCount('result_checker_orders', 0);
        Http::assertNothingSent();
    }

    public function test_result_checker_duplicate_confirm_creates_one_order_and_one_charge(): void
    {
        $this->paystack();
        $this->checker();

        $this->dialAndPress(['2', '1', '1', '0244222222']);
        $this->gwo('1');
        $this->gwo('1');

        $this->assertDatabaseCount('result_checker_orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize')));
    }

    public function test_result_checker_price_uses_only_the_assigned_vendors_profit(): void
    {
        $this->paystack();
        [$service] = $this->checker(20, 5);
        VendorResultCheckerSetting::create(['vendor_id' => $this->other->id, 'service_id' => $service->id, 'profit_amount' => 50, 'is_active' => true]);

        $this->assertStringContainsString('Total: GHS 25.00', $this->message($this->dialAndPress(['2', '1', '1', '0244222222'])));
    }

    public function test_the_moolre_gateway_keeps_its_shared_catalog_behaviour(): void
    {
        // Moolre sessions are unaffected: products without a size and from
        // other vendors still list there, exactly as before this change.
        $this->bundle($this->other, '3GB', 15, [], ['name' => 'Legacy Pkg']);
        Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'MTN 1GB',
            'description' => json_encode(['category' => 'data', 'network' => 'MTN']),
            'price' => 5,
            'is_active' => true,
        ]);

        $dial = "45*{$this->vendor->id}";
        $post = fn (string $text) => $this->post('/api/ussd', [
            'sessionId' => 'moolre-sess-1',
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => $text,
        ]);

        $post($dial);
        $post("{$dial}*1");
        $response = $post("{$dial}*1*1");

        $response->assertSee('Legacy Pkg', false)->assertSee('MTN 1GB', false);
    }

    private function paystack(): void
    {
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

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/transaction/initialize')) {
                return Http::response([
                    'status' => true,
                    'data' => ['authorization_url' => null, 'reference' => $request->data()['reference'] ?? 'unknown'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }
}
