<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vendor_result_checker_settings', 'checker_type_id')) {
            return;
        }

        Schema::table('vendor_result_checker_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('checker_type_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
