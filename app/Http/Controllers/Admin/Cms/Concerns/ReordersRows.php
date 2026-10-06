<?php

namespace App\Http\Controllers\Admin\Cms\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait ReordersRows
{
    /**
     * Move one row up or down inside an already-ordered group and renumber the
     * whole group, so sort_order stays gap-free and unambiguous.
     *
     * @param  Collection<int, Model>  $ordered  the group, in current display order
     */
    protected function reorderWithin(Collection $ordered, Model $moved, string $direction): void
    {
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $rows = $ordered->values();
        $index = $rows->search(fn ($row) => $row->is($moved));
        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($rows[$target])) {
            return;
        }

        $swap = $rows[$index];
        $rows[$index] = $rows[$target];
        $rows[$target] = $swap;

        foreach ($rows->values() as $position => $row) {
            if ((int) $row->sort_order !== $position) {
                $row->sort_order = $position;
                $row->save();
            }
        }
    }
}
