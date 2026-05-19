<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestVendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendor = Vendor::firstOrCreate(
            ['email' => 'ducaquqi@fxzig.com'],
            [
                'name' => 'Test Vendor',
                'phone_number' => '+233501234567',
                'password' => Hash::make('password123'),
                'is_approved' => true,
                'balance' => 1000.00,
                'wallet_balance' => 500.00,
                'vendor_code' => 'TEST' . strtoupper(Str::random(6)),
            ]
        );

        echo "\n✅ Test vendor account created/exists!\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Vendor ID: {$vendor->id}\n";
        echo "Name: {$vendor->name}\n";
        echo "Email: {$vendor->email}\n";
        echo "Phone: {$vendor->phone_number}\n";
        echo "Status: " . ($vendor->is_approved ? '✓ Approved' : '✗ Pending') . "\n";
        echo "Balance: {$vendor->balance}\n";
        echo "Wallet Balance: {$vendor->wallet_balance}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Login Credentials:\n";
        echo "  Email: {$vendor->email}\n";
        echo "  Password: password123\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    }
}
