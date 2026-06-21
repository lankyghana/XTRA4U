<?php
$walletService = app(\App\Services\WalletService::class);
$paymentService = app(\App\Services\PaymentService::class);

$topups = \App\Models\WalletTopup::where('status', 'initiated')->get();
foreach($topups as $topup) {
    try {
        $result = $paymentService->checkPaymentStatus($topup->reference);
        
        $rawStatus = $result['status'] ?? $result['data']['status'] ?? $result['data']['payment_status'] ?? $result['message'] ?? '';
        $low = strtolower(is_string($rawStatus) ? $rawStatus : '');
        
        if (strpos($low, 'complete') !== false || strpos($low, 'success') !== false) {
            $reportedAmount = (float) ($result['data']['amount'] ?? 0);
            $initiatedAmount = (float) $topup->amount;
            
            if ($initiatedAmount > 0 && round($reportedAmount, 2) >= round($initiatedAmount, 2)) {
                $credited = false;
                DB::transaction(function () use ($topup, $initiatedAmount, $result, $walletService, &$credited) {
                    $credited = $walletService->creditVendor($topup->vendor_id, $initiatedAmount, ['reference' => $topup->reference]);
                    if ($credited) {
                        $topup->update(['status' => 'completed', 'gateway_response' => $result]);
                        Cache::forget('wallet_topup:' . $topup->reference);
                        Cache::forget('vendor:' . $topup->vendor_id . ':topups_available');
                    }
                });
                echo 'Credited: ' . $topup->reference . ' amount: ' . $initiatedAmount . PHP_EOL;
            }
        }
    } catch (\Throwable $e) {
        echo 'Error on ' . $topup->reference . ': ' . $e->getMessage() . PHP_EOL;
    }
}
echo 'Done reconciling!' . PHP_EOL;
