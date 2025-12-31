<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRememberTokenToVendorsTable extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'remember_token')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->rememberToken();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendors', 'remember_token')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->dropColumn('remember_token');
            });
        }
    }
}
