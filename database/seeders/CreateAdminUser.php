<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Seeder
{
    /**
     * Development-only seeder with a well-known password. In production use
     * `php artisan xtra4u:ensure-admin {email} --password=` instead.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('CreateAdminUser uses a default password and must not run in production. Use: php artisan xtra4u:ensure-admin {email} --password=');

            return;
        }

        Admin::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => Hash::make('ChangeMe123!')]
        );
    }
}
