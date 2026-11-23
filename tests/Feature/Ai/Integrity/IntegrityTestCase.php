<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\AiTestCase;
use Tests\Feature\Ai\Support\FakeLiveProvider;

/**
 * A live-flagged (but network-free) provider, a throw-away independent anchor bound to the test database, and the helpers every
 * M5.7.2 test shares. The kill switch starts RELEASED; nothing here can reach the network.
 */
abstract class IntegrityTestCase extends AiTestCase
{
    protected FakeLiveProvider $live;

    protected function setUp(): void
    {
        parent::setUp();

        $this->live = $this->useLiveProvider();
        $this->liveBudgets(); // creates the anchor and releases the switch
    }

    protected function today(): string
    {
        return app(UsageMeter::class)->today();
    }

    /** One orchestrated provider turn against the live-flagged fake. @throws \App\Services\Ai\Exceptions\AiException */
    protected function turn(string $text = 'hello there'): void
    {
        $this->engine()->run($this->req($text));
    }

    /** The turn must be refused with the generic fail-closed error, and the provider must not be reached. */
    protected function assertRefused(string $why = ''): AiConfigurationException
    {
        $calls = $this->live->calls;

        try {
            $this->turn();
        } catch (AiConfigurationException $e) {
            $this->assertSame($calls, $this->live->calls, 'the provider was reached although the request had to be refused '.$why);

            return $e;
        } catch (\Throwable $e) {
            // The switch itself may already read "engaged", which stops the request even earlier, as an ordinary "disabled".
            if ($e instanceof \App\Services\Ai\Exceptions\AiDisabledException) {
                $this->assertSame($calls, $this->live->calls, 'the provider was reached although AI was stopped '.$why);

                return new AiConfigurationException('disabled');
            }

            throw $e;
        }

        $this->fail('the request should have been refused '.$why);
    }

    protected function ledgerRows(): int
    {
        return DB::table(UsageMeter::LEDGER_TABLE)->count();
    }

    protected function counter(string $scope, string $metric, ?string $day = null): int
    {
        return (int) DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $day ?? $this->today())->where('scope', $scope)->value($metric);
    }

    protected function assertLatched(): void
    {
        $this->assertTrue(app(KillSwitch::class)->engaged(), 'the kill switch must be engaged');
        $this->assertSame('required', $this->anchor()->read()->reconciliation['status'], 'the anchor must require a reconciliation');
    }

    protected function guard(): IntegrityGuard
    {
        return app(IntegrityGuard::class);
    }

    protected function assertIntegrityFailure(\Throwable $e, bool $mismatch): void
    {
        $this->assertInstanceOf(AiIntegrityException::class, $e);
        $this->assertSame($mismatch, $e->mismatch);
    }
}
