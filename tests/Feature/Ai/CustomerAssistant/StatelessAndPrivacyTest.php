<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\Tools\ReadOnlyGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Stateless by construction (no conversation storage anywhere, no history endpoint, nothing in session/cache/files/
 * browser storage), private (no raw prompt, answer, tool argument or result in logs, no sensitive data to tools), and
 * free of business side effects (a whole turn changes no table and only ever reads the public catalogue tables).
 */
class StatelessAndPrivacyTest extends CustomerAssistantTestCase
{
    private const QUESTION = 'ZEBRA_CANARY_QUESTION which bundles do you have';

    private const ANSWER = 'GIRAFFE_CANARY_ANSWER here are our bundles';

    // ------------------------------------------------------------------------------------------------ stateless

    public function test_no_conversation_or_message_table_or_migration_exists_for_the_assistant(): void
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

        // M5.6 adds NO migration: the newest one predates the milestone.
        $newest = collect(glob(database_path('migrations/*.php')))->map('basename')->sort()->last();
        $this->assertLessThan('2026_11_20', substr($newest, 0, 10));
    }

    public function test_a_full_tool_using_turn_leaves_no_trace_in_any_table_the_session_or_the_cache(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        Cache::forgetDriver();

        $this->catalogue();
        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('customer_search_products', ['query' => 'TOOLARG_CANARY mtn'], 'c1'),
                $this->toolCall('customer_search_help', ['query' => 'TOOLARG_CANARY payment'], 'c2'),
            ]),
            $this->textResponse(self::ANSWER),
        );

        $this->say(self::QUESTION)->assertOk()->assertJsonPath('answer', self::ANSWER);

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

    public function test_a_turn_changes_no_table_at_all(): void
    {
        $this->catalogue();
        $this->faq('How to pay', 'Pay at checkout.');
        $tables = array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables());
        $snapshot = fn () => array_map(fn ($t) => md5(DB::table($t)->get()->toJson()), array_combine($tables, $tables));
        $before = $snapshot();

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('customer_list_services', [], 'c1'),
                $this->toolCall('customer_search_products', ['limit' => 10], 'c2'),
                $this->toolCall('customer_get_product', ['product_ref' => 'product_1'], 'c3'),
                $this->toolCall('customer_search_help', ['query' => 'pay'], 'c4'),
            ]),
            $this->textResponse('done'),
        );
        $this->say('Show me everything')->assertOk();

        $after = $snapshot();
        $this->assertSame([], array_keys(array_diff_assoc($before, $after)), 'a business or any other table changed during a turn');
    }

    public function test_no_business_table_is_even_queried_only_the_public_catalogue_is_read(): void
    {
        $this->catalogue();
        $this->faq('How to pay', 'Pay at checkout.');
        $queried = [];
        DB::listen(function ($q) use (&$queried) {
            preg_match_all('/\b(?:from|join|into|update)\s+[`"\[]?([a-z_]+)/i', $q->sql, $m);
            array_push($queried, ...array_map('strtolower', $m[1]));
        });

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('customer_list_services', [], 'c1'),
                $this->toolCall('customer_search_products', ['limit' => 10], 'c2'),
                $this->toolCall('customer_get_product', ['product_ref' => 'product_1'], 'c3'),
                $this->toolCall('customer_get_product', ['product_ref' => 'reseller_1'], 'c4'),
                $this->toolCall('customer_search_help', ['query' => 'pay'], 'c5'),
            ]),
            $this->textResponse('done'),
        );
        // Run the tool turn first, then read what was queried by exactly that turn.
        $this->say('Show me everything')->assertOk();
        $queried = array_values(array_unique($queried));

        $allowed = ['products', 'reseller_products', 'vendors', 'settings', 'network_services', 'vendor_result_checker_settings', 'cms_faqs',
            // plumbing the framework itself issues around any request, never a tool
            'sessions', 'cache', 'cache_locks', 'jobs', 'migrations'];
        $this->assertSame([], array_values(array_diff($queried, $allowed)), 'unexpected table(s) queried');

        foreach (['orders', 'transactions', 'order_', 'wallet', 'withdraw', 'payment', 'result_checker_orders', 'result_checker_pins', 'afa_registrations', 'recipient_number', 'developer', 'api_key', 'webhook', 'utility_bill', 'users', 'admins', 'support_'] as $private) {
            foreach ($queried as $table) {
                $this->assertStringNotContainsString($private, $table, "the customer assistant queried [{$table}]");
            }
        }
    }

    public function test_every_tool_runs_only_read_only_sql(): void
    {
        $this->catalogue();
        $this->faq('How to pay', 'Pay at checkout.');
        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        foreach ([
            ['customer_list_services', []],
            ['customer_search_products', ['query' => 'mtn']],
            ['customer_get_product', ['product_ref' => 'product_1']],
            ['customer_get_product', ['product_ref' => 'reseller_1']],
            ['customer_get_product', ['product_ref' => 'result_checker_1']],
            ['customer_search_help', ['query' => 'payment']],
        ] as $i => [$name, $args]) {
            $this->assertTrue($this->runTool($name, $args, null, 'c'.$i)->isOk(), $name);
        }

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertTrue(ReadOnlyGuard::isReadOnlySql($sql), 'non-read statement during tools: '.$sql);
        }
    }

    public function test_nothing_is_flashed_or_kept_in_the_session(): void
    {
        $this->say(self::QUESTION)->assertOk()->assertSessionMissing('_old_input')->assertSessionMissing('errors');

        $this->assertStringNotContainsString('ZEBRA_CANARY', json_encode($this->app['session.store']->all()));

        $this->post(route('customer.assistant.message'), ['message' => self::QUESTION])->assertStatus(415)->assertSessionMissing('_old_input');
        $this->assertStringNotContainsString('ZEBRA_CANARY', json_encode($this->app['session.store']->all()));
    }

    public function test_every_question_stands_alone_and_earlier_ones_are_never_sent_again(): void
    {
        $this->mockProvider()->queue($this->textResponse('FIRST_ANSWER_MARKER'), $this->textResponse('second'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->say('FIRST_QUESTION_MARKER')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->say('second question')->assertOk();

        [, $second] = $this->mockProvider()->calls();
        $this->assertCount(2, $second->messages, 'system prompt + this question only');
        foreach ($second->messages as $m) {
            $this->assertStringNotContainsString('FIRST_QUESTION_MARKER', $m->content);
            $this->assertStringNotContainsString('FIRST_ANSWER_MARKER', $m->content);
        }
    }

    public function test_there_is_nothing_to_read_back(): void
    {
        $this->say(self::QUESTION)->assertOk();

        $this->getJson('/assistant/messages')->assertStatus(405);
        $this->getJson('/assistant/history')->assertNotFound();
        $this->getJson('/assistant/conversations')->assertNotFound();
        $this->deleteJson('/assistant/messages')->assertStatus(405);
        $this->putJson('/assistant/messages', ['message' => 'x'])->assertStatus(405);

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'assistant')) {
                $this->assertDoesNotMatchRegularExpression('/(conversation|history|transcript|thread)/i', $route->uri().' '.$route->getName());
            }
        }
    }

    public function test_the_page_keeps_messages_in_browser_memory_only_and_sends_only_the_current_message(): void
    {
        $html = $this->get(route('customer.assistant.show'))->assertOk()->getContent();

        // The shared layout carries unrelated checkout scripts, so the persistence check is on the assistant's own view.
        $source = (string) file_get_contents(resource_path('views/customer/assistant.blade.php'));
        foreach (['localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'caches.open', 'serviceWorker', 'navigator.storage', 'openDatabase', 'history.pushState', 'BroadcastChannel', '$persist'] as $storage) {
            $this->assertStringNotContainsString($storage, $source, "client-side persistence: {$storage}");
        }
        $text = strtolower((string) preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('does not remember earlier messages', $text);
        $this->assertStringContainsString('only gives information', $text);

        $this->assertMatchesRegularExpression('/JSON\.stringify\(\{\s*message:\s*\w+\s*\}\)/', $html);
        $this->assertStringNotContainsString('history:', $html);
    }

    public function test_the_page_has_the_accessible_controls_and_states_it_needs(): void
    {
        $html = $this->get(route('customer.assistant.show'))->assertOk()->getContent();

        foreach (['<label for="assistant-message"', 'aria-live="polite"', 'role="status"', 'Start over', ':disabled="loading', 'Try a question', 'maxlength'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('width=device-width', $html, 'mobile viewport');
        $this->assertStringContainsString('too quickly', $html, 'a rate-limited visitor gets a clear message');
    }

    public function test_answers_are_never_cacheable(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->say('hello')->assertOk()->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->say(str_repeat('a ', 700))->assertStatus(422)->headers->get('Cache-Control'), 'errors too');
    }

    // ------------------------------------------------------------------------------------------------ logging

    public function test_a_turn_logs_safe_events_and_none_of_the_content(): void
    {
        config(['logging.default' => 'ai_test']);
        $this->catalogue();

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('customer_search_products', ['query' => 'TOOLARG_CANARY'], 'c1'),
                $this->toolCall('customer_get_product', ['product_ref' => 'product_1'], 'c2'),
            ]),
            $this->textResponse(self::ANSWER.' for 0241234567'),
        );

        $this->say(self::QUESTION.' my number 0551112222 mail buyer@example.com ref 123456789012')->assertOk();

        $dump = $this->logDump();
        foreach (['ZEBRA_CANARY', 'GIRAFFE_CANARY', 'TOOLARG_CANARY', '0551112222', '0241234567', 'buyer@example.com', '123456789012', 'MTN 1GB', 'Alpha Data', 'SECRET-SVC', 'Great value'] as $canary) {
            $this->assertStringNotContainsString($canary, $dump, "[{$canary}] reached the log");
        }

        $messages = array_column($this->logRecords(), 'message');
        $this->assertContains('ai.customer_assistant.turn', $messages);

        $turn = collect($this->logRecords())->firstWhere('message', 'ai.customer_assistant.turn');
        $this->assertEqualsCanonicalizing(
            ['request_id', 'surface', 'principal', 'rounds', 'tool_calls_requested', 'tool_calls_run', 'tool_calls_ok', 'followup_calls_ignored', 'answer_chars', 'duration_ms', 'outcome'],
            array_keys($turn['context']),
        );
        $this->assertSame('customer', $turn['context']['surface']);
        $this->assertSame(2, $turn['context']['rounds']);
        $this->assertSame(12, strlen($turn['context']['principal']), 'a short hash, never the session id or the IP address');
    }

    public function test_the_ip_address_and_session_id_are_never_logged(): void
    {
        config(['logging.default' => 'ai_test']);
        $this->mockProvider()->respondWith('ok');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->say('hello')->assertOk();

        $this->assertStringNotContainsString('203.0.113.77', $this->logDump());
        $this->assertStringNotContainsString($this->app['session.store']->getId(), $this->logDump());
    }

    public function test_a_failed_turn_logs_only_the_failure_class(): void
    {
        config(['logging.default' => 'ai_test']);
        $this->mock(CustomerAssistant::class, function ($mock) {
            $mock->shouldReceive('ask')->andThrow(new \RuntimeException('QUESTION_CANARY_IN_EXCEPTION'));
        });

        $this->say(self::QUESTION)->assertStatus(500);

        $dump = $this->logDump();
        $this->assertStringNotContainsString('ZEBRA_CANARY', $dump);
        $this->assertStringNotContainsString('QUESTION_CANARY_IN_EXCEPTION', $dump);
        $this->assertStringContainsString(\RuntimeException::class, $dump);
    }
}
