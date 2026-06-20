<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ussd_logs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->json('request_data')->nullable();
            $table->text('response_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_logs');
    }
};
