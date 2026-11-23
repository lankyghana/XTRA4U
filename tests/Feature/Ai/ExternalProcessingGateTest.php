<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Safety\ExternalProcessingGate;
use Illuminate\Support\Env;
use Tests\Feature\Ai\Support\ConfiguresOpenAi;

/**
 * The privacy approval gate and the "safe by default" shape of the shipped configuration.
 */
class ExternalProcessingGateTest extends AiTestCase
{
    use ConfiguresOpenAi;

    private function gate(): ExternalProcessingGate
    {
        return app(ExternalProcessingGate::class);
    }

    private function record(array $over = []): void
    {
        config(['ai.external_processing' => $over + ['approved' => true, 'approval_reference' => 'PRIV-REVIEW-2026-001', 'approved_by' => 'Data Protection Officer', 'approved_on' => '2026-01-15']]);
    }

    public function test_a_complete_dated_record_opens_the_gate(): void
    {
        $this->record();

        $this->assertSame([], $this->gate()->problems());
        $this->assertTrue($this->gate()->isOpen());
        $this->gate()->assertOpen();
    }

    public function test_the_gate_is_closed_by_default_and_by_anything_other_than_a_literal_true(): void
    {
        foreach ([false, null, 'true', 'yes', 1, '1', 'on', [], 'false'] as $value) {
            $this->record(['approved' => $value]);
            $this->assertFalse($this->gate()->isOpen(), var_export($value, true));
        }

        $this->expectException(AiConfigurationException::class);
        $this->gate()->assertOpen();
    }

    public function test_an_approval_flag_alone_is_not_enough(): void
    {
        config(['ai.external_processing' => ['approved' => true]]);

        $problems = $this->gate()->problems();

        $this->assertCount(3, $problems);
        $this->assertFalse($this->gate()->isOpen());
    }

    public function test_each_field_of_the_record_is_validated(): void
    {
        $cases = [
            'reference missing' => ['approval_reference' => null],
            'reference blank' => ['approval_reference' => '   '],
            'reference too short' => ['approval_reference' => 'ab'],
            'reference with control characters' => ['approval_reference' => "PRIV-1\n2"],
            'approver missing' => ['approved_by' => null],
            'approver too short' => ['approved_by' => 'ab'],
            'approver too long' => ['approved_by' => str_repeat('a', 121)],
            'date missing' => ['approved_on' => null],
            'date wrong format' => ['approved_on' => '15/01/2026'],
            'date not a real day' => ['approved_on' => '2026-02-30'],
            'date with time' => ['approved_on' => '2026-01-15 10:00'],
            'date in the future' => ['approved_on' => now()->addDays(2)->format('Y-m-d')],
        ];

        foreach ($cases as $label => $over) {
            $this->record($over);
            $this->assertNotSame([], $this->gate()->problems(), $label);
            $this->assertFalse($this->gate()->isOpen(), $label);
        }

        $this->record(['approved_on' => now()->format('Y-m-d')]);
        $this->assertTrue($this->gate()->isOpen(), 'today is accepted');
    }

    public function test_problems_name_the_settings_but_never_echo_a_value(): void
    {
        $this->record(['approval_reference' => 'x', 'approved_by' => 'SECRET-APPROVER-NAME-X']);
        config(['ai.external_processing.approved_by' => str_repeat('SECRET-APPROVER-NAME-', 10)]);

        $text = implode(' ', $this->gate()->problems());

        $this->assertStringContainsString('AI_EXTERNAL_PROCESSING_', $text);
        $this->assertStringNotContainsString('SECRET-APPROVER-NAME', $text);
    }

    public function test_the_shipped_configuration_is_disabled_mock_and_closed(): void
    {
        $shipped = $this->shippedConfigWithEmptyEnvironment();

        $this->assertFalse($shipped['enabled']);
        $this->assertSame('mock', $shipped['provider']);
        $this->assertNull($shipped['default_model'], 'no default model is shipped');
        $this->assertFalse($shipped['external_processing']['approved']);
        $this->assertFalse($shipped['providers']['mock']['allowed_in_production']);
        $this->assertSame([], $shipped['pricing'], 'no price is shipped or invented');
        $this->assertNull($shipped['providers']['openai']['api_key']);
        $this->assertArrayNotHasKey('base_url', $shipped['providers']['openai']);

        foreach (['customer', 'vendor', 'developer'] as $surface) {
            $this->assertFalse($shipped['surfaces'][$surface]['enabled'], "{$surface} surface is off by default");
        }

        $limits = [$shipped['limits']['global'], ...array_values($shipped['limits']['surfaces'])];
        foreach ($limits as $scope) {
            foreach ($scope as $name => $value) {
                $this->assertNull($value, "no budget value is shipped ({$name})");
            }
        }

        $this->assertFalse($shipped['operations']['direct_connections_confirmed']);
        $this->assertFalse($shipped['operations']['customer_worker_capacity_confirmed']);
    }

    public function test_env_example_ships_only_blank_placeholders_for_secrets_prices_limits_and_approval(): void
    {
        $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example')));
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*#?\s*(AI_[A-Z0-9_]+|TRUSTED_PROXIES)\s*=(.*)$/', $line, $m) === 1) {
                $values[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        $this->assertNotEmpty($values);
        $this->assertGreaterThan(20, count($values), 'the whole AI block is covered');
        $this->assertSame('false', $values['AI_ENABLED'] ?? 'false');
        $this->assertSame('mock', $values['AI_PROVIDER'] ?? 'mock');

        foreach ($values as $name => $value) {
            if (preg_match('/API_KEY|PRICE|USD_PER|_MODEL$|DAILY_|APPROVAL_REF|APPROVED_BY|APPROVED_ON|TRUSTED_PROXIES|PRICING_AS_OF/', $name) === 1) {
                $this->assertSame('', $value, "{$name} must be a blank placeholder in .env.example");
            }
            if (preg_match('/APPROVED$|CONFIRMED$/', $name) === 1) {
                $this->assertContains($value, ['', 'false'], "{$name} must ship closed");
            }
        }
    }

    /** @return array<string,mixed> config/ai.php evaluated as it would be with no AI_* variables defined */
    private function shippedConfigWithEmptyEnvironment(): array
    {
        $names = [];
        preg_match_all('/env\(\s*\'(AI_[A-Z0-9_]+)\'/', (string) file_get_contents(config_path('ai.php')), $m);
        foreach (array_unique($m[1]) as $name) {
            $names[$name] = getenv($name);
        }

        // Make every AI_* variable "unset" for the duration of the include, whatever the developer's own .env says.
        $repository = Env::getRepository();
        foreach ($names as $name => $_) {
            $repository->clear($name);
        }

        try {
            return require config_path('ai.php');
        } finally {
            foreach ($names as $name => $old) {
                if ($old !== false) {
                    $repository->set($name, (string) $old);
                }
            }
        }
    }
}
