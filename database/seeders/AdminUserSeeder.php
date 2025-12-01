<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'lankyitechghana@gmail.com'],
            [
                'name' => 'Lanky Itech Admin',
                'password' => Hash::make('lankyitechghana@gmail.com@1'),
                'role' => 'admin',
            ]
        );
    }
}
