<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use App\Services\Ai\Developer\DeveloperAssistant;

/**
 * Only redacted operational metadata is logged: never the prompt, the answer, tool arguments or tool output, and
 * never anything key-shaped. Everything the application logs during a turn is captured (the default channel is
 * pointed at the in-memory test channel), not only the AI channel.
 */
class LoggingTest extends DeveloperAssistantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'ai_test']);
    }

    private function messages(): array
    {
        return array_column($this->logRecords(), 'message');
    }

    public function test_a_turn_logs_safe_events_and_none_of_the_content(): void
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor, 'APPNAME_CANARY');
        [, $plaintext] = $this->makeKey($app);
        $pasted = 'xt_live_abcd1234_'.str_repeat('K', 40);

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('developer_search_docs', ['query' => 'TOOLARG_CANARY signature'], 'c1'),
                $this->toolCall('developer_list_my_apps', [], 'c2'),
                $this->toolCall('developer_get_my_app', ['app_ref' => $app->id], 'c3'),
            ]),
            $this->textResponse('ANSWER_CANARY please rotate the key'),
        );

        $this->ask($vendor, "PROMPT_CANARY my key {$pasted} phone 0241234567 mail dev@example.com")->assertOk();

        $messages = $this->messages();
        foreach (['ai.request.completed', 'ai.tool.call', 'ai.developer_assistant.turn'] as $expected) {
            $this->assertContains($expected, $messages, "{$expected} should be logged");
        }

        $dump = $this->logDump();
        foreach ([
            'PROMPT_CANARY', 'ANSWER_CANARY', 'TOOLARG_CANARY', 'APPNAME_CANARY',
            $pasted, 'xt_live_abcd1234', $plaintext, '0241234567', 'dev@example.com',
            'Bearer ', 'Authorization',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, "leaked into the logs: {$secret}");
        }

        // The principal is a one-way fingerprint, never the vendor id, email or name.
        $this->assertStringNotContainsString((string) $vendor->email, $dump);
        $this->assertStringNotContainsString('vendor:'.$vendor->id, $dump);
    }

    public function test_the_turn_record_carries_counts_and_timings_only(): void
    {
        $this->mockProvider()->queue(
            $this->toolCallResponse([$this->toolCall('developer_search_docs', ['query' => 'scopes'], 'c1')]),
            $this->textResponse('done'),
        );

        $this->ask($this->approvedVendor(), 'scopes?')->assertOk();

        $turn = collect($this->logRecords())->firstWhere('message', 'ai.developer_assistant.turn');
        $this->assertNotNull($turn);
        $this->assertEqualsCanonicalizing(
            ['request_id', 'surface', 'principal', 'rounds', 'tool_calls_requested', 'tool_calls_run', 'tool_calls_ok', 'followup_calls_ignored', 'answer_chars', 'duration_ms', 'outcome'],
            array_keys($turn['context']),
        );
        $this->assertSame(2, $turn['context']['rounds']);
        $this->assertSame(1, $turn['context']['tool_calls_ok']);
        $this->assertIsInt($turn['context']['answer_chars']);
    }

    public function test_tool_records_hold_the_tool_name_and_sizes_not_arguments_or_results(): void
    {
        $this->mockProvider()->queue(
            $this->toolCallResponse([$this->toolCall('developer_read_doc', ['doc' => 'webhooks'], 'c1')]),
            $this->textResponse('ok'),
        );

        $this->ask($this->approvedVendor(), 'webhooks')->assertOk();

        $call = collect($this->logRecords())->firstWhere('message', 'ai.tool.call');
        $this->assertSame('developer_read_doc', $call['context']['tool']);
        $this->assertSame('ok', $call['context']['status']);
        $this->assertSame(1, $call['context']['args_n']);
        $this->assertArrayHasKey('result_bytes', $call['context']);
        $this->assertStringNotContainsString('webhooks', json_encode(array_diff_key($call['context'], ['tool' => 1])), 'the argument value is not logged');
    }

    public function test_an_unexpected_provider_failure_is_logged_generically_and_never_with_its_message(): void
    {
        $this->mockProvider()->queue(fn () => throw new \LogicException('PROMPT_CANARY internal /var/www/secret.php sk-LIVEKEY1234567890'));

        $response = $this->ask($this->approvedVendor(), 'PROMPT_CANARY hello')->assertStatus(503);

        // The M5.2 orchestrator turns any unexpected provider error into a generic one before it can travel anywhere.
        $this->assertSame('ai_unavailable', $response->json('error.code'));
        $record = collect($this->logRecords())->firstWhere('message', 'ai.request.failed');
        $this->assertSame('Unexpected failure while calling the provider.', $record['context']['error_detail']);

        foreach ([$this->logDump(), $response->getContent()] as $text) {
            foreach (['PROMPT_CANARY', 'secret.php', 'sk-LIVEKEY', '/var/www', 'LogicException'] as $leak) {
                $this->assertStringNotContainsString($leak, $text);
            }
        }
    }

    public function test_a_failure_inside_the_assistant_itself_is_a_generic_500_that_logs_only_the_class(): void
    {
        $this->mock(DeveloperAssistant::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('ask')->andThrow(new \LogicException('PROMPT_CANARY internal /var/www/secret.php sk-LIVEKEY1234567890'));
        });

        $response = $this->ask($this->approvedVendor(), 'PROMPT_CANARY hello')->assertStatus(500);

        $this->assertFalse($response->json('ok'));
        $dump = $this->logDump();
        $this->assertStringContainsString('LogicException', $dump, 'the exception CLASS is logged');
        foreach ([$dump, $response->getContent()] as $text) {
            foreach (['PROMPT_CANARY', 'secret.php', 'sk-LIVEKEY', '/var/www'] as $leak) {
                $this->assertStringNotContainsString($leak, $text);
            }
        }
    }

    public function test_refused_requests_log_no_message_text(): void
    {
        $vendor = $this->approvedVendor();

        $this->ask($vendor, str_repeat('LONG_CANARY ', 200))->assertStatus(422);
        $this->ask($this->unapprovedVendor(), 'DENIED_CANARY')->assertForbidden();
        $this->signedIn($vendor)->post(route('developers.assistant.message'), ['message' => 'FORM_CANARY'])->assertStatus(415);

        foreach (['LONG_CANARY', 'DENIED_CANARY', 'FORM_CANARY'] as $leak) {
            $this->assertStringNotContainsString($leak, $this->logDump());
        }
    }

    public function test_the_provider_receives_the_scrubbed_message_never_the_raw_secret(): void
    {
        $key = 'xt_test_wxyz9876_'.str_repeat('Q', 40);
        $this->ask($this->approvedVendor(), "headers: Authorization: Bearer {$key}\nplease check")->assertOk();

        $seen = json_encode($this->mockProvider()->calls()[0]->messages);
        $this->assertStringNotContainsString($key, $seen);
        $this->assertStringNotContainsString('wxyz9876', $seen);
        $this->assertStringContainsString('please check', $seen);
    }
}
