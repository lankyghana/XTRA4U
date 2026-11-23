<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiDisabledException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * M5.7.1: the emergency stop is a database row. Clearing the cache, restarting the application or losing the database
 * cannot switch AI back on; it is OFF until an operator releases it on purpose.
 */
class DurableKillSwitchTest extends AiTestCase
{
    private function switch(): KillSwitch
    {
        return app(KillSwitch::class);
    }

    private function clearEveryCache(): void
    {
        Cache::flush();
        Artisan::call('cache:clear');
    }

    /** What a restart does to this process: every in-memory object and driver is rebuilt, nothing is carried over. */
    private function restart(): void
    {
        $this->app->forgetInstance(KillSwitch::class);
        $this->app->forgetInstance(BudgetGuard::class);
        Cache::forgetDriver();
    }

    // ------------------------------------------------------------------------------------ default state

    public function test_ai_is_off_until_an_operator_releases_it_as_shipped(): void
    {
        $this->prepareDurableAiState(releaseKillSwitch: false); // exactly what the migration leaves behind

        $this->assertSame(KillSwitch::ENGAGED, DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('value'));
        $this->assertTrue($this->switch()->engaged());
        $this->assertFalse($this->switch()->engineEnabled(), 'even with ai.enabled = true');

        foreach (AiSurface::cases() as $surface) {
            $this->assertFalse($this->switch()->surfaceEnabled($surface), $surface->value);
            try {
                $this->engine()->run($this->req(surface: $surface));
                $this->fail("{$surface->value} must be refused");
            } catch (AiDisabledException) {
            }
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->artisan('ai:kill-switch', ['action' => 'status'])->expectsOutputToContain('ENGAGED')->assertExitCode(0);
    }

    public function test_the_shipped_config_is_off_too(): void
    {
        $shipped = require config_path('ai.php');

        $this->assertFalse($shipped['enabled']);
        foreach (['customer', 'vendor', 'developer'] as $surface) {
            $this->assertFalse($shipped['surfaces'][$surface]['enabled']);
        }
    }

    public function test_releasing_is_explicit_and_engaging_blocks_every_surface_and_the_provider(): void
    {
        $this->assertFalse($this->switch()->engaged(), 'released by the test setup');
        $this->engine()->run($this->req());
        $this->assertSame(1, $this->mockProvider()->callCount());

        $this->artisan('ai:kill-switch', ['action' => 'engage', '--reason' => 'incident 42'])->assertExitCode(0);
        $this->assertSame('incident 42', DB::table('ai_runtime_flags')->where('name', 'kill_switch')->value('reason'));

        foreach (AiSurface::cases() as $surface) {
            try {
                $this->engine()->run($this->req(surface: $surface));
                $this->fail("{$surface->value} must be refused while engaged");
            } catch (AiDisabledException) {
            }
        }

        $this->assertSame(1, $this->mockProvider()->callCount(), 'no provider request was made while engaged');
        $this->assertSame(1, $this->usage()['global']['requests'], 'and no budget was reserved');

        $this->artisan('ai:kill-switch', ['action' => 'release'])->assertExitCode(0);
        $this->engine()->run($this->req());
        $this->assertSame(2, $this->mockProvider()->callCount());
    }

    // ------------------------------------------------------------------------------------ cache clearing

    public function test_clearing_the_cache_after_an_emergency_shutdown_does_not_reactivate_ai(): void
    {
        $this->artisan('ai:kill-switch', ['action' => 'engage'])->assertExitCode(0);
        $this->assertTrue($this->switch()->engaged());

        $this->clearEveryCache();

        $this->assertTrue($this->switch()->engaged(), 'still engaged');
        $this->assertFalse($this->switch()->engineEnabled());
        $this->assertNull(Cache::get('ai:kill-switch'), 'there is no cache entry whose loss could matter');

        $this->expectException(AiDisabledException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(0, $this->mockProvider()->callCount());
        }
    }

    public function test_clearing_the_cache_does_not_engage_or_release_anything_either(): void
    {
        $this->assertFalse($this->switch()->engaged());
        $this->clearEveryCache();
        $this->assertFalse($this->switch()->engaged(), 'a released switch stays as the operator left it');
    }

    // ------------------------------------------------------------------------------------ restart

    public function test_an_application_restart_does_not_reactivate_ai(): void
    {
        $this->switch()->engage('before restart');

        $this->restart();

        $fresh = app(KillSwitch::class);
        $this->assertTrue($fresh->engaged());
        $this->assertFalse($fresh->engineEnabled());
        $this->assertSame('before restart', $fresh->status()['reason']);
    }

    public function test_a_database_reconnect_keeps_the_state(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('An in-memory SQLite database does not survive a reconnect; the cross-process restart is proven in AiMultiProcessTest.');
        }

        $this->switch()->engage();
        DB::disconnect();
        $this->restart();

        $this->assertTrue(app(KillSwitch::class)->engaged());
    }

    // ------------------------------------------------------------------------------------ fail closed

    /** @return array<string,array{0:\Closure}> */
    public static function unreadableStates(): array
    {
        return [
            'row deleted' => [fn () => DB::table('ai_runtime_flags')->where('name', 'kill_switch')->delete()],
            'unknown value' => [fn () => DB::table('ai_runtime_flags')->where('name', 'kill_switch')->update(['value' => 'maybe'])],
            'upper-case value' => [fn () => DB::table('ai_runtime_flags')->where('name', 'kill_switch')->update(['value' => 'RELEASED'])],
            'empty value' => [fn () => DB::table('ai_runtime_flags')->where('name', 'kill_switch')->update(['value' => ''])],
            'table dropped' => [fn () => Schema::drop('ai_runtime_flags')],
        ];
    }

    #[DataProvider('unreadableStates')]
    public function test_any_state_that_is_not_exactly_released_counts_as_engaged(\Closure $damage): void
    {
        $this->assertFalse($this->switch()->engaged());

        $damage();

        $this->assertTrue($this->switch()->engaged());
        $this->assertFalse($this->switch()->engineEnabled());
        $this->expectException(AiDisabledException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(0, $this->mockProvider()->callCount());
        }
    }

    public function test_a_database_error_while_reading_the_state_fails_closed_and_is_reported_as_unreadable(): void
    {
        $disarm = $this->failDatabaseWhen('select * from ai_runtime_flags');

        $status = $this->switch()->status();
        $disarm();

        $this->assertTrue($status['engaged']);
        $this->assertFalse($status['readable']);
    }

    public function test_the_command_reports_failure_when_it_cannot_write_and_never_prints_the_cause(): void
    {
        Schema::drop('ai_runtime_flags');

        $this->artisan('ai:kill-switch', ['action' => 'engage'])
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(1);
        $this->artisan('ai:kill-switch', ['action' => 'release'])->assertExitCode(1);

        $this->artisan('ai:kill-switch', ['action' => 'status'])->expectsOutputToContain('ENGAGED')->assertExitCode(0);
    }

    public function test_status_changes_nothing(): void
    {
        $before = DB::table('ai_runtime_flags')->get()->toJson();

        $this->artisan('ai:kill-switch', ['action' => 'status'])->assertExitCode(0);
        $this->artisan('ai:kill-switch', ['action' => 'bogus'])->assertExitCode(2);

        $this->assertSame($before, DB::table('ai_runtime_flags')->get()->toJson());
    }

    // ------------------------------------------------------------------------------------ mid-flight

    public function test_a_shutdown_between_the_reservation_and_the_provider_call_stops_the_request_and_releases_the_reservation(): void
    {
        $engaged = false;
        DB::listen(function ($query) use (&$engaged) {
            if (! $engaged && str_contains(str_replace(['"', '`'], '', $query->sql), 'insert into ai_budget_reservations')) {
                $engaged = true;
                $this->switch()->engage('engaged mid-request');
            }
        });

        try {
            $this->engine()->run($this->req());
            $this->fail('the request must not reach the provider');
        } catch (AiDisabledException) {
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->assertSame(BudgetGuard::RELEASED, DB::table('ai_budget_reservations')->value('state'), 'nothing was sent, so nothing stays reserved');
        $this->assertSame(0, $this->usage()['global']['tokens']);
        $this->assertSame([], app(BudgetGuard::class)->integrityProblems());
    }

    public function test_a_shutdown_during_a_provider_call_stops_the_retry(): void
    {
        config(['ai.request.max_retries' => 1]);
        $live = $this->useLiveProvider();
        $this->liveBudgets();
        $live->queue(function () {
            $this->switch()->engage('engaged during the call');

            return AiProviderException::unavailable('refused', requestSent: false);
        });
        $live->queue(fn () => $this->fail('the retry must not be sent'));

        try {
            $this->engine()->run($this->req());
            $this->fail('the first failure surfaces');
        } catch (AiProviderException) {
        }

        $this->assertSame(1, $live->calls, 'no second provider request after the shutdown');
        $this->assertSame(1, DB::table('ai_budget_reservations')->count(), 'and no second reservation');
    }

    // ------------------------------------------------------------------------------------ no side effects

    public function test_engaging_and_releasing_changes_only_the_flag_row_and_triggers_nothing(): void
    {
        Event::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $tables = array_values(array_diff(array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()), ['ai_runtime_flags', 'cache', 'cache_locks']));
        $snapshot = fn () => array_map(fn ($t) => md5(DB::table($t)->get()->toJson()), array_combine($tables, $tables));
        $before = $snapshot();

        $this->artisan('ai:kill-switch', ['action' => 'engage'])->assertExitCode(0);
        $this->artisan('ai:kill-switch', ['action' => 'status'])->assertExitCode(0);
        $this->artisan('ai:kill-switch', ['action' => 'release'])->assertExitCode(0);

        $this->assertSame($before, $snapshot(), 'no other table was touched');
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Event::assertNothingDispatched();
        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_every_entry_point_to_ai_goes_through_the_kill_switch(): void
    {
        $files = [
            'Services/Ai/AiOrchestrator.php',
            'Services/Ai/CustomerAssistant/CustomerAssistant.php',
            'Services/Ai/VendorAssistant/VendorAssistant.php',
            'Services/Ai/Developer/DeveloperAssistant.php',
            'Services/Ai/Tools/ToolExecutor.php',
            'Services/Ai/Providers/OpenAiProvider.php',
            'Http/Middleware/EnsureCustomerAssistantEnabled.php',
        ];

        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/killSwitch->(surfaceEnabled|engaged|engineEnabled)\(/', (string) file_get_contents(app_path($file)), "{$file} must consult the durable kill switch");
        }

        // ...and the switch itself has no cache path left.
        $source = (string) file_get_contents(app_path('Services/Ai/Safety/KillSwitch.php'));
        $this->assertStringNotContainsString('Cache::', $source);
        $this->assertStringNotContainsString('Facades\\Cache', $source);
    }
}
