<?php

namespace App\Services;

use App\Events\AfaRegistrationCompleted;
use App\Mail\AfaOrderPlacedMail;
use App\Models\AfaRegistration;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles AFA registration payment completion and transaction creation.
 * Keeps AFA business logic separate from product order logic.
 */
class AfaPaymentService
{
    /**
     * Complete AFA registration after successful payment
     */
    public function completeRegistration(AfaRegistration $registration): bool
    {
        // Idempotency check
        if ($registration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
            return true;
        }

        $postCommit = [];

        $result = DB::transaction(function () use ($registration, &$postCommit) {
            $lockedRegistration = AfaRegistration::whereKey($registration->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRegistration) {
                return false;
            }

            // Idempotency check (under lock)
            if ($lockedRegistration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
                return true;
            }

            $amount = (float) $lockedRegistration->amount;

            // Multi-level AFA support (additive): if a chain snapshot is present, compute payout
            // from that snapshot so completion is deterministic even if vendor settings change.
            $payoutLines = null;
            $ownerVendorId = (int) $lockedRegistration->vendor_id;
            $baseAmount = (float) ($lockedRegistration->vendor_price ?: 0);
            $platformCommission = (float) $lockedRegistration->platform_commission;
            $vendorEarning = (float) $lockedRegistration->vendor_earning;
            $resellerEarning = (float) $lockedRegistration->reseller_earning;

            if ($lockedRegistration->is_reseller_order && is_array($lockedRegistration->affiliate_chain_snapshot) && ! empty($lockedRegistration->affiliate_chain_snapshot)) {
                $computed = $this->computePayoutFromSnapshot($lockedRegistration);
                if ($computed['ok'] ?? false) {
                    $payoutLines = $computed['lines'];
                    $ownerVendorId = (int) $computed['owner_vendor_id'];
                    $baseAmount = (float) $computed['base_amount'];
                    $platformCommission = (float) $computed['platform_commission'];
                    $vendorEarning = (float) $computed['owner_earning'];
                    $resellerEarning = (float) $computed['immediate_seller_earning'];
                }
            }

            if (is_array($payoutLines)) {
                foreach ($payoutLines as $line) {
                    $vendorId = (int) ($line['vendor_id'] ?? 0);
                    if ($vendorId <= 0) {
                        continue;
                    }

                    $this->upsertAfaTransaction(
                        $lockedRegistration,
                        $vendorId,
                        (float) ($line['amount'] ?? 0),
                        (float) ($line['commission'] ?? 0),
                        (float) ($line['earning'] ?? 0)
                    );
                }
            } else {
                // Legacy 1-level behavior
                $this->upsertAfaTransaction(
                    $lockedRegistration,
                    (int) $lockedRegistration->vendor_id,
                    $lockedRegistration->is_reseller_order ? (float) $lockedRegistration->vendor_price : $amount,
                    $lockedRegistration->is_reseller_order ? round((float) $lockedRegistration->vendor_price * 0.02, 2) : $platformCommission,
                    $vendorEarning
                );

                if ($lockedRegistration->is_reseller_order && $lockedRegistration->reseller_vendor_id) {
                    $markupAmount = $resellerEarning + round($resellerEarning * 0.02, 2); // markup (gross)
                    $this->upsertAfaTransaction(
                        $lockedRegistration,
                        (int) $lockedRegistration->reseller_vendor_id,
                        $markupAmount,
                        round($resellerEarning * 0.02, 2),
                        $resellerEarning
                    );
                }
            }

            // Update registration status
            $lockedRegistration->update([
                'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
                'payment_completed_at' => now(),
                'status' => AfaRegistration::STATUS_PROCESSING,
                // Keep stored summary fields consistent for reporting.
                'vendor_id' => $ownerVendorId,
                'vendor_price' => $lockedRegistration->is_reseller_order ? $baseAmount : $amount,
                'platform_commission' => $platformCommission,
                'vendor_earning' => $vendorEarning,
                'reseller_earning' => $lockedRegistration->is_reseller_order ? $resellerEarning : 0,
            ]);

            // Update vendor wallets
            $this->updateVendorWallets($lockedRegistration);

            // Create in-app notification records (DB work)
            $this->createNotificationRecords($lockedRegistration);

            // External side effects (mail/SMS) must happen after commit
            $postCommit[] = ['type' => 'communications', 'registration_id' => $lockedRegistration->id];

            Log::info('AFA registration payment completed', [
                'registration_id' => $lockedRegistration->id,
                'vendor_id' => $ownerVendorId,
                'reseller_vendor_id' => $lockedRegistration->reseller_vendor_id,
                'amount' => $amount,
                'vendor_earning' => $vendorEarning,
                'reseller_earning' => $resellerEarning,
            ]);

            return true;
        });

        if ($result) {
            $this->dispatchPostCommitActions($postCommit);
        }

        return $result;
    }

    private function dispatchPostCommitActions(array $postCommit): void
    {
        foreach ($postCommit as $action) {
            if (($action['type'] ?? null) === 'communications' && isset($action['registration_id'])) {
                try {
                    event(new AfaRegistrationCompleted((int) $action['registration_id']));
                } catch (\Throwable $e) {
                    // Never fail AFA completion due to audit logging.
                }
                $this->sendCommunicationsAfterCommit((int) $action['registration_id']);
            }
        }
    }

    private function sendCommunicationsAfterCommit(int $registrationId): void
    {
        try {
            Cache::lock('afa_registration_comm:'.$registrationId, 60)->get(function () use ($registrationId) {
                $registration = AfaRegistration::find($registrationId);
                if (! $registration) {
                    return;
                }

                if ($registration->payment_status !== AfaRegistration::PAYMENT_COMPLETED) {
                    return;
                }

                $this->sendNotifications($registration);
            });
        } catch (\Exception $e) {
            Log::warning('Failed to send AFA communications post-commit', [
                'registration_id' => $registrationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create vendor notification records (DB-only). Email/SMS is handled post-commit.
     */
    private function createNotificationRecords(AfaRegistration $registration): void
    {
        $sourceVendor = Vendor::find($registration->vendor_id);
        if ($sourceVendor) {
            VendorNotification::create([
                'vendor_id' => $sourceVendor->id,
                'type' => VendorNotification::TYPE_AFA_REGISTRATION,
                'title' => $registration->is_reseller_order ? 'New AFA Registration (Reseller Sale)' : 'New AFA Registration',
                'message' => "New AFA registration for {$registration->full_name}. Your earning: GHS ".number_format($registration->vendor_earning, 2),
                'data' => [
                    'registration_id' => $registration->id,
                    'customer_name' => $registration->full_name,
                    'amount' => $registration->amount,
                    'earning' => $registration->vendor_earning,
                    'is_reseller_order' => $registration->is_reseller_order,
                ],
            ]);
        }

        if ($registration->is_reseller_order && $registration->reseller_vendor_id) {
            $resellerVendor = Vendor::find($registration->reseller_vendor_id);
            if ($resellerVendor) {
                VendorNotification::create([
                    'vendor_id' => $resellerVendor->id,
                    'type' => VendorNotification::TYPE_AFA_REGISTRATION,
                    'title' => 'New AFA Registration Sale',
                    'message' => "You sold an AFA registration! Customer: {$registration->full_name}. Your markup earning: GHS ".number_format($registration->reseller_earning, 2),
                    'data' => [
                        'registration_id' => $registration->id,
                        'customer_name' => $registration->full_name,
                        'amount' => $registration->amount,
                        'earning' => $registration->reseller_earning,
                    ],
                ]);
            }
        }
    }

    /**
     * Compute payout lines deterministically from an immutable snapshot.
     * Snapshot format: [{vendor_id, role: owner, amount}, {vendor_id, role: reseller, markup}, ...]
     */
    private function computePayoutFromSnapshot(AfaRegistration $registration): array
    {
        $snapshot = $registration->affiliate_chain_snapshot;
        if (! is_array($snapshot) || empty($snapshot)) {
            return ['ok' => false, 'reason' => 'missing_snapshot'];
        }

        $ownerEntry = $snapshot[0] ?? null;
        if (! is_array($ownerEntry) || ($ownerEntry['role'] ?? null) !== 'owner') {
            return ['ok' => false, 'reason' => 'invalid_snapshot_owner'];
        }

        $ownerVendorId = (int) ($ownerEntry['vendor_id'] ?? 0);
        $baseAmount = (float) ($ownerEntry['amount'] ?? 0);
        if ($ownerVendorId <= 0 || $baseAmount <= 0) {
            return ['ok' => false, 'reason' => 'invalid_snapshot_base'];
        }

        $lines = [];
        $platformCommission = 0.0;

        $ownerCommission = round($baseAmount * 0.02, 2);
        $ownerEarning = round($baseAmount - $ownerCommission, 2);

        $lines[] = [
            'vendor_id' => $ownerVendorId,
            'role' => 'owner',
            'amount' => $baseAmount,
            'commission' => $ownerCommission,
            'earning' => $ownerEarning,
        ];
        $platformCommission = round($platformCommission + $ownerCommission, 2);

        $immediateSellerEarning = 0.0;
        $immediateSellerVendorId = (int) ($registration->reseller_vendor_id ?? 0);

        // Remaining entries are reseller markups
        foreach (array_slice($snapshot, 1) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $vendorId = (int) ($entry['vendor_id'] ?? 0);
            $markup = (float) ($entry['markup'] ?? ($entry['amount'] ?? 0));

            if ($vendorId <= 0 || $markup <= 0) {
                continue;
            }

            $commission = round($markup * 0.02, 2);
            $earning = round($markup - $commission, 2);

            $lines[] = [
                'vendor_id' => $vendorId,
                'role' => 'reseller',
                'amount' => $markup,
                'commission' => $commission,
                'earning' => $earning,
            ];

            $platformCommission = round($platformCommission + $commission, 2);

            if ($immediateSellerVendorId > 0 && $vendorId === $immediateSellerVendorId) {
                $immediateSellerEarning = $earning;
            }
        }

        return [
            'ok' => true,
            'owner_vendor_id' => $ownerVendorId,
            'immediate_seller_vendor_id' => $immediateSellerVendorId,
            'base_amount' => $baseAmount,
            'platform_commission' => $platformCommission,
            'owner_earning' => $ownerEarning,
            'immediate_seller_earning' => $immediateSellerEarning,
            'lines' => $lines,
        ];
    }

    /**
     * Create a transaction record for an AFA registration
     */
    private function createAfaTransaction(
        AfaRegistration $registration,
        int $vendorId,
        float $amount,
        float $commission,
        float $earning
    ): void {
        // Backward-compatible wrapper.
        $this->upsertAfaTransaction($registration, $vendorId, $amount, $commission, $earning);
    }

    /**
     * Upsert transaction record for AFA registration to avoid duplicate rows on retries.
     */
    private function upsertAfaTransaction(
        AfaRegistration $registration,
        int $vendorId,
        float $amount,
        float $commission,
        float $earning
    ): void {
        $transaction = Transaction::where('transactionable_type', 'App\\Models\\AfaRegistration')
            ->where('transactionable_id', $registration->id)
            ->where('vendor_id', $vendorId)
            ->latest('id')
            ->first();

        $attributes = [
            'payment_type' => 'afa_registration',
            'recipient_phone' => $registration->phone_number,
            'amount' => $amount,
            'commission_amount' => $commission,
            'vendor_earning' => $earning,
            'payment_status' => 'successful',
            'timestamp' => now(),
        ];

        if ($transaction) {
            if (in_array($transaction->payment_status, ['completed', 'successful'], true)) {
                return;
            }
            $transaction->update($attributes);

            return;
        }

        Transaction::create(array_merge([
            'transactionable_type' => 'App\\Models\\AfaRegistration',
            'transactionable_id' => $registration->id,
            'vendor_id' => $vendorId,
        ], $attributes));
    }

    /**
     * Update vendor wallet balances
     */
    private function updateVendorWallets(AfaRegistration $registration): void
    {
        // Multi-level path: if snapshot exists, credit all vendors in the chain.
        if ($registration->is_reseller_order && is_array($registration->affiliate_chain_snapshot) && ! empty($registration->affiliate_chain_snapshot)) {
            $computed = $this->computePayoutFromSnapshot($registration);
            if ($computed['ok'] ?? false) {
                foreach (($computed['lines'] ?? []) as $line) {
                    $vendorId = (int) ($line['vendor_id'] ?? 0);
                    $earning = (float) ($line['earning'] ?? 0);
                    if ($vendorId > 0 && $earning > 0) {
                        Vendor::whereKey($vendorId)->increment('wallet_balance', $earning);
                    }
                }

                return;
            }
        }

        // Legacy 1-level behavior
        $sourceVendor = Vendor::find($registration->vendor_id);
        if ($sourceVendor && $registration->vendor_earning > 0) {
            $sourceVendor->increment('wallet_balance', $registration->vendor_earning);
        }

        if ($registration->is_reseller_order && $registration->reseller_vendor_id && $registration->reseller_earning > 0) {
            $resellerVendor = Vendor::find($registration->reseller_vendor_id);
            if ($resellerVendor) {
                $resellerVendor->increment('wallet_balance', $registration->reseller_earning);
            }
        }
    }

    /**
     * Send notifications to vendors
     */
    private function sendNotifications(AfaRegistration $registration): void
    {
        // Notify source vendor (email/SMS only; DB notification records created in-transaction)
        $sourceVendor = Vendor::find($registration->vendor_id);
        if ($sourceVendor) {
            // Send email
            if ($sourceVendor->email) {
                try {
                    $vendorRole = $registration->is_reseller_order ? 'owner' : 'direct';
                    Mail::to($sourceVendor->email)->send(new AfaOrderPlacedMail(
                        $registration,
                        $sourceVendor,
                        $vendorRole,
                        $registration->vendor_earning
                    ));
                } catch (\Exception $e) {
                    Log::warning('Failed to send AFA email to source vendor', ['error' => $e->getMessage()]);
                }
            }
        }

        // Notify reseller vendor if applicable
        if ($registration->is_reseller_order && $registration->reseller_vendor_id) {
            $resellerVendor = Vendor::find($registration->reseller_vendor_id);
            if ($resellerVendor) {
                // Send email
                if ($resellerVendor->email) {
                    try {
                        Mail::to($resellerVendor->email)->send(new AfaOrderPlacedMail(
                            $registration,
                            $resellerVendor,
                            'reseller',
                            $registration->reseller_earning
                        ));
                    } catch (\Exception $e) {
                        Log::warning('Failed to send AFA email to reseller vendor', ['error' => $e->getMessage()]);
                    }
                }
            }
        }
    }
}
