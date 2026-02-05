<?php
// scripts/simulate_quick_buy.php
// Usage: php scripts/simulate_quick_buy.php [vendor_id] [amount]

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Models\WalletLedger;
use App\Services\WalletService;

$vendorId = $argv[1] ?? null;
$amount = isset($argv[2]) ? (float)$argv[2] : 1.0;

if (! $vendorId) {
    $vendor = Vendor::first();
} else {
    $vendor = Vendor::find($vendorId);
}

if (! $vendor) {
    echo "No vendor found (id: {$vendorId}).\n";
    exit(2);
}

echo "Vendor: {$vendor->id} - {$vendor->name} ({$vendor->email})\n";
echo "Starting wallet_balance: " . number_format((float)$vendor->wallet_balance, 2) . "\n\n";

$topups = WalletTopup::where('vendor_id', $vendor->id)->orderBy('created_at')->get();
if ($topups->isEmpty()) {
    echo "No completed top-ups found for vendor.\n";
} else {
    echo "Top-ups:\n";
    foreach ($topups as $t) {
        $consumed = data_get($t->metadata, 'consumed', 0);
        echo " - [#{$t->id}] amount={$t->amount} status={$t->status} consumed={$consumed}\n";
    }
}

$ws = new WalletService();

echo "\nAttempting to debit {$amount} from top-ups...\n";
$ok = $ws->debitVendorFromTopups($vendor->id, $amount, ['type' => 'simulate_quick_buy']);

if (! $ok) {
    echo "Debit failed: insufficient top-up balance.\n";
    exit(3);
}

$vendor->refresh();
echo "\nAfter debit wallet_balance: " . number_format((float)$vendor->wallet_balance, 2) . "\n\n";

$topups = WalletTopup::where('vendor_id', $vendor->id)->orderBy('created_at')->get();
if (! $topups->isEmpty()) {
    echo "Top-ups after consumption:\n";
    foreach ($topups as $t) {
        $consumed = data_get($t->metadata, 'consumed', 0);
        echo " - [#{$t->id}] amount={$t->amount} status={$t->status} consumed={$consumed}\n";
    }
}

$ledger = WalletLedger::where('vendor_id', $vendor->id)->latest('id')->first();
if ($ledger) {
    echo "\nLatest ledger: id={$ledger->id} type={$ledger->type} amount={$ledger->amount} balance_after={$ledger->balance_after}\n";
    echo "metadata: " . json_encode($ledger->metadata) . "\n";
}

echo "\nDone.\n";
