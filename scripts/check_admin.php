<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

$admin = Admin::where('email', 'admin@example.com')->first();
if (! $admin) {
    echo "missing\n";
    exit(0);
}

echo "found: {$admin->email}\n";
echo "hash: {$admin->password}\n";
echo (Hash::check('ChangeMe123!', $admin->password) ? "password ok\n" : "password mismatch\n");
