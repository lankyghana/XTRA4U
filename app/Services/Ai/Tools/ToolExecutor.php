<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Exceptions\ToolInputRejected;
use App\Services\Ai\Tools\Exceptions\ToolWriteBlockedException;

/**
 * Runs ONE model-requested tool call, safely, or says why not. It is the only door from model output to application
 * code, and it never throws for anything the model caused: every outcome is a ToolResult.
 *
 * Every call, every time, in this order (nothing after a failed step runs):
 *    0. turn limits        calls per turn / call-id reuse / tool-time budget
 *    0b. kill switch       the M5.2 engine + surface switch (config AND runtime flag)
 *    1. call shape         well-formed call id and tool name
 *    2. tool exists        in the registry
 *    3. surface permits    declared by the tool AND on the surface allowlist (re-checked now, not at listing time)
 *    4. arguments          size/count, prohibited identity names, unexpected names, types, Laravel rules
 *    5. context valid      internally consistent AND the vendor is still approved in the database
 *    6. authorization      the tool's own authorize(), fail-closed on error
 *    7. execute            under the read-only DB guard, timed
 *    8. normalise          sanitise, redact, size-cap; failures become generic codes
 *
 * There is no retry: a failed call is reported once, and the executor never runs a tool twice for one call.
 */
class ToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolContextVerifier $verifier,
        private readonly ReadOnlyGuard $guard,
        private readonly KillSwitch $killSwitch,
        private readonly ToolLogger $logger,
    ) {}

    /** A fresh, trusted per-turn counter. Create one per assistant turn. */
    public function beginTurn(): ToolTurn
    {
        return new ToolTurn;
    }

    /**
     * Execute the calls of one assistant turn in order; calls past the per-turn limit are rejected, not run.
     *
     * @param  list<AiToolCall>  $calls
     * @return list<ToolResult> one result per call, same order
     */
    public function executeTurn(array $calls, ToolContext $context, ?ToolTurn $turn = null): array
    {
        $turn ??= $this->beginTurn();

        return array_map(fn (AiToolCall $call) => $this->execute($call, $context, $turn), array_values($calls));
    }

    public function execute(AiToolCall $call, ToolContext $context, ToolTurn $turn): ToolResult
    {
        $started = microtime(true);
        $id = preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $call->id) === 1 ? $call->id : 'invalid';
        $wellFormedName = preg_match(ToolRegistry::NAME_PATTERN, $call->name) === 1;
        $name = $wellFormedName ? $call->name : 'unknown';
        $argCount = count($call->arguments);

        $reject = function (string $code, string $reason, array $problems = []) use ($id, $name, $context, $argCount, $started): ToolResult {
            $this->logger->record($name, $context->surface->value, $context->principalKey(), 'rejected', $code, $reason, $argCount, 0, $this->ms($started));

            return ToolResult::rejected($id, $name, $code, $problems);
        };

        try {
            // 0. turn limits
            $limits = ToolLimits::fromConfig();
            $ordinal = $turn->admit();

            if ($ordinal > $limits->maxCallsPerTurn) {
                return $reject('too_many_tool_calls', 'call_limit');
            }

            if ($turn->toolSeconds() >= $limits->maxTurnSeconds) {
                return $reject('turn_time_exceeded', 'time_budget');
            }

            // 0b. the M5.2 switches still apply to tools
            if (! $this->killSwitch->surfaceEnabled($context->surface)) {
                return $reject('tools_disabled', 'kill_switch');
            }

            // 1. shape
            if ($id === 'invalid' || ! $wellFormedName) {
                return $reject('invalid_call', 'malformed_call');
            }

            if (! $turn->claimId($id)) {
                return $reject('duplicate_call', 'duplicate_call_id');
            }

            // 2 + 3. exists, and available on THIS context's surface right now
            $tool = $this->registry->resolve($name, $context->surface);
            if ($tool === null) {
                return $reject('unknown_tool', $this->registry->has($name) ? 'not_on_surface' : 'not_registered');
            }

            // 4. arguments
            try {
                $arguments = $tool->schema()->validate($call->arguments, $limits);
            } catch (ToolInputRejected $e) {
                return $reject($e->reason, 'arguments', $e->problems);
            }

            // 5. context
            if (! $this->verifier->verify($context)) {
                return $reject('invalid_context', 'context_not_valid');
            }

            // 6. authorization (every execution; any error denies)
            if (! $this->authorized($tool, $context)) {
                return $reject('not_authorized', 'tool_authorize');
            }
        } catch (\Throwable $e) {
            // Configuration or registry trouble: fail closed, tell the model nothing about it.
            $this->logger->record($name, $context->surface->value, $context->principalKey(), 'rejected', 'invalid_call', 'engine_error', $argCount, 0, $this->ms($started), $e::class);

            return ToolResult::rejected($id, $name, 'invalid_call');
        }

        return $this->run($tool, $arguments, $context, $turn, $limits, $id, $name, $argCount, $started);
    }

    private function run(AiTool $tool, ToolArguments $arguments, ToolContext $context, ToolTurn $turn, ToolLimits $limits, string $id, string $name, int $argCount, float $started): ToolResult
    {
        $fail = function (string $code, string $reason, ?\Throwable $e = null) use ($id, $name, $context, $argCount, $started): ToolResult {
            $this->logger->record($name, $context->surface->value, $context->principalKey(), 'failed', $code, $reason, $argCount, 0, $this->ms($started), $e ? $e::class : null);

            return ToolResult::failed($id, $name, $code);
        };

        $toolStart = microtime(true);

        try {
            $data = $this->guard->run(fn () => $tool->handle($arguments, $context));
        } catch (\Throwable $e) {
            $turn->addSeconds(microtime(true) - $toolStart);

            // Only the CLASS is logged and nothing about the exception reaches the model.
            return $this->blockedWrite($e)
                ? $fail('read_only_violation', 'write_attempt', $e)
                : $fail('tool_error', 'exception', $e);
        }

        $elapsed = microtime(true) - $toolStart;
        $turn->addSeconds($elapsed);

        // PHP cannot interrupt a running tool: the limit is enforced on the outcome (the result is discarded).
        if ($elapsed > $limits->maxExecutionSeconds) {
            return $fail('tool_timeout', 'execution_time');
        }

        if (! is_array($data)) {
            return $fail('invalid_result', 'not_an_array');
        }

        try {
            [$clean, $truncated, $bytes] = (new ResultSanitizer($limits))->sanitize($data);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage() === 'result_too_large' ? 'result_too_large' : 'invalid_result', 'result_'.$e->getMessage());
        } catch (\Throwable $e) {
            return $fail('invalid_result', 'sanitizer_error', $e);
        }

        $this->logger->record($name, $context->surface->value, $context->principalKey(), 'ok', null, null, $argCount, $bytes, $this->ms($started));

        return ToolResult::ok($id, $name, $clean, $truncated, $bytes);
    }

    private function authorized(AiTool $tool, ToolContext $context): bool
    {
        try {
            return $tool->authorize($context) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function blockedWrite(\Throwable $e): bool
    {
        for ($i = 0; $e !== null && $i < 5; $i++, $e = $e->getPrevious()) {
            if ($e instanceof ToolWriteBlockedException) {
                return true;
            }
        }

        return false;
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
