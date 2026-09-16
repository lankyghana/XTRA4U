<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports orders that would violate the payment single-use constraints:
 * a payment_reference used by more than one order, or one gateway transaction
 * claimed by more than one order.
 *
 * Read-only. It never edits, merges or deletes anything — resolving a
 * duplicate is a financial judgement about which order a real payment actually
 * belongs to, and that is a human's call.
 *
 * Run this BEFORE deploying: the migration that adds those constraints
 * (2026_09_14_000003) deliberately aborts rather than silently skipping when
 * this reports anything, so that a completed migration always means the
 * constraint exists.
 */
class AuditPaymentUniqueness extends Command
{
    protected $signature = 'payments:audit-uniqueness
                            {--details : List every affected order id, not just the duplicated values}';

    protected $description = 'Report orders sharing a payment reference or gateway transaction id (blocks the single-use constraints)';

    public function handle(): int
    {
        $groups = $this->duplicateGroups();

        if ($groups->isEmpty()) {
            $this->info('No duplicate payment references or gateway transaction ids. The single-use constraints can be applied.');

            return self::SUCCESS;
        }

        $this->error(sprintf('Found %d duplicated value(s) blocking the payment single-use constraints.', $groups->count()));
        $this->line('');
        $this->line($this->describe($groups, (bool) $this->option('details')));
        $this->line('');
        $this->warn('Resolve these rows (decide which order each real payment belongs to) and run this command again.');
        $this->warn('Nothing has been modified — this command is read-only.');

        return self::FAILURE;
    }

    /**
     * Every duplicated value across both constraints.
     *
     * @return Collection<int, object{kind: string, value: string, count: int, order_ids: string}>
     */
    public function duplicateGroups(): Collection
    {
        return $this->duplicatesFor(
            'payment_reference',
            ['payment_reference'],
            fn ($row) => (string) $row->payment_reference
        )->concat($this->duplicatesFor(
            'gateway_transaction_id',
            ['payment_gateway', 'gateway_transaction_id'],
            fn ($row) => sprintf('%s / %s', $row->payment_gateway ?? 'null', $row->gateway_transaction_id)
        ))->values();
    }

    public function describe(Collection $groups, bool $details = true): string
    {
        return $groups->map(function ($group) use ($details) {
            $line = sprintf('  [%s] %s — %d orders', $group->kind, $group->value, $group->count);

            return $details ? $line.': #'.$group->order_ids : $line;
        })->implode("\n");
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function duplicatesFor(string $kind, array $columns, callable $label): Collection
    {
        $notNull = $columns[count($columns) - 1];

        // The grouping key column must exist before this can be audited — the
        // gateway_transaction_id column arrives with the integrity migration.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('orders', $notNull)) {
            return collect();
        }

        return DB::table('orders')
            ->select($columns)
            ->selectRaw('COUNT(*) as aggregate_count')
            ->whereNotNull($notNull)
            ->where($notNull, '!=', '')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(function ($row) use ($kind, $columns, $label) {
                $ids = DB::table('orders')
                    ->where(function ($query) use ($columns, $row) {
                        foreach ($columns as $column) {
                            $query->where($column, $row->{$column});
                        }
                    })
                    ->orderBy('id')
                    ->pluck('id');

                return (object) [
                    'kind' => $kind,
                    'value' => $label($row),
                    'count' => (int) $row->aggregate_count,
                    'order_ids' => $ids->implode(', #'),
                ];
            });
    }
}
