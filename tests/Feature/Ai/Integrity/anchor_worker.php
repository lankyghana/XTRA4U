<?php

/**
 * Child process for AnchorProtocolTest: hammers the file anchor from a separate PHP process.
 *
 *   php anchor_worker.php <directory> <key> <count> <prefix> <go-file> <ready-file>
 *
 * Prints one JSON line: the sequence numbers this process was given.
 */

use App\Services\Ai\Integrity\FileIntegrityAnchor;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__, 4);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $dir, $key, $count, $prefix, $go, $ready] = $argv;

$anchor = new FileIntegrityAnchor($dir, $key);

file_put_contents($ready, (string) getmypid());
$deadline = microtime(true) + 60;
while (! file_exists($go)) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(300);
}

$seqs = [];
for ($i = 0; $i < (int) $count; $i++) {
    $id = $prefix.'-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $seqs[] = $anchor->reserve($id, ['day' => '20260101', 'surface' => 'vendor', 'requests' => 1, 'tokens' => 10, 'cost' => 5, 'reserved_tokens' => 10, 'reserved_cost' => 5]);
    $anchor->close($id, 'settled', 3, 2);
}

echo json_encode(['pid' => getmypid(), 'seqs' => $seqs]), "\n";
