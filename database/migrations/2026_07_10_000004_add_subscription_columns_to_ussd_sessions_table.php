<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ussd_sessions')) {
            return;
        }

        Schema::table('ussd_sessions', function (Blueprint $table) {
            // vendor_id currently lives only inside the `data` JSON blob, which
            // cannot be indexed or joined. Session metering needs both.
            if (! Schema::hasColumn('ussd_sessions', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('session_id');
            }
            if (! Schema::hasColumn('ussd_sessions', 'ussd_subscription_id')) {
                $table->unsignedBigInteger('ussd_subscription_id')->nullable()->after('vendor_id');
            }

            // Terminal state. Today endSession() deletes the row, so a replayed
            // sessionId is indistinguishable from a brand new dial. Keeping the
            // row in an 'ended' state is what lets Phase 5 reject replays.
            if (! Schema::hasColumn('ussd_sessions', 'status')) {
                $table->string('status', 20)->default('active')->after('current_step');
            }
            if (! Schema::hasColumn('ussd_sessions', 'request_count')) {
                $table->unsignedSmallInteger('request_count')->default(0)->after('status');
            }
            if (! Schema::hasColumn('ussd_sessions', 'retry_count')) {
                $table->unsignedSmallInteger('retry_count')->default(0)->after('request_count');
            }
            if (! Schema::hasColumn('ussd_sessions', 'ended_at')) {
                $table->timestamp('ended_at')->nullable();
            }
        });

        Schema::table('ussd_sessions', function (Blueprint $table) {
            $table->index('vendor_id', 'idx_ussd_sessions_vendor');
            $table->index(['status', 'updated_at'], 'idx_ussd_sessions_status_activity');
        });

        // SQLite cannot attach foreign keys to an existing table, and the test
        // suite runs on SQLite. Add them only where the engine supports it.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('ussd_sessions', function (Blueprint $table) {
                $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
                $table->foreign('ussd_subscription_id')->references('id')->on('ussd_subscriptions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ussd_sessions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            Schema::table('ussd_sessions', function (Blueprint $table) {
                $table->dropForeign(['vendor_id']);
                $table->dropForeign(['ussd_subscription_id']);
            });
        }

        Schema::table('ussd_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_ussd_sessions_vendor');
            $table->dropIndex('idx_ussd_sessions_status_activity');
            $table->dropColumn([
                'vendor_id',
                'ussd_subscription_id',
                'status',
                'request_count',
                'retry_count',
                'ended_at',
            ]);
        });
    }
};
