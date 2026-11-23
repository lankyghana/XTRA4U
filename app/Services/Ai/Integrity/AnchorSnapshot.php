<?php

namespace App\Services\Ai\Integrity;

/**
 * One authenticated read of the independent anchor. Immutable; numbers, ids and states only (no prompt, response or identity).
 *
 * `reservations` is the anchor's own record of every budget reservation it ever accepted:
 *   id => [seq, day, surface, state, requests, tokens, cost, reserved_tokens, reserved_cost, retry_of]
 */
final class AnchorSnapshot
{
    /**
     * @param  array<string,array<string,mixed>>  $reservations
     * @param  array{state:string,generation:int,reason:?string,at:?string}  $kill
     * @param  array{status:string,reason:?string,at:?string}  $reconciliation
     */
    public function __construct(
        public readonly string $anchorId,
        public readonly int $seq,
        public readonly int $epoch,
        public readonly array $kill,
        public readonly array $reconciliation,
        public readonly array $reservations,
    ) {}

    public function reservation(string $id): ?array
    {
        return $this->reservations[$id] ?? null;
    }

    public function reconciliationOk(): bool
    {
        return ($this->reconciliation['status'] ?? null) === 'ok';
    }

    /**
     * What the anchor says is currently charged for a day, per scope (`global`, `surface.<name>`), as the sum of its records.
     *
     * @return array<string,array{requests:int,tokens:int,cost_micro_usd:int}>
     */
    public function totals(string $day): array
    {
        $out = ['global' => ['requests' => 0, 'tokens' => 0, 'cost_micro_usd' => 0]];

        foreach ($this->reservations as $r) {
            if ($r['day'] !== $day) {
                continue;
            }

            $scope = 'surface.'.$r['surface'];
            $out[$scope] ??= ['requests' => 0, 'tokens' => 0, 'cost_micro_usd' => 0];

            foreach (['requests' => 'requests', 'tokens' => 'tokens', 'cost_micro_usd' => 'cost'] as $metric => $key) {
                $out[$scope][$metric] += (int) $r[$key];
                $out['global'][$metric] += (int) $r[$key];
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> the records of one day, each with its `id` */
    public function recordsOfDay(string $day): array
    {
        $rows = [];
        foreach ($this->reservations as $id => $r) {
            if ($r['day'] === $day) {
                $rows[] = ['id' => (string) $id] + $r;
            }
        }

        return $rows;
    }
}
