<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_services', function (Blueprint $table) {
            // Add checker type support
            $table->boolean('is_checker_type')->default(false)->after('category')->index();
            $table->decimal('base_price', 10, 2)->nullable()->after('is_checker_type');
            $table->string('checker_code')->nullable()->after('base_price')->unique();
            $table->text('checker_description')->nullable()->after('checker_code');
        });
    }

    public function down(): void
    {
        Schema::table('network_services', function (Blueprint $table) {
            $table->dropIndex(['is_checker_type']);
            $table->dropIndex(['checker_code']);
            $table->dropColumn(['is_checker_type', 'base_price', 'checker_code', 'checker_description']);
        });
    }
};
