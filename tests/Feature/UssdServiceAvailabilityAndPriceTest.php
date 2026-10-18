<?php

namespace Tests\Feature;

use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResultCheckerOrder;
use App\Models\Setting;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Both USSD gateways honour the admin Service Availability switches and refuse
 * to charge a zero, negative or invalid price, exactly like the web checkout.
 */
class UssdServiceAvailabilityAndPriceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'gwosevo-shared-secret-0123456789';

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        UssdSubscription::create([
            'vendor_id' => $this->vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $this->vendor->id,
            'ussd_code' => UssdSubscription::buildUssdCode('*203*', '45', $this->vendor->id),
            'extension_code' => '45',
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'activated_at' => now(),
            'expires_at' => now()->addDays(20),
            'payment_reference' => Str::uuid()->toString(),
        ]);

        foreach ([
            'ussd_gwosevo_enabled' => '1',
            'ussd_gwosevo_vendor_id' => (string) $this->vendor->id,
            'ussd_gwosevo_secret' => Crypt::encryptString(self::SECRET),
        ] as $key => $value) {
            Setting::set($key, $value, 'ussd');
        }
        Setting::clearCache();

        $this->paystack();
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

    private function close(string $category): void
    {
        ServiceAvailability::setOpen($category, false);
        Setting::clearCache();
    }

    private function open(string $category): void
    {
        ServiceAvailability::setOpen($category, true);
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

    private function gwoKeys(array $keys, string $sessionId = 'S1'): string
    {
        $response = $this->gwo('', 1, $sessionId);

        foreach ($keys as $key) {
            $response = $this->gwo($key, 18, $sessionId);
        }

        return (string) $response->json('message');
    }

    private function moolre(string $suffix, string $sessionId = 'moolre-sess-1')
    {
        $prefix = "45*{$this->vendor->id}";

        return $this->post('/api/ussd', [
            'sessionId' => $sessionId,
            'phoneNumber' => '0244000000',
            'network' => 'MTN',
            'text' => $suffix === '' ? $prefix : "{$prefix}*{$suffix}",
        ]);
    }

    private function bundle(float|string $price, string $size = '1GB'): Product
    {
        return Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'MTN '.$size,
            'description' => json_encode(['category' => 'data', 'network' => 'MTN', 'size' => $size]),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function checker(float $base = 20, float $profit = 5): NetworkService
    {
        $service = NetworkService::factory()->create(['service_type' => 'results_checker', 'is_active' => true, 'base_price' => $base]);
        VendorResultCheckerSetting::create(['vendor_id' => $this->vendor->id, 'service_id' => $service->id, 'profit_amount' => $profit, 'is_active' => true]);

        return $service;
    }

    private function initializeCalls(): int
    {
        return Http::recorded(fn ($r) => str_contains((string) $r->url(), '/transaction/initialize'))->count();
    }

    // ---------------------------------------------------------- availability

    public function test_closed_data_category_blocks_gwo_data_purchase_at_entry(): void
    {
        $this->bundle(5);
        $this->close('data');
        ServiceAvailability::setMessage('Data is on a break.');

        $message = $this->gwoKeys(['1']);

        $this->assertStringContainsString('Data is on a break.', $message);
        $this->assertStringNotContainsString('Select Network', $message);
    }

    public function test_data_closed_after_selection_blocks_payment_on_gwo(): void
    {
        $this->bundle(5);
        $this->gwoKeys(['1', '1', '1', '0244111111']);
        $this->close('data');

        $this->assertStringContainsString('temporarily unavailable', (string) $this->gwo('1')->json('message'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $this->initializeCalls());
    }

    public function test_reopened_data_category_sells_again(): void
    {
        $this->bundle(5);
        $this->close('data');
        $this->open('data');

        $this->gwoKeys(['1', '1', '1', '0244111111']);
        $this->assertStringContainsString('Order placed successfully', (string) $this->gwo('1')->json('message'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_closed_results_category_blocks_gwo_checker_purchase_at_entry_and_before_payment(): void
    {
        $this->checker();

        $this->gwoKeys(['2', '1', '1', '0244222222'], 'S1');
        $this->close('results');

        $this->assertStringContainsString('temporarily unavailable', (string) $this->gwo('1', 18, 'S1')->json('message'));
        $this->assertDatabaseCount('result_checker_orders', 0);
        $this->assertSame(0, $this->initializeCalls());

        $entry = $this->gwoKeys(['2'], 'S2');
        $this->assertStringContainsString('temporarily unavailable', $entry);
        $this->assertStringNotContainsString('Select Exam Type', $entry);
    }

    public function test_closing_data_does_not_block_results_and_vice_versa(): void
    {
        $this->checker();
        $this->close('data');

        $this->assertStringContainsString('Select Exam Type', $this->gwoKeys(['2']));

        $this->open('data');
        $this->close('results');
        $this->bundle(5);

        $this->assertStringContainsString('Select MTN Bundle', $this->gwoKeys(['1', '1'], 'S2'));
    }

    public function test_closed_categories_block_the_moolre_gateway_too(): void
    {
        $this->bundle(5);
        $this->checker();
        $this->close('data');
        $this->close('results');

        $this->moolre('');
        $this->moolre('1')->assertSee('temporarily unavailable', false)->assertDontSee('Select Network', false);

        $this->moolre('', 'moolre-sess-2');
        $this->moolre('2', 'moolre-sess-2')->assertSee('temporarily unavailable', false)->assertDontSee('Select Exam Type', false);
    }

    // ----------------------------------------------------------------- price

    public function test_zero_price_bundle_is_not_listed_or_sold_on_gwo(): void
    {
        $this->bundle(0, '1GB');
        $this->bundle(5, '2GB');

        $message = $this->gwoKeys(['1', '1']);

        $this->assertStringContainsString('2GB - GHS 5.00', $message);
        $this->assertStringNotContainsString('1GB', $message);
    }

    public function test_bundle_zeroed_after_selection_creates_no_order_or_charge_on_gwo(): void
    {
        $product = $this->bundle(5);
        $this->gwoKeys(['1', '1', '1', '0244111111']);
        $product->update(['price' => 0]);

        $this->gwo('1');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $this->initializeCalls());
    }

    public function test_zero_price_bundle_is_never_charged_on_moolre(): void
    {
        Product::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'Free Pkg',
            'description' => json_encode(['category' => 'data', 'network' => 'MTN']),
            'price' => 0,
            'is_active' => true,
        ]);

        $this->moolre('');
        $this->moolre('1');
        $this->moolre('1*1');
        $this->moolre('1*1*1');
        $this->moolre('1*1*1*0244111111');
        $response = $this->moolre('1*1*1*0244111111*1');

        $this->assertStringContainsString('Bundle no longer available', $response->getContent());
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $this->initializeCalls());
    }

    public function test_legitimate_prices_and_vendor_profit_are_unchanged(): void
    {
        $this->bundle(5);
        $this->checker(20, 5);

        $this->gwoKeys(['1', '1', '1', '0244111111'], 'S1');
        $this->assertStringContainsString('Order placed successfully', (string) $this->gwo('1', 18, 'S1')->json('message'));

        $confirm = $this->gwoKeys(['2', '1', '1', '0244222222'], 'S2');
        $this->assertStringContainsString('Total: GHS 25.00', $confirm);
        $this->gwo('1', 18, 'S2');

        $this->assertSame('5.00', number_format((float) ResultCheckerOrder::firstOrFail()->vendor_profit, 2, '.', ''));
    }
}
