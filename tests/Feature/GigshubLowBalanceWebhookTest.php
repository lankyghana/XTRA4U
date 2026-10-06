<?php

namespace Tests\Feature;

use App\Models\GigshubLowBalanceAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GigshubLowBalanceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.gigshub.webhook_secret' => self::SECRET]);
    }

    private function payload(array $override = []): array
    {
        return array_merge(['event' => 'balance.low', 'balance' => 12.5, 'threshold' => 50, 'currency' => 'GHS'], $override);
    }

    private function signed(array $payload): array
    {
        $body = json_encode($payload);

        return [$body, ['X-Gigshub-Signature' => hash_hmac('sha256', $body, self::SECRET), 'CONTENT_TYPE' => 'application/json']];
    }

    private function hook(array $payload, ?array $headers = null)
    {
        [$body, $signedHeaders] = $this->signed($payload);
        $headers ??= $signedHeaders;
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $this->call('POST', '/webhooks/gigshub/balance-low', [], [], [], $server, $body);
    }

    public function test_unsigned_request_is_rejected_and_writes_nothing(): void
    {
        $this->hook($this->payload(), [])->assertStatus(401);

        $this->assertSame(0, GigshubLowBalanceAlert::count());
    }

    public function test_wrong_signature_is_rejected(): void
    {
        $this->hook($this->payload(), ['X-Gigshub-Signature' => 'nope'])->assertStatus(401);

        $this->assertSame(0, GigshubLowBalanceAlert::count());
    }

    public function test_signed_request_creates_an_alert(): void
    {
        $this->hook($this->payload())->assertOk();

        $this->assertSame(1, GigshubLowBalanceAlert::count());
    }

    public function test_non_numeric_balance_is_a_422_not_a_500(): void
    {
        $this->hook($this->payload(['balance' => 'lots']))->assertStatus(422);

        $this->assertSame(0, GigshubLowBalanceAlert::count());
    }

    public function test_numeric_strings_are_accepted(): void
    {
        $this->hook($this->payload(['balance' => '12.50', 'threshold' => '50']))->assertOk();

        $this->assertSame(1, GigshubLowBalanceAlert::count());
    }

    public function test_duplicate_within_cooldown_is_suppressed(): void
    {
        $this->hook($this->payload())->assertOk();
        $this->hook($this->payload())->assertOk();

        $this->assertSame(1, GigshubLowBalanceAlert::count());
    }

    public function test_alert_is_allowed_again_after_cooldown(): void
    {
        $this->hook($this->payload())->assertOk();
        Cache::put('gigshub_last_low_balance_alert_at', now()->subMinutes(10), 3600);
        $this->hook($this->payload())->assertOk();

        $this->assertSame(2, GigshubLowBalanceAlert::count());
    }
}
