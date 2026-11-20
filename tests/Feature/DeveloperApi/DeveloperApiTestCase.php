<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperPlan;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use App\Services\DeveloperApi\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

abstract class DeveloperApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api-rate:1');
    }

    /**
     * An application always belongs to ONE vendor. With no vendor given, a fresh APPROVED one is created.
     * The owner is set through forceFill exactly as DeveloperApplicationService does: it is never mass assignable.
     */
    protected function makeApp(?Vendor $vendor = null, array $overrides = []): DeveloperApplication
    {
        $vendor ??= $this->vendor();

        $app = new DeveloperApplication(['name' => 'Test App']);
        $app->forceFill(array_merge([
            'vendor_id' => $vendor->id,
            'status' => DeveloperApplication::STATUS_ACTIVE,
        ], $overrides))->save();

        return $app;
    }

    /**
     * @param  ?string  $environment  'production' (default, xt_live_) or 'sandbox' (xt_test_)
     * @return array{0:\App\Models\Developer\DeveloperApiKey,1:string} key + plaintext
     */
    protected function makeKey(DeveloperApplication $app, ?array $scopes = null, $expiresAt = null, string $environment = 'production'): array
    {
        // Default = every scope this environment may hold. Sandbox-only scopes are never valid on a production key,
        // and scopes without a working endpoint (`unavailable_scopes`, e.g. ai:chat) cannot be granted to new keys.
        $grantable = array_keys(ApiKeyService::availableScopes());
        $scopes ??= $environment === 'sandbox'
            ? $grantable
            : array_values(array_diff($grantable, config('developer_api.sandbox_only_scopes')));
        $r = app(ApiKeyService::class)->create($app, 'Test key', $scopes, $expiresAt, null, $environment);

        return [$r['key'], $r['plaintext']];
    }

    protected function auth(string $plaintext): array
    {
        return ['Authorization' => 'Bearer '.$plaintext];
    }

    protected function vendor(array $attrs = []): Vendor
    {
        return Vendor::factory()->create($attrs);
    }

    protected function product(Vendor $vendor, float $price = 10.0, array $meta = [], array $attrs = []): Product
    {
        return Product::create(array_merge([
            'vendor_id' => $vendor->id,
            'name' => 'MTN 5GB',
            'description' => json_encode(array_merge(['category' => 'data', 'network' => 'MTN', 'size' => '5GB', 'validity' => '30 days'], $meta)),
            'price' => $price,
            'is_active' => true,
            'is_resellable' => true,
        ], $attrs));
    }

    protected function listing(Product $product, Vendor $reseller, float $markup = 2.0): ResellerProduct
    {
        return ResellerProduct::create([
            'product_id' => $product->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_vendor_id' => $product->vendor_id,
            'base_price' => $product->price,
            'markup_price' => $markup,
            'is_active' => true,
        ]);
    }

    protected function paystack(): void
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
    }

    protected function fakePaystackInit(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true, 'message' => 'Initialized',
                'data' => ['authorization_url' => 'https://paystack.example/redirect'],
            ], 200),
        ]);
    }

    protected function plan(array $attrs = []): DeveloperPlan
    {
        return DeveloperPlan::create(array_merge(['code' => 'p'.uniqid(), 'name' => 'Plan', 'requests_per_minute' => 60], $attrs));
    }
}
