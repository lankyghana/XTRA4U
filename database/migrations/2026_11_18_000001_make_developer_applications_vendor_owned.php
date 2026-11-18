<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Developer API access is now driven by the existing XTRA4U vendor account, not by a separate
 * developer membership. An application is owned by exactly one vendor (developer_applications.vendor_id).
 *
 * Safe by construction:
 *  - Only ONE structural change: developer_applications.developer_id becomes NULLABLE, so new
 *    vendor-owned applications no longer need a (retired) developers row. No column or table is dropped.
 *  - The `developers` table, the production_* and vendor_link_* columns are left in place, unused.
 *  - Existing applications were owned by external developers (not vendors) and were authorised under
 *    the old rules (developer account + vendor consent + production review). They must NOT silently
 *    inherit a vendor's authority, so every one of them is SUSPENDED. Rows, keys, webhooks and the audit
 *    trail are all preserved; a suspended application cannot authenticate and receives no webhooks.
 *    (Nothing has been deployed to production yet, so on production this touches zero rows.)
 *
 * SQLite caveat (the local/dev database): SQLite cannot alter a column in place, so `->change()` REBUILDS
 * the table (create, copy, drop, rename). Dropping the parent table while foreign keys are enforced runs
 * the children's ON DELETE CASCADE and silently deletes every API key, webhook and request log. So the
 * rebuild is only ever done with enforcement paused, which SQLite honours only OUTSIDE a transaction
 * ($withinTransaction = false). If rows exist and a transaction is open, it refuses to run rather than
 * lose data. MySQL/MariaDB alter the column in place and are not affected. Every step is idempotent, so a
 * run that stops half-way is simply run again.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->setDeveloperIdNullable(true);
        $this->suspendLegacyApplications();
    }

    public function down(): void
    {
        // Only restore NOT NULL when it cannot lose or invent data.
        if (DB::table('developer_applications')->whereNull('developer_id')->exists()) {
            return;
        }

        $this->setDeveloperIdNullable(false);
    }

    /** Public so the data step can be exercised on its own, without the schema rebuild. */
    public function suspendLegacyApplications(): void
    {
        $legacy = DB::table('developer_applications')
            ->whereNotNull('developer_id')
            ->where('status', '!=', 'suspended')
            ->where('status', '!=', 'revoked')
            ->get(['id', 'status', 'vendor_id']);

        foreach ($legacy as $row) {
            DB::table('developer_applications')->where('id', $row->id)->update([
                'status' => 'suspended',
                'suspended_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('developer_audit_events')->insert([
                'actor' => 'system',
                'application_id' => $row->id,
                'action' => 'application.suspended_legacy_developer_model',
                'meta' => json_encode(['previous_status' => $row->status, 'vendor_id' => $row->vendor_id]),
                'created_at' => now(),
            ]);
        }
    }

    private function setDeveloperIdNullable(bool $nullable): void
    {
        $column = collect(Schema::getColumns('developer_applications'))->firstWhere('name', 'developer_id');

        // Already in the wanted state: nothing to do, and on SQLite no table rebuild at all.
        if ($column !== null && (bool) $column['nullable'] === $nullable) {
            return;
        }

        $alter = function () use ($nullable): void {
            Schema::table('developer_applications', function (Blueprint $table) use ($nullable) {
                $table->unsignedBigInteger('developer_id')->nullable($nullable)->change();
            });
        };

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $alter();   // in-place ALTER: foreign keys and child rows are untouched

            return;
        }

        if (DB::transactionLevel() > 0 && DB::table('developer_applications')->exists()) {
            throw new RuntimeException(
                'Refusing to rebuild developer_applications inside a transaction while it has rows: SQLite would '
                .'cascade-delete its API keys, webhooks and logs. Run this migration outside a transaction.'
            );
        }

        $enforced = (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0);
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $alter();
        } finally {
            DB::statement('PRAGMA foreign_keys = '.($enforced ? 'ON' : 'OFF'));
        }

        // Prove nothing was orphaned by the rebuild.
        $orphans = DB::select('PRAGMA foreign_key_check');
        if ($orphans !== []) {
            throw new RuntimeException('Foreign key check failed after rebuilding developer_applications.');
        }
    }
};
