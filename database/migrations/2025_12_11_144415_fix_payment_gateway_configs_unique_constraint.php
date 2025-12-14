<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table) {
            // Drop the single column unique constraint
            $table->dropUnique(['gateway_name']);
            
            // Add composite unique constraint for gateway_name + gateway_type
            $table->unique(['gateway_name', 'gateway_type'], 'unique_gateway_name_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('unique_gateway_name_type');
            
            // Add back the single column unique constraint
            $table->unique('gateway_name');
        });
    }
};
