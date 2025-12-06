<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('wallet_balance', 12, 2)->default(0)->after('updated_at');
        });
    }

    public function down()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};
