<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiUsage;

/**
 * Estimated cost from CONFIGURED pricing only. No price list is shipped and none is ever guessed: with no entry
 * for the provider/model, or no reported token usage, the cost is unknown (null).
 *
 * Maths: a price in USD per 1,000,000 tokens equals that many micro-USD per token, so
 * cost_micro_usd = input_tokens * input_price + output_tokens * output_price (rounded). Integers, no floats stored.
 */
class PriceTable
{
    public function costMicroUsd(string $provider, string $model, AiUsage $usage): ?int
    {
        if (! $usage->isKnown()) {
            return null;
        }

        $entry = config("ai.pricing.{$provider}.{$model}");

        if (! is_array($entry)
            || ! is_numeric($entry['input_usd_per_million'] ?? null)
            || ! is_numeric($entry['output_usd_per_million'] ?? null)) {
            return null;
        }

        return (int) round(
            $usage->inputTokens * (float) $entry['input_usd_per_million']
            + $usage->outputTokens * (float) $entry['output_usd_per_million']
        );
    }

    /**
     * Upper-bound cost for `$tokens` tokens whose input/output split is UNKNOWN (usage not reported, or the call
     * failed ambiguously): every token is priced at the dearer of the two configured rates. Used ONLY to keep the
     * cost budget conservative; it is never shown as the request's cost. Null when no complete price is configured
     * (pricing is still never guessed).
     */
    public function conservativeCostMicroUsd(string $provider, string $model, int $tokens): ?int
    {
        $entry = config("ai.pricing.{$provider}.{$model}");

        if ($tokens < 1
            || ! is_array($entry)
            || ! is_numeric($entry['input_usd_per_million'] ?? null)
            || ! is_numeric($entry['output_usd_per_million'] ?? null)) {
            return null;
        }

        return (int) ceil($tokens * max((float) $entry['input_usd_per_million'], (float) $entry['output_usd_per_million']));
    }
}
