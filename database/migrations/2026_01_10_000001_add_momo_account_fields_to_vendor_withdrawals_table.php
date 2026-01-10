<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_withdrawals', 'momo_account_name')) {
                $table->string('momo_account_name', 120)->nullable()->after('momo_network');
            }
            if (!Schema::hasColumn('vendor_withdrawals', 'momo_account_type')) {
                $table->string('momo_account_type', 50)->nullable()->after('momo_account_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_withdrawals', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('vendor_withdrawals', 'momo_account_type')) {
                $columns[] = 'momo_account_type';
            }
            if (Schema::hasColumn('vendor_withdrawals', 'momo_account_name')) {
                $columns[] = 'momo_account_name';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
