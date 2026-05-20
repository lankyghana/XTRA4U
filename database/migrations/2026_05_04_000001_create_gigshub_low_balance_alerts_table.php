<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gigshub_low_balance_alerts', function (Blueprint $table) {
            $table->id();
            $table->decimal('balance', 10, 2);
            $table->decimal('threshold', 10, 2);
            $table->string('currency')->default('GHS');
            $table->text('message')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();
            $table->index('acknowledged_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gigshub_low_balance_alerts');
    }
};
