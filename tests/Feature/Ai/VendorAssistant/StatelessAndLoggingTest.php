<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant is stateless (nothing in the database, session, cache or browser storage; no history endpoints) and logs
 * only redacted operational metadata (never a prompt, an answer, a tool argument, a tool result, or any order, customer
 * or financial value).
 */
class StatelessAndLoggingTest extends VendorAssistantTestCase
{
    private const QUESTION = 'ZEBRA_CANARY_QUESTION how many orders did I get';

    private const ANSWER = 'GIRAFFE_CANARY_ANSWER you made some sales';

    // ------------------------------------------------------------------------------------------------ stateless

    public function test_there_are_no_conversation_or_message_tables_or_migrations_for_the_assistant(): void
    {
        foreach (array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()) as $table) {
            if (str_starts_with($table, 'support_')) {
                continue; // the existing vendor<->admin support inbox: a different feature
            }
            $this->assertDoesNotMatchRegularExpression('/(^ai_|_ai_|assistant|conversation|chat|prompt|llm|completion)/i', $table, "unexpected table: {$table}");
        }

        foreach (glob(database_path('migrations/*.php')) as $migration) {
            if (! str_contains(basename($migration), 'support')) {
                $this->assertDoesNotMatchRegularExpression('/(assistant|conversation|ai_message|ai_chat|chat_message)/i', basename($migration));
            }
        }

        // No migration was added by this milestone: the newest migration predates it.
        $newest = collect(glob(database_path('migrations/*.php')))->map('basename')->sort()->last();
        $this->assertLessThan('2026_11_20', substr($newest, 0, 10));
    }

    public function test_a_full_tool_using_turn_leaves_no_trace_in_any_table_the_session_or_the_cache(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        Cache::forgetDriver();

        $w = $this->world();
        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('vendor_search_my_orders', ['product' => 'TOOLARG_CANARY'], 'c1'),
                $this->toolCall('vendor_get_dashboard_summary', [], 'c2'),
            ]),
            $this->textResponse(self::ANSWER),
        );

        $this->ask($w['a'], self::QUESTION)->assertOk()->assertJsonPath('answer', self::ANSWER);

        $found = [];
        foreach (array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()) as $table) {
            foreach (DB::table($table)->get() as $row) {
                $flat = json_encode($row);
                foreach (['ZEBRA_CANARY', 'GIRAFFE_CANARY', 'TOOLARG_CANARY'] as $canary) {
                    if (str_contains($flat, $canary)) {
                        $found[] = "{$table}: {$canary}";
                    }
                }
            }
        }
        $this->assertSame([], $found, 'prompt, answer or tool arguments were written to the database (sessions and cache included)');
    }

    public function test_a_turn_changes_no_business_table(): void
    {
        $w = $this->world();
        $tables = ['orders', 'transactions', 'products', 'reseller_products', 'vendors', 'afa_registrations', 'result_checker_orders', 'wallet_ledgers', 'vendor_withdrawals', 'network_services'];
        $snapshot = fn () => array_map(fn ($t) => Schema::hasTable($t) ? md5(DB::table($t)->get()->toJson()) : null, $tables);
        $before = $snapshot();

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('vendor_get_dashboard_summary', [], 'c1'),
                $this->toolCall('vendor_search_my_orders', [], 'c2'),
                $this->toolCall('vendor_list_my_products', [], 'c3'),
                $this->toolCall('vendor_get_earnings_summary', ['period' => 'all_time'], 'c4'),
            ]),
            $this->textResponse('done'),
        );
        $this->ask($w['a'], 'Show me everything')->assertOk();

        $this->assertSame($before, $snapshot());
    }

    public function test_nothing_is_flashed_or_kept_in_the_session(): void
    {
        $vendor = $this->approvedVendor();

        $this->ask($vendor, self::QUESTION)->assertOk()->assertSessionMissing('_old_input')->assertSessionMissing('errors');

        $session = json_encode($this->app['session.store']->all());
        $this->assertStringNotContainsString('ZEBRA_CANARY', $session);

        $this->signedIn($vendor)->post(route('vendor.assistant.message'), ['message' => self::QUESTION])->assertStatus(415)->assertSessionMissing('_old_input');
        $this->assertStringNotContainsString('ZEBRA_CANARY', json_encode($this->app['session.store']->all()));
    }

    public function test_every_question_stands_alone_and_earlier_ones_are_never_sent_again(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->queue($this->textResponse('FIRST_ANSWER_MARKER'), $this->textResponse('second'));

        $this->ask($vendor, 'FIRST_QUESTION_MARKER')->assertOk();
        $this->ask($vendor, 'second question')->assertOk();

        [, $second] = $this->mockProvider()->calls();
        $this->assertCount(2, $second->messages, 'system prompt + this question only');
        foreach ($second->messages as $m) {
            $this->assertStringNotContainsString('FIRST_QUESTION_MARKER', $m->content);
            $this->assertStringNotContainsString('FIRST_ANSWER_MARKER', $m->content);
        }
    }

    public function test_the_only_routes_are_the_page_and_the_single_message_endpoint(): void
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'vendor/') && str_contains($route->uri(), 'assistant')) {
                $found[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            }
        }
        sort($found);

        $this->assertSame(['GET vendor/assistant', 'POST vendor/assistant/messages'], $found);

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'assistant')) {
                $this->assertDoesNotMatchRegularExpression('/(conversation|history|transcript|thread)/i', $route->uri().' '.$route->getName());
            }
        }
    }

    public function test_there_is_nothing_to_read_back(): void
    {
        $vendor = $this->approvedVendor();
        $this->ask($vendor, self::QUESTION)->assertOk();

        $this->signedIn($vendor)->getJson('/vendor/assistant/messages')->assertStatus(405);
        $this->signedIn($vendor)->getJson('/vendor/assistant/history')->assertNotFound();
        $this->signedIn($vendor)->getJson('/vendor/assistant/conversations')->assertNotFound();
        $this->signedIn($vendor)->deleteJson('/vendor/assistant/messages')->assertStatus(405);
        $this->signedIn($vendor)->putJson('/vendor/assistant/messages', ['message' => 'x'])->assertStatus(405);
    }

    public function test_the_page_keeps_messages_in_browser_memory_only_and_sends_only_the_current_question(): void
    {
        $html = $this->signedIn($this->approvedVendor())->get(route('vendor.assistant.show'))->assertOk()->getContent();

        // The shared vendor layout carries unrelated checkout scripts, so the persistence check is on the assistant's own view.
        $source = (string) file_get_contents(resource_path('views/vendor/assistant.blade.php'));
        foreach (['localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'caches.open', 'serviceWorker', 'navigator.storage', 'openDatabase', 'history.pushState', 'BroadcastChannel', '$persist'] as $storage) {
            $this->assertStringNotContainsString($storage, $source, "client-side persistence: {$storage}");
        }
        $text = strtolower((string) preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('does not remember earlier questions', $text);
        $this->assertStringContainsString('nothing you type is saved', $text);

        // The request body is built from the current draft only.
        $this->assertMatchesRegularExpression('/JSON\.stringify\(\{\s*message:\s*\w+\s*\}\)/', $html);
        $this->assertStringNotContainsString('history:', $html);
    }

    public function test_answers_are_never_cacheable(): void
    {
        $vendor = $this->approvedVendor();

        $this->assertStringContainsString('no-store', (string) $this->ask($vendor, 'hello')->assertOk()->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->ask($vendor, str_repeat('a ', 700))->assertStatus(422)->headers->get('Cache-Control'), 'errors too');
    }

    // ------------------------------------------------------------------------------------------------ logging

    private function logMessages(): array
    {
        return array_column($this->logRecords(), 'message');
    }

    public function test_a_turn_logs_safe_events_and_none_of_the_content(): void
    {
        config(['logging.default' => 'ai_test']);
        $w = $this->world();
        $order = $this->makeOrder([
            'vendor_id' => $w['a']->id, 'service_purchased' => 'SERVICE_CANARY', 'recipient_phone_number' => '0557778899',
            'payment_reference' => 'PAYREF_CANARY_42', 'amount_paid' => 321.45,
        ]);
        $pasted = 'xt_live_abcd1234_'.str_repeat('K', 40);

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('vendor_search_my_orders', ['product' => 'SERVICE_CANARY'], 'c1'),
                $this->toolCall('vendor_get_my_order', ['order_ref' => $order->id], 'c2'),
                $this->toolCall('vendor_get_earnings_summary', ['period' => 'today'], 'c3'),
            ]),
            $this->textResponse('ANSWER_CANARY you earned GHS 141.40'),
        );

        $this->ask($w['a'], "PROMPT_CANARY my key {$pasted} customer 0241234567 mail buyer@example.com")->assertOk();

        foreach (['ai.request.completed', 'ai.tool.call', 'ai.vendor_assistant.turn'] as $expected) {
            $this->assertContains($expected, $this->logMessages(), "{$expected} should be logged");
        }

        $dump = $this->logDump();
        foreach ([
            'PROMPT_CANARY', 'ANSWER_CANARY', 'SERVICE_CANARY', 'PAYREF_CANARY_42', '0557778899', '0241234567', 'buyer@example.com',
            $pasted, 'xt_live_abcd1234', '321.45', '141.40', '165.00', 'Alpha Data', 'Bearer ', 'Authorization',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, "leaked into the logs: {$secret}");
        }

        $this->assertStringNotContainsString((string) $w['a']->email, $dump);
        $this->assertStringNotContainsString('vendor:'.$w['a']->id, $dump, 'the principal is a one-way fingerprint');
    }

    public function test_the_turn_record_carries_counts_and_timings_only(): void
    {
        config(['logging.default' => 'ai_test']);
        $this->mockProvider()->queue(
            $this->toolCallResponse([$this->toolCall('vendor_get_dashboard_summary', [], 'c1')]),
            $this->textResponse('done'),
        );

        $this->ask($this->approvedVendor(), 'summary?')->assertOk();

        $turn = collect($this->logRecords())->firstWhere('message', 'ai.vendor_assistant.turn');
        $this->assertNotNull($turn);
        $this->assertEqualsCanonicalizing(
            ['request_id', 'surface', 'principal', 'rounds', 'tool_calls_requested', 'tool_calls_run', 'tool_calls_ok', 'followup_calls_ignored', 'answer_chars', 'duration_ms', 'outcome'],
            array_keys($turn['context']),
        );
        $this->assertSame(2, $turn['context']['rounds']);
        $this->assertSame(1, $turn['context']['tool_calls_ok']);
        foreach ($turn['context'] as $key => $value) {
            $this->assertTrue(is_int($value) || in_array($key, ['request_id', 'surface', 'principal', 'outcome'], true), "{$key} must be a count or a fixed label");
        }
    }

    public function test_tool_records_hold_the_tool_name_and_sizes_not_arguments_or_results(): void
    {
        config(['logging.default' => 'ai_test']);
        $w = $this->world();
        $this->mockProvider()->queue(
            $this->toolCallResponse([$this->toolCall('vendor_list_my_products', ['name' => 'ARGVALUE_CANARY'], 'c1')]),
            $this->textResponse('ok'),
        );

        $this->ask($w['a'], 'products')->assertOk();

        $call = collect($this->logRecords())->firstWhere('message', 'ai.tool.call');
        $this->assertSame('vendor_list_my_products', $call['context']['tool']);
        $this->assertSame('ok', $call['context']['status']);
        $this->assertSame(1, $call['context']['args_n']);
        $this->assertArrayHasKey('result_bytes', $call['context']);
        $this->assertStringNotContainsString('ARGVALUE_CANARY', $this->logDump());
        $this->assertStringNotContainsString('MTN 5GB', $this->logDump(), 'product names from tool results are not logged');
    }

    public function test_an_unexpected_provider_failure_is_logged_generically(): void
    {
        config(['logging.default' => 'ai_test']);
        $this->mockProvider()->queue(fn () => throw new \LogicException('PROMPT_CANARY internal /var/www/secret.php sk-LIVEKEY1234567890'));

        $response = $this->ask($this->approvedVendor(), 'PROMPT_CANARY hello')->assertStatus(503);

        $this->assertSame('ai_unavailable', $response->json('error.code'));
        foreach ([$this->logDump(), $response->getContent()] as $text) {
            foreach (['PROMPT_CANARY', 'secret.php', 'sk-LIVEKEY', '/var/www', 'LogicException'] as $leak) {
                $this->assertStringNotContainsString($leak, $text);
            }
        }
    }

    public function test_a_failure_inside_the_assistant_itself_is_a_generic_500_that_logs_only_the_class(): void
    {
        config(['logging.default' => 'ai_test']);
        $this->mock(VendorAssistant::class, function ($mock) {
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
        config(['logging.default' => 'ai_test']);
        $vendor = $this->approvedVendor();

        $this->ask($vendor, str_repeat('LONG_CANARY ', 200))->assertStatus(422);
        $this->ask($this->unapprovedVendor(), 'DENIED_CANARY');
        $this->signedIn($vendor)->post(route('vendor.assistant.message'), ['message' => 'FORM_CANARY'])->assertStatus(415);

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

    public function test_the_rate_limit_is_per_vendor_and_applies_to_the_message_endpoint(): void
    {
        $a = $this->approvedVendor();
        $b = $this->approvedVendor();
        RateLimiter::clear('vendor-assistant:vendor:'.$a->id);
        RateLimiter::clear('vendor-assistant:vendor:'.$b->id);

        $statuses = [];
        foreach (range(1, 12) as $i) {
            $statuses[] = $this->ask($a, "question {$i}")->status();
        }

        $this->assertSame(429, end($statuses), 'the eleventh and later requests in a minute are throttled');
        $this->assertSame(10, count(array_filter($statuses, fn ($s) => $s === 200)));
        $this->ask($b, 'another vendor is unaffected')->assertOk();
    }
}
