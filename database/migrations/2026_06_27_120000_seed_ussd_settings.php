<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['key' => 'ussd_enabled',            'value' => '1',              'group' => 'ussd'],
            ['key' => 'ussd_service_code',        'value' => '',               'group' => 'ussd'],
            ['key' => 'ussd_welcome_message',     'value' => 'Welcome to XTRA4U', 'group' => 'ussd'],
            ['key' => 'ussd_support_number',      'value' => '0531192005',     'group' => 'ussd'],
            ['key' => 'ussd_default_vendor_id',   'value' => '',               'group' => 'ussd'],
        ];

        foreach ($defaults as $row) {
            DB::table('settings')->insertOrIgnore($row + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'ussd')->delete();
    }
};
