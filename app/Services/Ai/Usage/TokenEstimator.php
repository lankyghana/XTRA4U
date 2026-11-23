<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiRequest;

/**
 * A deliberately PESSIMISTIC token UPPER BOUND for one provider attempt, used to reserve budget BEFORE the call.
 *
 *   input  <= bytes(every message) + tool_schema_multiplier x bytes(tool definitions)
 *             + per_message_overhead x messages + fixed_overhead
 *   output <= max_output_tokens          (enforced IN the provider request: the adapter sends it as the provider's hard cap)
 *
 * Why the input bound is safe: the tokenizers of the supported provider family are byte-level BPE, so every token
 * stands for AT LEAST ONE byte of the text it was made from - a text of N bytes can never become more than N tokens.
 * Role/format framing the provider adds around messages is covered by the per-message and fixed overheads, and tool
 * definitions (which a provider re-renders into its own format) get a x2 margin on their JSON size. Request size is
 * strictly capped (`ai.request.max_request_bytes`), so the bound is small in absolute terms.
 *
 * What this is NOT: an exact token count (no tokenizer ships with the application) and not a mathematical proof about
 * a provider's hidden framing. The residual assumption is documented, and it is monitored: if a provider ever REPORTS
 * more usage than was reserved, BudgetGuard records the overage, raises the budget-integrity flag and blocks live
 * execution for the rest of the day (docs/ai/06 section 6).
 */
class TokenEstimator
{
    /** Upper bound on input tokens for everything that would be sent. */
    public function inputUpperBound(AiRequest $resolved): int
    {
        $cfg = (array) config('ai.budget', []);
        $fixed = max(0, (int) ($cfg['fixed_overhead_tokens'] ?? 512));
        $perMessage = max(0, (int) ($cfg['per_message_overhead_tokens'] ?? 16));
        $multiplier = max(1, (int) ($cfg['tool_schema_multiplier'] ?? 2));

        $messageBytes = 0;
        foreach ($resolved->messages as $m) {
            $messageBytes += strlen($m->content);
        }

        $toolBytes = $resolved->tools === [] ? 0 : strlen((string) json_encode($resolved->tools));

        return $messageBytes + $multiplier * $toolBytes + $perMessage * count($resolved->messages) + $fixed;
    }

    /** Upper bound on output tokens (the cap that is sent to the provider). */
    public function outputUpperBound(AiRequest $resolved): int
    {
        return (int) $resolved->maxOutputTokens;
    }

    /** Input + output upper bound for ONE attempt: what a reservation holds. */
    public function estimate(AiRequest $resolved): int
    {
        return $this->inputUpperBound($resolved) + $this->outputUpperBound($resolved);
    }
}
