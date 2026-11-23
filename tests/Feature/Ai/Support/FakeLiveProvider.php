<?php

namespace Tests\Feature\Ai\Support;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;

/**
 * Stands in for a "live" provider (isLive() = true) so the live-only rules - mandatory budgets, mandatory model, no
 * ambiguous retries - can be tested. It performs NO network I/O: it counts calls, returns a canned response by default and
 * can be scripted (a response, a throwable, or a closure(AiRequest) returning either) one entry per call.
 */
final class FakeLiveProvider implements AiProvider
{
    public int $calls = 0;

    /** @var list<AiRequest> */
    public array $requests = [];

    /** @var list<AiResponse|\Throwable|\Closure> */
    private array $script = [];

    public function name(): string
    {
        return 'fakelive';
    }

    public function isLive(): bool
    {
        return true;
    }

    public function assertConfigured(?string $model): void {}

    public function queue(AiResponse|\Throwable|\Closure ...$items): self
    {
        foreach ($items as $item) {
            $this->script[] = $item;
        }

        return $this;
    }

    /**
     * Mirrors the real adapter's last line of defence (M5.7.1): a "live" provider refuses to transmit anything without the
     * emergency stop being released and a valid, OPEN reservation in the durable ledger. Because every orchestrated test of a
     * live provider goes through this, each of them proves the reservation exists BEFORE the provider is reached.
     */
    public bool $enforceGuards = true;

    public function send(AiRequest $request): AiResponse
    {
        if ($this->enforceGuards) {
            if (! app(KillSwitch::class)->surfaceEnabled($request->surface)) {
                throw new AiProviderException(AiProviderException::BAD_REQUEST, 'AI is stopped; the request was not sent.', null, false);
            }

            try {
                app(BudgetGuard::class)->assertOpenReservation($request->reservationId, $request->surface);
            } catch (AiConfigurationException) {
                throw new AiProviderException(AiProviderException::BAD_REQUEST, 'No valid budget reservation; the request was not sent.', null, false);
            }
        }

        $this->calls++;
        $this->requests[] = $request;

        $next = array_shift($this->script);

        if ($next instanceof \Closure) {
            $next = $next($request);
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        if ($next instanceof AiResponse) {
            return $next;
        }

        return new AiResponse('live ok', 'fakelive', (string) $request->model, new AiUsage(100, 50));
    }
}
