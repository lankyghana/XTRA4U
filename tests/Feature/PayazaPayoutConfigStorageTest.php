<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Storage-only coverage for Payaza payout credentials: encryption, PIN
 * masking/blank-preserve, PIN format validation, and confirmation that
 * none of this enables Payaza payouts (supports_payout stays false).
 *
 * No PayazaPayoutService exists yet and none of these tests initiate a
 * real payout — this is purely about whether credentials can be stored
 * and protected correctly ahead of that work.
 */
class PayazaPayoutConfigStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    protected function validPayoutPayload(array $overrides = []): array
    {
        return array_merge([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '0',
            'is_default' => '0',
            'config' => [
                'public_key' => 'test-public-key',
                'secret_key' => 'test-secret-key',
                'transaction_pin' => '135790', // 6 distinct digits, no run
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ], $overrides);
    }

    // ---------------------------------------------------------------- create

    public function test_admin_can_create_payaza_payout_config_row_with_valid_pin(): void
    {
        $this->actingAdmin();

        $resp = $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload());

        $resp->assertRedirect(route('admin.payment-gateways.index'));

        $gateway = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_PAYAZA)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
            ->first();

        $this->assertNotNull($gateway);
        $this->assertSame('135790', $gateway->getConfig('transaction_pin'));
    }

    public function test_creating_payaza_payout_config_cannot_be_saved_active(): void
    {
        $this->actingAdmin();

        $resp = $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload([
            'is_active' => '1',
        ]));

        $resp->assertSessionHasErrors('is_active');

        $this->assertDatabaseMissing('payment_gateway_configs', [
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
        ]);
    }

    public function test_supports_payout_remains_false_after_creating_payaza_payout_row(): void
    {
        $this->actingAdmin();

        $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload());

        $gateway = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_PAYAZA)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
            ->first();

        $this->assertNotNull($gateway);
        $this->assertFalse((bool) $gateway->supports_payout);
        $this->assertFalse((bool) $gateway->is_active);

        // Confirm the capability map itself was not touched by this work.
        $this->assertFalse(PaymentGatewayConfig::defaultCapabilitiesFor(PaymentGatewayConfig::GATEWAY_PAYAZA)['supports_payout']);
    }

    // ---------------------------------------------------------------- encryption

    public function test_transaction_pin_is_encrypted_at_rest(): void
    {
        $this->actingAdmin();

        $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload());

        $gateway = PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_PAYAZA)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
            ->first();

        $raw = $gateway->getRawOriginal('config_data');

        // The PIN must not appear anywhere in the raw stored column.
        $this->assertStringNotContainsString('135790', (string) $raw);

        // But it must be recoverable via the existing Crypt mechanism —
        // confirming no bespoke encryption was introduced.
        $decrypted = json_decode(Crypt::decryptString($raw), true);
        $this->assertSame('135790', $decrypted['transaction_pin']);
    }

    // ---------------------------------------------------------------- isConfigured()

    public function test_is_configured_true_with_valid_fields(): void
    {
        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_payout' => false,
            'is_active' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '135790',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->assertTrue($gateway->isConfigured());
    }

    public static function invalidPinProvider(): array
    {
        return [
            'too short' => ['1357'],
            'too long' => ['13579012'],
            'non-numeric' => ['12345a'],
            'all repeated' => ['111111'],
            'one repeated digit' => ['123453'],
            'ascending run' => ['123456'],
            'descending run' => ['654321'],
        ];
    }

    #[DataProvider('invalidPinProvider')]
    public function test_is_configured_false_with_invalid_pin(string $pin): void
    {
        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_payout' => false,
            'is_active' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => $pin,
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->assertFalse($gateway->isConfigured());
    }

    // ---------------------------------------------------------------- create-time validation

    public function test_create_rejects_repeated_digit_pin(): void
    {
        $this->actingAdmin();

        $resp = $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload([
            'config' => array_merge($this->validPayoutPayload()['config'], ['transaction_pin' => '112233']),
        ]));

        $resp->assertSessionHasErrors('config.transaction_pin');
        $this->assertDatabaseMissing('payment_gateway_configs', ['gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA]);
    }

    public function test_create_rejects_sequential_pin(): void
    {
        $this->actingAdmin();

        $resp = $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload([
            'config' => array_merge($this->validPayoutPayload()['config'], ['transaction_pin' => '234567']),
        ]));

        $resp->assertSessionHasErrors('config.transaction_pin');
    }

    public function test_validation_error_message_never_echoes_the_submitted_pin(): void
    {
        $this->actingAdmin();

        $resp = $this->post(route('admin.payment-gateways.store'), $this->validPayoutPayload([
            'config' => array_merge($this->validPayoutPayload()['config'], ['transaction_pin' => '111222']),
        ]));

        $errors = $resp->getSession()->get('errors');
        $message = $errors->first('config.transaction_pin');

        $this->assertStringNotContainsString('111222', $message);
    }

    // ---------------------------------------------------------------- update / blank-preserve

    public function test_leaving_pin_blank_on_update_preserves_existing_value(): void
    {
        $this->actingAdmin();

        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_payout' => false,
            'is_active' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '135790',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $resp = $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '0',
            'is_default' => '0',
            'config' => [
                'public_key' => 'pk',
                'secret_key' => '', // blank — same rule applies to secret_key
                'transaction_pin' => '', // blank — must preserve
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $resp->assertRedirect(route('admin.payment-gateways.index'));

        $gateway->refresh();
        $this->assertSame('135790', $gateway->getConfig('transaction_pin'));
        $this->assertSame('sk', $gateway->getConfig('secret_key'));
    }

    public function test_updating_with_new_valid_pin_replaces_existing_value(): void
    {
        $this->actingAdmin();

        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_payout' => false,
            'is_active' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '135790',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '0',
            'is_default' => '0',
            'config' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '246803',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $gateway->refresh();
        $this->assertSame('246803', $gateway->getConfig('transaction_pin'));
    }

    public function test_updating_with_invalid_pin_is_rejected_and_does_not_overwrite(): void
    {
        $this->actingAdmin();

        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_payout' => false,
            'is_active' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '135790',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $resp = $this->put(route('admin.payment-gateways.update', $gateway), [
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => '0',
            'is_default' => '0',
            'config' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '000000',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $resp->assertSessionHasErrors('config.transaction_pin');

        $gateway->refresh();
        $this->assertSame('135790', $gateway->getConfig('transaction_pin'));
    }

    // ---------------------------------------------------------------- HTML exposure

    public function test_edit_view_never_renders_decrypted_pin_in_html(): void
    {
        $this->actingAdmin();

        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'supports_payout' => false,
            'is_active' => false,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'transaction_pin' => '135790',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $resp = $this->get(route('admin.payment-gateways.edit', $gateway));

        $resp->assertOk();
        $resp->assertDontSee('135790', false);
        // The PIN field itself should render as a masked, empty input.
        $resp->assertSee('id="config_transaction_pin"', false);
    }

    // ---------------------------------------------------------------- regression: collection untouched

    public function test_payaza_collection_configuration_is_unaffected(): void
    {
        $gateway = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'pk',
                'secret_key' => 'sk',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);

        $this->assertTrue($gateway->isConfigured());

        $service = new \App\Services\PayazaPaymentService($gateway);
        $this->assertTrue($service->isConfigured());
    }
}
