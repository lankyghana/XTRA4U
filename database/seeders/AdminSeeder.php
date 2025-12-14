<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default admin user in users table
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@xtra4u.com'],
            [
                'name' => 'System Administrator',
                'password' => bcrypt('admin123'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        // Create default admin in admins table
        \App\Models\Admin::firstOrCreate(
            ['email' => 'admin@xtra4u.com'],
            [
                'name' => 'System Administrator',
                'password' => bcrypt('admin123'),
            ]
        );

        echo "Admin user created:\n";
        echo "Email: admin@xtra4u.com\n";
        echo "Password: admin123\n";
    }
}
