<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Logging\AiLogger;
use App\Services\Ai\Logging\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;

class LoggingAndRedactionTest extends AiTestCase
{
    private const PROMPT = 'My momo number is 0241234567 and my key is sk-abcdefghijklmnop1234, please recommend a bundle';

    private const RESPONSE = 'Sure, I recommend the SECRET-RESPONSE-TEXT bundle for your number.';

    public function test_a_successful_request_logs_metadata_only(): void
    {
        $this->mockProvider()->respondWith(self::RESPONSE, new AiUsage(12, 8));

        $result = $this->engine()->run($this->req(self::PROMPT, principal: 'vendor:77'));

        $dump = $this->logDump();
        $this->assertStringNotContainsString('0241234567', $dump, 'raw phone numbers are never logged');
        $this->assertStringNotContainsString('sk-abcdefghijklmnop1234', $dump);
        $this->assertStringNotContainsString('momo number', $dump, 'the prompt is never logged');
        $this->assertStringNotContainsString('SECRET-RESPONSE-TEXT', $dump, 'the response is never logged');
        $this->assertStringNotContainsString('vendor:77', $dump, 'only a one-way hash of the principal is logged');

        $records = $this->logRecords();
        $this->assertCount(1, $records);
        $this->assertSame('ai.request.completed', $records[0]['message']);
        $c = $records[0]['context'];
        $this->assertSame($result->requestId, $c['request_id']);
        $this->assertSame('vendor', $c['surface']);
        $this->assertSame('mock', $c['provider']);
        $this->assertSame('mock-model', $c['model']);
        $this->assertSame(12, $c['input_tokens']);
        $this->assertSame(8, $c['output_tokens']);
        $this->assertSame('success', $c['outcome']);
        $this->assertArrayHasKey('duration_ms', $c);
        $this->assertSame(substr(hash('sha256', 'vendor:77'), 0, 12), $c['principal']);
    }

    public function test_a_failed_request_logs_the_error_class_and_code_but_no_content(): void
    {
        $this->mockProvider()->failWith(AiProviderException::timeout('upstream said Authorization: Bearer abcdefgh12345678 for 0241234567'));

        try {
            $this->engine()->run($this->req(self::PROMPT));
        } catch (AiProviderException) {
        }

        $dump = $this->logDump();
        $this->assertStringNotContainsString('abcdefgh12345678', $dump);
        $this->assertStringNotContainsString('0241234567', $dump);
        $this->assertStringNotContainsString('momo number', $dump);

        $r = $this->logRecords()[0];
        $this->assertSame('ai.request.failed', $r['message']);
        $this->assertSame('failure', $r['context']['outcome']);
        $this->assertSame('ai_unavailable', $r['context']['error_code']);
        $this->assertSame(AiProviderException::class, $r['context']['error_class']);
        $this->assertArrayNotHasKey('trace', $r['context']);
    }

    public function test_a_refused_request_is_logged_as_rejected(): void
    {
        config(['ai.rate_limits.vendor.per_minute' => 1]);
        $this->engine()->run($this->req());

        try {
            $this->engine()->run($this->req(self::PROMPT));
        } catch (AiRateLimitedException) {
        }

        $records = $this->logRecords();
        $this->assertSame('ai.request.rejected', $records[1]['message']);
        $this->assertSame('ai_rate_limited', $records[1]['context']['error_code']);
        $this->assertStringNotContainsString('momo number', $this->logDump());
    }

    public function test_logging_failures_can_never_break_a_request(): void
    {
        config(['ai.logging.channel' => 'no_such_channel']);

        $this->assertSame('mock', $this->engine()->run($this->req())->response->provider);
    }

    public function test_ai_exceptions_redact_their_own_messages_and_carry_no_previous_exception(): void
    {
        $e = new AiProviderException(AiProviderException::UNAVAILABLE, 'Authorization: Bearer abcdefgh12345678 api_key=sk-zzzzzzzzzzzz1234 phone 0241234567');

        $this->assertStringNotContainsString('abcdefgh12345678', $e->getMessage());
        $this->assertStringNotContainsString('sk-zzzzzzzzzzzz1234', $e->getMessage());
        $this->assertStringNotContainsString('0241234567', $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    #[DataProvider('secrets')]
    public function test_the_redactor_masks_secrets_and_personal_data(string $input, string $mustNotContain): void
    {
        $this->assertStringNotContainsString($mustNotContain, Redactor::redact($input));
    }

    public static function secrets(): array
    {
        return [
            'bearer token' => ['Authorization: Bearer abc123def456ghi789', 'abc123def456ghi789'],
            'bearer inline' => ['sent bearer abc123def456ghi789 here', 'abc123def456ghi789'],
            'basic auth' => ['Basic dXNlcjpwYXNzd29yZA== sent', 'dXNlcjpwYXNzd29yZA'],
            'openai-style key' => ['key sk-proj-abcdefghijklmnop1234', 'abcdefghijklmnop1234'],
            'platform live key' => ['xt_live_abcd1234_'.str_repeat('A', 40), str_repeat('A', 40)],
            'platform test key' => ['xt_test_abcd1234_secretsecret', 'secretsecret'],
            'api_key assignment' => ['api_key=supersecretvalue', 'supersecretvalue'],
            'password json' => ['{"password": "hunter2hunter2"}', 'hunter2hunter2'],
            'email' => ['mail kofi.mensah@example.com now', 'kofi.mensah@example.com'],
            'local phone' => ['call 0241234567 please', '0241234567'],
            'spaced phone' => ['call 024 123 4567 please', '123 4567'],
            'intl phone' => ['call +233241234567 please', '241234567'],
            'intl phone no plus' => ['call 233241234567 please', '241234567'],
            'long number' => ['ref 1234567890123', '1234567890123'],
            'opaque token' => ['t '.str_repeat('a1B2', 12), str_repeat('a1B2', 12)],
        ];
    }

    public function test_the_redactor_leaves_ordinary_text_alone(): void
    {
        $this->assertSame('Hello, show me 5GB bundles under GHS 20.', Redactor::redact('Hello, show me 5GB bundles under GHS 20.'));
    }

    public function test_context_sanitising_drops_sensitive_keys_and_keeps_counts(): void
    {
        $out = Redactor::context([
            'request_id' => 'abc', 'input_tokens' => 5, 'output_tokens' => 7,
            'prompt' => 'secret prompt', 'messages' => ['x'], 'response_text' => 'secret', 'Authorization' => 'Bearer x',
            'api_key' => 'k', 'customer_phone' => '0241234567', 'payment_reference' => 'PAY123', 'tool_arguments' => ['a' => 1],
            'note' => 'call 0241234567', 'object' => new \stdClass,
        ]);

        $this->assertSame(['request_id' => 'abc', 'input_tokens' => 5, 'output_tokens' => 7, 'note' => 'call [PHONE]'], $out);
    }

    public function test_the_logger_has_no_way_to_receive_message_content(): void
    {
        // Structural guard: the typed logging methods accept no message/prompt/response parameter at all.
        foreach ((new \ReflectionClass(AiLogger::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $p) {
                $this->assertNotContains($p->getName(), ['message', 'messages', 'prompt', 'response', 'content', 'text', 'arguments', 'headers'], "AiLogger::{$method->getName()} must not take content");
            }
        }

        $this->assertInstanceOf(AiRequest::class, new AiRequest(AiSurface::Vendor, 'v:1', [AiMessage::user('x')]));
    }
}
