<?php

namespace App\Services;

use App\Models\AfaRegistration;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\Transaction;
use App\Mail\AfaOrderPlacedMail;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($registration) {
            $amount = (float) $registration->amount;
            $platformCommission = (float) $registration->platform_commission;
            $vendorEarning = (float) $registration->vendor_earning;
            $resellerEarning = (float) $registration->reseller_earning;

            // Create polymorphic transaction for source vendor
            $this->createAfaTransaction(
                $registration,
                $registration->vendor_id,
                $registration->is_reseller_order ? $registration->vendor_price : $amount,
                $registration->is_reseller_order ? round($registration->vendor_price * 0.02, 2) : $platformCommission,
                $vendorEarning
            );

            // Create transaction for reseller if applicable
            if ($registration->is_reseller_order && $registration->reseller_vendor_id) {
                $this->createAfaTransaction(
                    $registration,
                    $registration->reseller_vendor_id,
                    $resellerEarning + round($resellerEarning * 0.02, 2), // markup + commission
                    round($resellerEarning * 0.02, 2),
                    $resellerEarning
                );
            }

            // Update registration status
            $registration->update([
                'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
                'payment_completed_at' => now(),
                'status' => AfaRegistration::STATUS_PROCESSING,
            ]);

            // Update vendor wallets
            $this->updateVendorWallets($registration);

            // Send notifications
            $this->sendNotifications($registration);

            Log::info('AFA registration payment completed', [
                'registration_id' => $registration->id,
                'vendor_id' => $registration->vendor_id,
                'reseller_vendor_id' => $registration->reseller_vendor_id,
                'amount' => $amount,
                'vendor_earning' => $vendorEarning,
                'reseller_earning' => $resellerEarning,
            ]);

            return true;
        });
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
        // Notify source vendor
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
