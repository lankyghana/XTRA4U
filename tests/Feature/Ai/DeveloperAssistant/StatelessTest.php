<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant is stateless: no conversation or message storage, nothing kept in the database, session or cache, no
 * history endpoints. Memory lives in the browser tab only.
 */
class StatelessTest extends DeveloperAssistantTestCase
{
    private const QUESTION = 'ZEBRA_CANARY_QUESTION how do webhook signatures work';

    private const ANSWER = 'GIRAFFE_CANARY_ANSWER verify the v1 signature';

    public function test_there_are_no_conversation_or_message_tables_for_the_assistant(): void
    {
        $tables = array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables());

        foreach ($tables as $table) {
            if (str_starts_with($table, 'support_')) {
                continue; // the existing vendor<->admin support inbox: a different feature, not an AI store
            }
            $this->assertDoesNotMatchRegularExpression('/(^ai_|_ai_|assistant|conversation|chat|prompt|llm|completion)/i', $table, "unexpected table: {$table}");
        }

        foreach (glob(database_path('migrations/*.php')) as $migration) {
            if (str_contains(basename($migration), 'support')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/(assistant|conversation|ai_message|ai_chat|chat_message)/i', basename($migration));
        }
    }

    public function test_a_full_tool_using_turn_leaves_no_trace_in_any_table_the_session_or_the_cache(): void
    {
        // Put the stores that WOULD be used in production on the database, so a leak anywhere is visible in SQL.
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        Cache::forgetDriver();
        RateLimiter::clear('dev-assistant:ip:127.0.0.1');

        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor, 'My Store App');
        [, $plaintext] = $this->makeKey($app);

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('developer_search_docs', ['query' => 'TOOLARG_CANARY webhook signature'], 'c1'),
                $this->toolCall('developer_list_my_apps', [], 'c2'),
            ]),
            $this->textResponse(self::ANSWER),
        );

        $this->ask($vendor, self::QUESTION)->assertOk()->assertJsonPath('answer', self::ANSWER);

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

        // The only thing the cache/session may hold for this is engine metadata that never contains content.
        $this->assertStringNotContainsString($plaintext, json_encode(DB::table('cache')->get()));
        $this->assertStringNotContainsString($plaintext, json_encode(DB::table('sessions')->get()));
    }

    public function test_nothing_is_flashed_or_kept_in_the_session(): void
    {
        $vendor = $this->approvedVendor();

        $response = $this->ask($vendor, self::QUESTION)->assertOk();
        $response->assertSessionMissing('_old_input')->assertSessionMissing('errors');

        $session = json_encode($this->app['session.store']->all());
        $this->assertStringNotContainsString('ZEBRA_CANARY', $session);
        $this->assertStringNotContainsString('GIRAFFE_CANARY', $session);

        // A refused (non-JSON) post must not flash the typed text back either.
        $this->signedIn($vendor)->post(route('developers.assistant.message'), ['message' => self::QUESTION])->assertStatus(415)->assertSessionMissing('_old_input');
        $this->assertStringNotContainsString('ZEBRA_CANARY', json_encode($this->app['session.store']->all()));
    }

    public function test_every_question_stands_alone_and_earlier_ones_are_never_sent_again(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->queue($this->textResponse('first'), $this->textResponse('second'));

        $this->ask($vendor, 'FIRST_QUESTION_MARKER')->assertOk();
        $this->ask($vendor, 'second question')->assertOk();

        [, $second] = $this->mockProvider()->calls();
        $this->assertCount(2, $second->messages, 'system prompt + this question only');
        foreach ($second->messages as $m) {
            $this->assertStringNotContainsString('FIRST_QUESTION_MARKER', $m->content);
            $this->assertStringNotContainsString('first', $m->role->value === 'user' ? $m->content : '');
        }
    }

    public function test_the_only_routes_are_the_page_and_the_single_message_endpoint(): void
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'assistant')) {
                $found[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            }
        }
        sort($found);

        $this->assertSame(['GET developers/assistant', 'POST developers/assistant/messages'], $found);

        // (The vendor<->admin support inbox legitimately has "conversation" routes; look only where the assistant lives.)
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'developers') || str_starts_with($route->uri(), 'api/')) {
                $this->assertDoesNotMatchRegularExpression('/(conversation|history|transcript|thread)/i', $route->uri().' '.$route->getName(), 'a history endpoint exists: '.$route->uri());
            }
        }
    }

    public function test_there_is_nothing_to_read_back(): void
    {
        $vendor = $this->approvedVendor();
        $this->ask($vendor, self::QUESTION)->assertOk();

        $this->signedIn($vendor)->getJson('/developers/assistant/messages')->assertStatus(405);
        $this->signedIn($vendor)->getJson('/developers/assistant/history')->assertNotFound();
        $this->signedIn($vendor)->getJson('/developers/assistant/conversations')->assertNotFound();
        $this->signedIn($vendor)->deleteJson('/developers/assistant/messages')->assertStatus(405);
        $this->signedIn($vendor)->putJson('/developers/assistant/messages', ['message' => 'x'])->assertStatus(405);
    }

    public function test_the_page_keeps_messages_in_browser_memory_only(): void
    {
        $html = $this->signedIn($this->approvedVendor())->get(route('developers.assistant'))->assertOk()->getContent();

        foreach (['localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'caches.open', 'serviceWorker', 'navigator.storage', 'openDatabase', 'history.pushState', 'BroadcastChannel'] as $storage) {
            $this->assertStringNotContainsString($storage, $html, "client-side persistence: {$storage}");
        }
        $this->assertStringNotContainsString('$persist', $html, 'no Alpine persist plugin');
        $text = strtolower((string) preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('does not remember earlier questions', $text, 'the vendor is told nothing is remembered');
        $this->assertStringContainsString('nothing you type is saved', $text);
    }

    public function test_answers_are_never_cacheable(): void
    {
        $vendor = $this->approvedVendor();

        $this->assertStringContainsString('no-store', (string) $this->ask($vendor, 'hello')->assertOk()->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->ask($vendor, str_repeat('a ', 700))->assertStatus(422)->headers->get('Cache-Control'), 'errors too');

        // The page itself carries no conversation (that exists only in the browser), and stays private/no-cache.
        $page = (string) $this->signedIn($vendor)->get(route('developers.assistant'))->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $page);
    }
}
