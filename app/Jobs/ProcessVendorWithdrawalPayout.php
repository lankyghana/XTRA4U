<?php

namespace App\Jobs;

use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\VendorWithdrawal;
use App\Services\GatewayManager;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessVendorWithdrawalPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $withdrawalId)
    {
    }

    public function middleware(): array
    {
        return [
            // Prevent concurrent processing for the same withdrawal ID.
            (new WithoutOverlapping('vendor-withdrawal:' . $this->withdrawalId))->expireAfter(300),
        ];
    }

    public function handle(GatewayManager $gatewayManager): void
    {
        $shouldCallGateway = false;
        $withdrawal = null;

        DB::transaction(function () use ($gatewayManager, &$shouldCallGateway, &$withdrawal) {
            $withdrawal = VendorWithdrawal::with('vendor')
                ->whereKey($this->withdrawalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== VendorWithdrawal::STATUS_PROCESSING) {
                return;
            }

            // If we already have a completed payout, do nothing (idempotent).
            if ($withdrawal->status === VendorWithdrawal::STATUS_APPROVED || $withdrawal->payout_status === 'completed') {
                return;
            }

            // Rate-limit retries (helps with job retries/backoff).
            if ($withdrawal->payout_attempted_at && $withdrawal->payout_attempted_at->gt(now()->subSeconds(60))) {
                return;
            }

            if (!$withdrawal->payout_gateway) {
                $gateway = $gatewayManager->resolvePayoutConfigForWithdrawal($withdrawal);
                $withdrawal->payout_gateway = $gateway?->gateway_name;
            }

            $withdrawal->payout_attempted_at = now();
            $withdrawal->save();

            $shouldCallGateway = true;
        });

        if (!$shouldCallGateway || !$withdrawal) {
            return;
        }

        $withdrawal = VendorWithdrawal::with('vendor')->findOrFail($this->withdrawalId);

        $result = $gatewayManager->payout($withdrawal);

        if (($result['success'] ?? false) === true) {
            $this->markApproved($withdrawal, $result);
            return;
        }

        // Some providers return an explicit pending/unknown state; do not fail/refund in that case.
        if (($result['pending'] ?? false) === true || ($result['success'] ?? null) === null) {
            DB::transaction(function () use ($withdrawal, $result) {
                $locked = VendorWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== VendorWithdrawal::STATUS_PROCESSING) {
                    return;
                }

                $updates = [];
                if (empty($locked->payout_reference) && !empty($result['reference'])) {
                    $updates['payout_reference'] = $result['reference'];
                }
                if (empty($locked->payout_transaction_id) && !empty($result['transaction_id'])) {
                    $updates['payout_transaction_id'] = $result['transaction_id'];
                }
                if (!empty($updates)) {
                    $locked->update($updates);
                }
            });

            Log::info('Vendor withdrawal payout pending', [
                'withdrawal_id' => $withdrawal->id,
                'vendor_id' => $withdrawal->vendor_id,
                'amount' => $withdrawal->amount,
                'gateway' => $withdrawal->payout_gateway,
                'message' => $result['message'] ?? null,
            ]);
            return;
        }

        $this->markFailedAndRefund($withdrawal, (string) ($result['message'] ?? 'Payout failed'));
    }

    private function markApproved(VendorWithdrawal $withdrawal, array $result): void
    {
        $didUpdate = false;

        DB::transaction(function () use (&$didUpdate, $withdrawal, $result) {
            $locked = VendorWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VendorWithdrawal::STATUS_PROCESSING) {
                return;
            }

            $locked->update([
                'status' => VendorWithdrawal::STATUS_APPROVED,
                'payout_reference' => $result['reference'] ?? $locked->payout_reference,
                'payout_transaction_id' => $result['transaction_id'] ?? $locked->payout_transaction_id,
                'payout_status' => 'completed',
                'paid_at' => now(),
                'error_message' => null,
            ]);

            $didUpdate = true;
        });

        if ($didUpdate) {
            $this->notifyVendorApproved($withdrawal);

            Log::info('Vendor withdrawal payout approved', [
                'withdrawal_id' => $withdrawal->id,
                'vendor_id' => $withdrawal->vendor_id,
                'amount' => $withdrawal->amount,
                'gateway' => $withdrawal->payout_gateway,
            ]);
        }
    }

    private function markFailedAndRefund(VendorWithdrawal $withdrawal, string $errorMessage): void
    {
        $didUpdate = false;

        DB::transaction(function () use (&$didUpdate, $withdrawal, $errorMessage) {
            $locked = VendorWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VendorWithdrawal::STATUS_PROCESSING) {
                return;
            }

            $locked->update([
                'status' => VendorWithdrawal::STATUS_FAILED,
                'payout_status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            // Refund wallet balance exactly once.
            if ($locked->refunded_at === null) {
                $vendor = Vendor::whereKey($locked->vendor_id)->lockForUpdate()->first();

                if ($vendor) {
                    $vendor->increment('wallet_balance', $locked->amount);
                }

                $locked->update([
                    'refunded_at' => now(),
                ]);
            }

            $didUpdate = true;
        });

        if ($didUpdate) {
            $this->notifyVendorFailed($withdrawal, $errorMessage);

            Log::warning('Vendor withdrawal payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'vendor_id' => $withdrawal->vendor_id,
                'amount' => $withdrawal->amount,
                'gateway' => $withdrawal->payout_gateway,
                'error' => $errorMessage,
            ]);
        }
    }

    private function notifyVendorApproved(VendorWithdrawal $withdrawal): void
    {
        VendorNotification::create([
            'vendor_id' => $withdrawal->vendor_id,
            'type' => 'withdrawal_approved',
            'title' => 'Withdrawal Approved',
            'message' => 'Your withdrawal of GHS ' . number_format($withdrawal->amount, 2) . ' has been paid to ' . $withdrawal->momo_number . '.',
            'data' => [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'momo_number' => $withdrawal->momo_number,
                'reference' => $withdrawal->payout_reference,
                'gateway' => $withdrawal->payout_gateway,
            ],
        ]);

        try {
            $smsService = app(SmsService::class);
            $vendor = $withdrawal->vendor;

            if ($vendor && $vendor->phone_number) {
                $message = 'XTRA4U: Withdrawal of GHS ' . number_format($withdrawal->amount, 2) . ' paid to ' . $withdrawal->momo_number . '. Ref: ' . ($withdrawal->payout_reference ?? $withdrawal->reference);
                $smsService->send($vendor->phone_number, $message);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send withdrawal approved SMS', ['error' => $e->getMessage()]);
        }
    }

    private function notifyVendorFailed(VendorWithdrawal $withdrawal, string $errorMessage): void
    {
        VendorNotification::create([
            'vendor_id' => $withdrawal->vendor_id,
            'type' => 'withdrawal_failed',
            'title' => 'Withdrawal Failed',
            'message' => 'Your withdrawal of GHS ' . number_format($withdrawal->amount, 2) . ' failed and has been refunded. Reason: ' . $errorMessage,
            'data' => [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'momo_number' => $withdrawal->momo_number,
                'reference' => $withdrawal->reference,
                'gateway' => $withdrawal->payout_gateway,
                'error' => $errorMessage,
            ],
        ]);

        try {
            $smsService = app(SmsService::class);
            $vendor = $withdrawal->vendor;

            if ($vendor && $vendor->phone_number) {
                $message = 'XTRA4U: Withdrawal of GHS ' . number_format($withdrawal->amount, 2) . ' failed and was refunded. Reason: ' . $errorMessage;
                $smsService->send($vendor->phone_number, $message);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send withdrawal failed SMS', ['error' => $e->getMessage()]);
        }
    }
}
