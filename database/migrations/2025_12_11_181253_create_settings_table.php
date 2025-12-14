<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general'); // general, email, sms, etc.
            $table->timestamps();
        });

        // Insert default email settings
        DB::table('settings')->insert([
            ['key' => 'mail_mailer', 'value' => 'smtp', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_host', 'value' => '', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_port', 'value' => '587', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_username', 'value' => '', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_password', 'value' => '', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_encryption', 'value' => 'tls', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_from_address', 'value' => '', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mail_from_name', 'value' => 'XTRA4U', 'group' => 'email', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
