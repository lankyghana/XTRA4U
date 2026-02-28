<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'external_fulfillment_provider_used')) {
                $table->string('external_fulfillment_provider_used')->nullable()->after('external_fulfillment_remote_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'external_fulfillment_provider_used')) {
                $table->dropColumn('external_fulfillment_provider_used');
            }
        });
    }
};
