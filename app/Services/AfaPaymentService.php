<?php

namespace App\Services;

use App\Events\AfaRegistrationCompleted;
use App\Models\AfaRegistration;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\Transaction;
use App\Mail\AfaOrderPlacedMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles AFA registration payment completion and transaction creation.
 * Keeps AFA business logic separate from product order logic.
 */
class AfaPaymentService
{
    public function __construct(
        private ?SmsService $smsService = null
    ) {
        $this->smsService = $smsService ?? (new GatewayManager())->getSmsService();
    }

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

            if (!$lockedRegistration) {
                return false;
            }

            // Idempotency check (under lock)
            if ($lockedRegistration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
                return true;
            }

            $amount = (float) $lockedRegistration->amount;
            $platformCommission = (float) $lockedRegistration->platform_commission;
            $vendorEarning = (float) $lockedRegistration->vendor_earning;
            $resellerEarning = (float) $lockedRegistration->reseller_earning;

            // Create polymorphic transaction for source vendor
            $this->createAfaTransaction(
                $lockedRegistration,
                $lockedRegistration->vendor_id,
                $lockedRegistration->is_reseller_order ? $lockedRegistration->vendor_price : $amount,
                $lockedRegistration->is_reseller_order ? round($lockedRegistration->vendor_price * 0.02, 2) : $platformCommission,
                $vendorEarning
            );

            // Create transaction for reseller if applicable
            if ($lockedRegistration->is_reseller_order && $lockedRegistration->reseller_vendor_id) {
                $this->createAfaTransaction(
                    $lockedRegistration,
                    $lockedRegistration->reseller_vendor_id,
                    $resellerEarning + round($resellerEarning * 0.02, 2), // markup + commission
                    round($resellerEarning * 0.02, 2),
                    $resellerEarning
                );
            }

            // Update registration status
            $lockedRegistration->update([
                'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
                'payment_completed_at' => now(),
                'status' => AfaRegistration::STATUS_PROCESSING,
            ]);

            // Update vendor wallets
            $this->updateVendorWallets($lockedRegistration);

            // Create in-app notification records (DB work)
            $this->createNotificationRecords($lockedRegistration);

            // External side effects (mail/SMS) must happen after commit
            $postCommit[] = ['type' => 'communications', 'registration_id' => $lockedRegistration->id];

            Log::info('AFA registration payment completed', [
                'registration_id' => $lockedRegistration->id,
                'vendor_id' => $lockedRegistration->vendor_id,
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
            Cache::lock('afa_registration_comm:' . $registrationId, 60)->get(function () use ($registrationId) {
                $registration = AfaRegistration::find($registrationId);
                if (!$registration) {
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
                'message' => "New AFA registration for {$registration->full_name}. Your earning: GHS " . number_format($registration->vendor_earning, 2),
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
                    'message' => "You sold an AFA registration! Customer: {$registration->full_name}. Your markup earning: GHS " . number_format($registration->reseller_earning, 2),
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
     * Create a transaction record for an AFA registration
     */
    private function createAfaTransaction(
        AfaRegistration $registration,
        int $vendorId,
        float $amount,
        float $commission,
        float $earning
    ): void {
        Transaction::create([
            'transactionable_type' => 'App\\Models\\AfaRegistration',
            'transactionable_id' => $registration->id,
            'vendor_id' => $vendorId,
            'payment_type' => 'afa_registration',
            'recipient_phone' => $registration->phone_number,
            'amount' => $amount,
            'commission_amount' => $commission,
            'vendor_earning' => $earning,
            'payment_status' => 'successful',
            'timestamp' => now(),
        ]);
    }

    /**
     * Update vendor wallet balances
     */
    private function updateVendorWallets(AfaRegistration $registration): void
    {
        // Update source vendor wallet
        $sourceVendor = Vendor::find($registration->vendor_id);
        if ($sourceVendor && $registration->vendor_earning > 0) {
            $sourceVendor->increment('wallet_balance', $registration->vendor_earning);
        }

        // Update reseller vendor wallet if applicable
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

            // Send SMS
            $this->sendVendorSms($sourceVendor, $registration, $registration->vendor_earning);
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

                // Send SMS
                $this->sendVendorSms($resellerVendor, $registration, $registration->reseller_earning, true);
            }
        }
    }

    /**
     * Send SMS notification to vendor
     */
    private function sendVendorSms(Vendor $vendor, AfaRegistration $registration, float $earning, bool $isReseller = false): void
    {
        if (!$this->smsService || !$vendor->phone_number) {
            return;
        }

        try {
            $message = $isReseller
                ? "XTRA4U: You sold AFA registration! Customer: {$registration->full_name}. Earning: GHS " . number_format($earning, 2)
                : "XTRA4U: New AFA registration for {$registration->full_name}. Earning: GHS " . number_format($earning, 2);

            $this->smsService->send($vendor->phone_number, $message);
        } catch (\Exception $e) {
            Log::warning('Failed to send AFA SMS to vendor', ['error' => $e->getMessage()]);
        }
    }
}
