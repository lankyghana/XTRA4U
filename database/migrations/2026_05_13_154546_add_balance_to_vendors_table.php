<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'balance')) {
                $table->decimal('balance', 12, 2)->default(0)->after('is_approved');
            }
        });
    }

    public function down()
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'balance')) {
                $table->dropColumn('balance');
            }
        });
    }
};
