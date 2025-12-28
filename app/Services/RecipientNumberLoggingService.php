<?php

namespace App\Services;

use App\Models\AfaRegistration;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecipientNumberLoggingService
{
    public function enabled(): bool
    {
        return (bool) config('audit.recipient_numbers.enabled', true);
    }

    /**
     * Normalizes phone numbers for storage. Best-effort (does not reject unknown formats).
     */
    public function normalizePhoneNumber(?string $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // Keep leading +, strip other non-digits
        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // Ghana normalization (best-effort)
        // 0XXXXXXXXX (10 digits) -> +233XXXXXXXXX
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '+233' . substr($digits, 1);
        }

        // 233XXXXXXXXX (12 digits) -> +233XXXXXXXXX
        if (strlen($digits) === 12 && str_starts_with($digits, '233')) {
            return '+'.$digits;
        }

        return $hasPlus ? ('+' . $digits) : $digits;
    }

    public function logFromOrder(Order $order): void
    {
        $phone = $this->normalizePhoneNumber($order->recipient_phone_number);
        if (!$phone) {
            return;
        }

        $usedAt = $order->payment_completed_at instanceof Carbon
            ? $order->payment_completed_at
            : now();

        $serviceType = (string) ($order->service_purchased ?: 'order');

        $vendorIds = collect([
            $order->vendor_id,
            $order->reseller_vendor_id,
            $order->owner_vendor_id,
        ])->filter(fn ($id) => !is_null($id))->unique()->values();

        $rows = [];
        foreach ($vendorIds as $vendorId) {
            $rows[] = [
                'phone_number' => $phone,
                'vendor_id' => (int) $vendorId,
                'order_id' => (int) $order->id,
                'service_type' => $serviceType,
                'used_at' => $usedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            $rows[] = [
                'phone_number' => $phone,
                'vendor_id' => null,
                'order_id' => (int) $order->id,
                'service_type' => $serviceType,
                'used_at' => $usedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('recipient_number_logs')->insert($rows);
    }

    public function logFromAfaRegistration(AfaRegistration $registration): void
    {
        $phone = $this->normalizePhoneNumber($registration->phone_number);
        if (!$phone) {
            return;
        }

        $usedAt = $registration->payment_completed_at instanceof Carbon
            ? $registration->payment_completed_at
            : now();

        $vendorIds = collect([
            $registration->vendor_id,
            $registration->reseller_vendor_id,
        ])->filter(fn ($id) => !is_null($id))->unique()->values();

        $rows = [];
        foreach ($vendorIds as $vendorId) {
            $rows[] = [
                'phone_number' => $phone,
                'vendor_id' => (int) $vendorId,
                'order_id' => null,
                'service_type' => 'afa_registration',
                'used_at' => $usedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            $rows[] = [
                'phone_number' => $phone,
                'vendor_id' => null,
                'order_id' => null,
                'service_type' => 'afa_registration',
                'used_at' => $usedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('recipient_number_logs')->insert($rows);
    }

    public function shouldEnqueue(): bool
    {
        // Never run synchronously in request flows.
        return config('queue.default') !== 'sync';
    }

    public function warnIfSyncQueue(): void
    {
        if (!$this->shouldEnqueue()) {
            Log::warning('Recipient number logging skipped because queue driver is sync');
        }
    }
}
