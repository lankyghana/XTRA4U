<?php

/**
 * Child process for the opt-in MySQL concurrency tests (UtilityBillMySqlConcurrencyTest).
 * Boots the application against the SAME database/cache as the test process, so row locks,
 * cache locks and budgets are genuinely shared between separate PHP processes.
 *
 *   php race_worker.php <mode> '<json args>'
 *
 * Prints one JSON line with its result. Provider/gateway HTTP is always faked here.
 */

use App\Models\Order;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Support\PaymentFailureTransition;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$base = dirname(__DIR__, 4);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'queue.default' => 'sync',
    'services.kingflexy_utilities.api_key' => 'kf_cs_live_testkey123',
    'services.kingflexy_utilities.base_url' => 'https://api.kingflexygh.com/api/v2',
]);

$mode = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];
$out = ['mode' => $mode, 'pid' => getmypid()];

$payBody = ['success' => true, 'data' => ['reference' => 'UTIL-RACE-1', 'order_id' => 'uuid-1', 'status' => 'pending',
    'biller' => 'dstv', 'account' => '7041234567', 'amount' => 100.0, 'commission_share_percent' => 40]];

switch ($mode) {
    // The success webhook: holds the order row lock (as completeOrder does) for hold_ms, then settles.
    case 'complete':
        Http::fake(['*/utilities/pay' => Http::response($payBody)]);
        DB::transaction(function () use ($args, &$out) {
            $order = Order::query()->whereKey($args['order_id'])->lockForUpdate()->firstOrFail();
            file_put_contents($args['flag'], 'locked');
            usleep((int) $args['hold_ms'] * 1000);

            $verification = ['success' => true, 'data' => ['status' => 'success', 'amount' => 100.0, 'currency' => 'GHS', 'reference' => $order->payment_reference, 'id' => 4242]];
            $integrity = app(PaymentIntegrityGuard::class)->guard($order, $verification, 'paystack');
            $order->amount_paid = $integrity->confirmedAmount;
            $out['completed'] = app(PaymentService::class)->completeOrder($order);
            $out['committed_at'] = microtime(true);
        });
        break;

        // A browser verify / callback that keeps receiving "failed" from the gateway.
    case 'fail':
        $until = microtime(true) + ((int) $args['run_ms']) / 1000;
        $out['outcomes'] = [];
        while (microtime(true) < $until) {
            $out['outcomes'][] = [microtime(true), PaymentFailureTransition::apply((int) $args['order_id'])];
            usleep(random_int(2000, 15000));
        }
        break;

    default:
        $out['error'] = 'unknown mode';
}

echo json_encode($out).PHP_EOL;
