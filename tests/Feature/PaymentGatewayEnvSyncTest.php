<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers a `php artisan serve` interaction bug: PaymentGatewayController::
 * updateEnvFile() used to unconditionally rewrite .env (even byte-for-byte
 * identical content) on every save of an active+default gateway. That bumps
 * .env's mtime, which `artisan serve`'s environment-change watcher
 * (ServeCommand::handle()) polls for — tripping a server restart that kills
 * the very request doing the save. Payaza has no .env-mapped fields at all,
 * so every one of its saves hit this for no reason.
 *
 * These tests touch the project's real .env file (that's what the code under
 * test operates on), so setUp/tearDown snapshot and restore it around every
 * test in this class.
 */
class PaymentGatewayEnvSyncTest extends TestCase
{
    use RefreshDatabase;

    protected string $envPath;

    protected ?string $envBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = base_path('.env');
        $this->envBackup = File::exists($this->envPath) ? File::get($this->envPath) : null;
    }

    protected function tearDown(): void
    {
        if ($this->envBackup !== null) {
            File::put($this->envPath, $this->envBackup);
        }

        parent::tearDown();
    }

    protected function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    protected function makePayazaCollectionConfig(array $overrides = []): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create(array_merge([
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
                'public_key' => 'old-public-key',
                'secret_key' => 'old-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
            'supported_features' => [],
        ], $overrides));
    }

    protected function makePaystackConfig(array $overrides = []): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create(array_merge([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYSTACK,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'supports_payout' => true,
            'supports_sms' => false,
            'supports_webhook' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'old-paystack-public',
                'secret_key' => 'old-paystack-secret',
                'payment_url' => 'https://api.paystack.co',
            ],
            'supported_features' => [],
        ], $overrides));
    }

    // 1. The bug this whole class exists to catch: an active+default Payaza
    //    collection save must never touch .env, since Payaza has no .env-mapped
    //    fields — the old code rewrote it anyway (bumping mtime) on every save.
    public function test_payaza_collection_update_does_not_touch_env_file(): void
    {
        $this->actingAdmin();
        $gateway = $this->makePayazaCollectionConfig();

        $beforeContent = File::get($this->envPath);
        $beforeMtime = filemtime($this->envPath);

        // Ensure at least 1 whole second passes so a real rewrite would be
        // detectable via mtime even on filesystems with 1s mtime resolution.
        sleep(1);

        $resp = $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_LIVE,
            'is_active' => '1',
            'is_default' => '1',
            'config' => [
                'public_key' => 'new-public-key',
                'secret_key' => 'new-secret-key',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $resp->assertRedirect(route('admin.payment-gateways.index'));

        $this->assertSame($beforeContent, File::get($this->envPath), '.env content changed for a gateway with no .env-mapped fields.');
        $this->assertSame($beforeMtime, filemtime($this->envPath), '.env mtime changed for a gateway with no .env-mapped fields — this is exactly what trips artisan serve\'s restart-on-change watcher mid-request.');

        // The actual DB save must still have gone through despite skipping .env.
        $gateway->refresh();
        $this->assertSame(PaymentGatewayConfig::ENV_LIVE, $gateway->environment);
        $this->assertSame('new-public-key', $gateway->getConfig('public_key'));
    }

    // 2. Existing behaviour for gateways that DO map to .env must be unchanged:
    //    a real credential change still gets written through.
    public function test_paystack_update_with_changed_key_still_writes_env_file(): void
    {
        $this->actingAdmin();
        $gateway = $this->makePaystackConfig();

        $resp = $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '1',
            'is_default' => '1',
            'config' => [
                'public_key' => 'brand-new-paystack-public',
                'secret_key' => 'old-paystack-secret',
                'payment_url' => 'https://api.paystack.co',
            ],
        ]);

        $resp->assertRedirect(route('admin.payment-gateways.index'));

        $this->assertStringContainsString('PAYSTACK_PUBLIC_KEY=brand-new-paystack-public', File::get($this->envPath));
    }

    // 3. A Paystack save that changes nothing at all should also skip the
    //    write — same fix, same reasoning, different gateway.
    public function test_paystack_update_with_unchanged_values_does_not_touch_env_file(): void
    {
        $this->actingAdmin();
        $gateway = $this->makePaystackConfig();

        // Prime .env with the exact values already stored, so the upcoming
        // save is a genuine no-op from .env's point of view.
        $envContent = File::get($this->envPath);
        $envContent = preg_replace('/^PAYSTACK_PUBLIC_KEY=.*$/m', 'PAYSTACK_PUBLIC_KEY=old-paystack-public', $envContent);
        if (! str_contains($envContent, 'PAYSTACK_PUBLIC_KEY=')) {
            $envContent .= "\nPAYSTACK_PUBLIC_KEY=old-paystack-public";
        }
        $envContent = preg_replace('/^PAYSTACK_SECRET_KEY=.*$/m', 'PAYSTACK_SECRET_KEY=old-paystack-secret', $envContent);
        if (! str_contains($envContent, 'PAYSTACK_SECRET_KEY=')) {
            $envContent .= "\nPAYSTACK_SECRET_KEY=old-paystack-secret";
        }
        $envContent = preg_replace('/^PAYSTACK_PAYMENT_URL=.*$/m', 'PAYSTACK_PAYMENT_URL=https://api.paystack.co', $envContent);
        if (! str_contains($envContent, 'PAYSTACK_PAYMENT_URL=')) {
            $envContent .= "\nPAYSTACK_PAYMENT_URL=https://api.paystack.co";
        }
        File::put($this->envPath, $envContent);

        $beforeContent = File::get($this->envPath);
        $beforeMtime = filemtime($this->envPath);
        sleep(1);

        $resp = $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '1',
            'is_default' => '1',
            'config' => [
                'public_key' => 'old-paystack-public',
                'secret_key' => 'old-paystack-secret',
                'payment_url' => 'https://api.paystack.co',
            ],
        ]);

        $resp->assertRedirect(route('admin.payment-gateways.index'));

        $this->assertSame($beforeContent, File::get($this->envPath));
        $this->assertSame($beforeMtime, filemtime($this->envPath));
    }

    // 4. Inactive/non-default gateway saves already short-circuit before
    //    touching .env at all (pre-existing behaviour) — still true after the fix.
    public function test_inactive_gateway_update_never_touches_env_file(): void
    {
        $this->actingAdmin();
        $gateway = $this->makePayazaCollectionConfig(['is_active' => false, 'is_default' => false]);

        $beforeContent = File::get($this->envPath);
        $beforeMtime = filemtime($this->envPath);
        sleep(1);

        $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '0',
            'is_default' => '0',
            'config' => [
                'public_key' => 'irrelevant',
                'secret_key' => 'irrelevant',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->assertSame($beforeContent, File::get($this->envPath));
        $this->assertSame($beforeMtime, filemtime($this->envPath));
    }
}
