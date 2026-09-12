<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfig;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Security/safety coverage for the `payaza:payout-diagnostic` command
 * itself — not for a real Payaza payout (no PayazaPayoutService exists).
 * These tests never hit the real network (Http::fake() throughout) and
 * never assert anything about Payaza's actual behaviour.
 */
class PayazaPayoutDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.payaza.africa/live';

    private const INITIATE_URL = self::BASE.'/payout-receptor/payout';

    private const STATUS_URL = self::BASE.'/payaza-account/api/v1/mainaccounts/transaction/status*';

    private const ENQUIRY_URL = self::BASE.'/payaza-account/api/v1/mainaccounts/merchant/enquiry/main';

    protected function makeSandboxPayoutConfig(array $overrides = []): PaymentGatewayConfig
    {
        return PaymentGatewayConfig::create(array_merge([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYOUT,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'is_active' => false,
            'is_default' => false,
            'supports_payout' => false,
            'config_data' => [
                'public_key' => 'sandbox-public-key-value',
                'secret_key' => 'sandbox-secret-key-value',
                'transaction_pin' => '135790',
                'base_url' => self::BASE,
            ],
        ], $overrides));
    }

    /** A successful account-enquiry fake: one active GHS account. */
    protected function activeGhsEnquiryResponse(): array
    {
        return [
            'data' => [
                [
                    'currency' => 'GHS',
                    'payazaAccountReference' => 'GHS-ACCT-REF-DIAGNOSTIC',
                    'status' => 'ACTIVE',
                ],
                [
                    'currency' => 'NGN',
                    'payazaAccountReference' => 'NGN-ACCT-REF-OTHER',
                    'status' => 'ACTIVE',
                ],
            ],
        ];
    }

    protected function fakeHappyPath(): void
    {
        Http::fake([
            self::ENQUIRY_URL => Http::response($this->activeGhsEnquiryResponse(), 200),
            self::INITIATE_URL => Http::response(['transaction_status' => 'TRANSACTION_INITIATED'], 200),
            self::STATUS_URL => Http::response(['transaction_status' => 'TRANSACTION_INITIATED'], 200),
        ]);
    }

    // ---------------------------------------------------------------- basic guards (unaffected by enquiry step)

    public function test_refuses_production_environment(): void
    {
        $this->makeSandboxPayoutConfig();

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('refusing to run in a production environment');

        $this->app->detectEnvironment(fn () => 'testing');
    }

    public function test_refuses_live_config(): void
    {
        $this->makeSandboxPayoutConfig(['environment' => PaymentGatewayConfig::ENV_LIVE]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('not sandbox');
    }

    public function test_refuses_missing_pin(): void
    {
        $this->makeSandboxPayoutConfig([
            'config_data' => [
                'public_key' => 'sandbox-public-key-value',
                'secret_key' => 'sandbox-secret-key-value',
                'base_url' => self::BASE,
            ],
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('transaction_pin is missing');
    }

    public function test_refuses_malformed_pin(): void
    {
        $this->makeSandboxPayoutConfig([
            'config_data' => [
                'public_key' => 'sandbox-public-key-value',
                'secret_key' => 'sandbox-secret-key-value',
                'transaction_pin' => '111111',
                'base_url' => self::BASE,
            ],
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('malformed');
    }

    public function test_refuses_missing_public_key(): void
    {
        $this->makeSandboxPayoutConfig([
            'config_data' => [
                'secret_key' => 'sandbox-secret-key-value',
                'transaction_pin' => '135790',
                'base_url' => self::BASE,
            ],
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('public_key is missing');
    }

    public function test_refuses_when_no_payout_config_exists(): void
    {
        $this->artisan('payaza:payout-diagnostic', ['--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('no Payaza payout gateway configuration exists');
    }

    public function test_refuses_if_config_is_active_or_supports_payout(): void
    {
        $this->makeSandboxPayoutConfig(['is_active' => true]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('active and/or supports_payout is true');
    }

    // ---------------------------------------------------------------- base_url shape guard

    public function test_refuses_invalid_base_url_shape(): void
    {
        $this->makeSandboxPayoutConfig(['config_data' => [
            'public_key' => 'sandbox-public-key-value',
            'secret_key' => 'sandbox-secret-key-value',
            'transaction_pin' => '135790',
            'base_url' => 'https://x',
        ]]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not look like a real Payaza API endpoint');

        Http::fake(); // nothing should have been sent
        Http::assertNothingSent();
    }

    public function test_refuses_base_url_missing_live_suffix(): void
    {
        $this->makeSandboxPayoutConfig(['config_data' => [
            'public_key' => 'sandbox-public-key-value',
            'secret_key' => 'sandbox-secret-key-value',
            'transaction_pin' => '135790',
            'base_url' => 'https://api.payaza.africa',
        ]]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not look like a real Payaza API endpoint');
    }

    // ---------------------------------------------------------------- account enquiry guard

    public function test_stops_when_account_enquiry_network_fails(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('could not confirm an active GHS Payaza account_reference');

        Http::assertNotSent(fn ($r) => (string) $r->url() === self::INITIATE_URL);
    }

    public function test_stops_when_no_ghs_account_found(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response([
                'data' => [
                    ['currency' => 'NGN', 'payazaAccountReference' => 'NGN-REF', 'status' => 'ACTIVE'],
                ],
            ], 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('No GHS entry was found');

        Http::assertNotSent(fn ($r) => (string) $r->url() === self::INITIATE_URL);
    }

    public function test_stops_when_ghs_account_is_not_active(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response([
                'data' => [
                    ['currency' => 'GHS', 'payazaAccountReference' => 'GHS-REF', 'status' => 'SUSPENDED'],
                ],
            ], 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'X', '--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain("status is 'SUSPENDED'");

        Http::assertNotSent(fn ($r) => (string) $r->url() === self::INITIATE_URL);
    }

    public function test_account_enquiry_response_never_prints_balance_fields(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response([
                'data' => [[
                    'currency' => 'GHS',
                    'payazaAccountReference' => 'GHS-ACCT-REF-DIAGNOSTIC',
                    'status' => 'ACTIVE',
                    'balance' => 999999.99,
                    'account_name' => 'Some Merchant Name',
                ]],
            ], 200),
            self::INITIATE_URL => Http::response(['transaction_status' => 'TRANSACTION_INITIATED'], 200),
            self::STATUS_URL => Http::response(['transaction_status' => 'TRANSACTION_INITIATED'], 200),
        ]);

        \Artisan::call('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ]);
        $output = \Artisan::output();

        $this->assertStringNotContainsString('999999.99', $output);
        $this->assertStringNotContainsString('Some Merchant Name', $output);
        $this->assertStringNotContainsString('GHS-ACCT-REF-DIAGNOSTIC', $output, 'The discovered account_reference itself must never be printed.');
    }

    // ---------------------------------------------------------------- request content correctness

    public function test_payload_uses_gha_country_code_and_discovered_account_reference(): void
    {
        $this->makeSandboxPayoutConfig();
        $this->fakeHappyPath();

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->run();

        $initiateBody = collect(Http::recorded())
            ->first(fn ($pair) => (string) $pair[0]->url() === self::INITIATE_URL)[0]->data();

        $this->assertSame('GHA', data_get($initiateBody, 'service_payload.country'));
        $this->assertSame('GHS-ACCT-REF-DIAGNOSTIC', data_get($initiateBody, 'service_payload.account_reference'));
    }

    public function test_payout_amount_equals_credit_amount_for_single_beneficiary(): void
    {
        $this->makeSandboxPayoutConfig();
        $this->fakeHappyPath();

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--amount' => '2.50',
            '--force' => true,
        ])->run();

        $initiateBody = collect(Http::recorded())
            ->first(fn ($pair) => (string) $pair[0]->url() === self::INITIATE_URL)[0]->data();

        $this->assertEquals(
            data_get($initiateBody, 'service_payload.payout_amount'),
            data_get($initiateBody, 'payout_beneficiaries.0.credit_amount')
        );
    }

    // ---------------------------------------------------------------- bank_code / force guards (now past enquiry)

    public function test_refuses_without_bank_code(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response($this->activeGhsEnquiryResponse(), 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--phone' => '0241234567', '--force' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('no --bank-code supplied');
    }

    public function test_refuses_without_force_confirmation(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response($this->activeGhsEnquiryResponse(), 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--bank-code' => 'MTN-GH', '--phone' => '0241234567'])
            ->assertExitCode(1)
            ->expectsOutputToContain('--force to confirm');

        Http::assertNotSent(fn ($r) => (string) $r->url() === self::INITIATE_URL);
    }

    // ---------------------------------------------------------------- secrecy

    public function test_never_prints_pin_or_secret_key_even_if_echoed_back(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response($this->activeGhsEnquiryResponse(), 200),
            self::INITIATE_URL => Http::response([
                'response_code' => '00',
                'transaction_reference' => 'whatever',
                'transaction_pin' => '135790', // hypothetical bad provider echo
                'secret_key' => 'sandbox-secret-key-value', // hypothetical bad provider echo
                'transaction_status' => 'TRANSACTION_INITIATED',
            ], 200),
            self::STATUS_URL => Http::response(['transaction_status' => 'TRANSACTION_INITIATED'], 200),
        ]);

        \Artisan::call('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ]);
        $output = \Artisan::output();

        $this->assertStringNotContainsString('135790', $output);
        $this->assertStringNotContainsString('sandbox-secret-key-value', $output);
        $this->assertStringContainsString('redacted', $output);
    }

    public function test_never_prints_authorization_header_value(): void
    {
        $this->makeSandboxPayoutConfig();
        $this->fakeHappyPath();

        \Artisan::call('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ]);
        $output = \Artisan::output();
        $encoded = base64_encode('sandbox-public-key-value');

        $this->assertStringNotContainsString($encoded, $output);
        $this->assertStringNotContainsString('sandbox-public-key-value', $output);
    }

    // ---------------------------------------------------------------- no-retry / ambiguity handling

    public function test_initiation_request_is_never_automatically_retried(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::ENQUIRY_URL => Http::response($this->activeGhsEnquiryResponse(), 200),
            self::INITIATE_URL => Http::response(['message' => 'server error'], 500),
            self::STATUS_URL => Http::response(['transaction_status' => 'NIP_PENDING'], 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->run();

        $initiateRequests = collect(Http::recorded())
            ->filter(fn ($pair) => (string) $pair[0]->url() === self::INITIATE_URL);

        $this->assertCount(1, $initiateRequests, 'The mutating payout POST must be sent at most once, even on a 500.');
    }

    public function test_timeout_on_initiation_leads_to_status_lookup_not_another_post(): void
    {
        $this->makeSandboxPayoutConfig();

        $initiateAttempts = 0;
        Http::fake([
            self::ENQUIRY_URL => Http::response($this->activeGhsEnquiryResponse(), 200),
            self::INITIATE_URL => function () use (&$initiateAttempts) {
                $initiateAttempts++;

                throw new ConnectionException('Connection timed out');
            },
            self::STATUS_URL => Http::response(['transaction_status' => 'TRANSACTION_INITIATED'], 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, $initiateAttempts, 'Exactly one initiation attempt, even after a timeout — never automatically retried.');

        $statusCount = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), '/transaction/status'))
            ->count();

        $this->assertGreaterThanOrEqual(1, $statusCount, 'A status lookup must follow a timeout.');
    }

    public function test_same_reference_used_for_initiation_and_status_lookup(): void
    {
        $this->makeSandboxPayoutConfig();
        $this->fakeHappyPath();

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->run();

        $recorded = collect(Http::recorded());

        $initiateBody = $recorded->first(fn ($pair) => (string) $pair[0]->url() === self::INITIATE_URL)[0]->data();
        $statusRequest = $recorded->first(fn ($pair) => str_contains((string) $pair[0]->url(), '/transaction/status'))[0];

        $initiatedReference = data_get($initiateBody, 'payout_beneficiaries.0.transaction_reference');
        $statusReference = $statusRequest->data()['transaction_reference'] ?? null;

        $this->assertNotEmpty($initiatedReference);
        $this->assertSame($initiatedReference, $statusReference);
    }

    public function test_reference_option_only_checks_status_never_initiates(): void
    {
        $this->makeSandboxPayoutConfig();

        Http::fake([
            self::STATUS_URL => Http::response(['transaction_status' => 'NIP_PENDING'], 200),
        ]);

        $this->artisan('payaza:payout-diagnostic', ['--reference' => 'PAYAZA-DIAG-EXISTING-1'])
            ->assertExitCode(0)
            ->expectsOutputToContain('skipping initiation entirely');

        Http::assertNotSent(fn ($request) => (string) $request->url() === self::INITIATE_URL);
        Http::assertNotSent(fn ($request) => (string) $request->url() === self::ENQUIRY_URL);
    }

    // ---------------------------------------------------------------- never touches withdrawals/wallets/collection config

    public function test_never_touches_vendor_withdrawals(): void
    {
        $this->makeSandboxPayoutConfig();
        $vendor = Vendor::factory()->create(['wallet_balance' => 500]);
        $existingWithdrawal = VendorWithdrawal::create([
            'vendor_id' => $vendor->id,
            'amount' => 50,
            'momo_number' => '0241234567',
            'momo_network' => VendorWithdrawal::NETWORK_MTN,
            'status' => VendorWithdrawal::STATUS_PROCESSING,
            'reference' => 'WD-EXISTING',
        ]);

        $this->fakeHappyPath();

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->run();

        $this->assertSame(1, VendorWithdrawal::count());
        $existingWithdrawal->refresh();
        $this->assertSame(VendorWithdrawal::STATUS_PROCESSING, $existingWithdrawal->status);
        $this->assertNull($existingWithdrawal->payout_reference);
    }

    public function test_never_touches_vendor_wallet_balance(): void
    {
        $this->makeSandboxPayoutConfig();
        $vendor = Vendor::factory()->create(['wallet_balance' => 500]);

        $this->fakeHappyPath();

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->run();

        $vendor->refresh();
        $this->assertSame(500.0, (float) $vendor->wallet_balance);
    }

    public function test_does_not_alter_payaza_collection_configuration(): void
    {
        $collection = PaymentGatewayConfig::create([
            'gateway_name' => PaymentGatewayConfig::GATEWAY_PAYAZA,
            'gateway_type' => PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION,
            'supports_collection' => true,
            'supports_generic' => true,
            'is_active' => true,
            'is_default' => true,
            'environment' => PaymentGatewayConfig::ENV_SANDBOX,
            'config_data' => [
                'public_key' => 'collection-pk',
                'secret_key' => 'collection-sk',
                'base_url' => 'https://api.payaza.africa/live',
            ],
        ]);
        $this->makeSandboxPayoutConfig();
        $this->fakeHappyPath();

        $this->artisan('payaza:payout-diagnostic', [
            '--bank-code' => 'MTN-GH',
            '--phone' => '0241234567',
            '--force' => true,
        ])->run();

        $collection->refresh();
        $this->assertSame('collection-pk', $collection->getConfig('public_key'));
        $this->assertTrue($collection->is_active);
        $this->assertTrue($collection->is_default);
    }
}
