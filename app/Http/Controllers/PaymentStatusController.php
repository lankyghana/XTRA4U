<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentStatusController extends Controller
{
    public function status(Request $request, string $reference, PaymentService $paymentService)
    {
        $result = $paymentService->checkPaymentStatus($reference);

        // If the gateway returns an error (e.g. transaction not found yet), we shouldn't
        // immediately fail the polling unless we're sure it's an actual failed payment.
        if (!isset($result['success']) || !$result['success']) {
            $msg = strtolower($result['message'] ?? '');
            $status = 'pending'; // Default to pending so UI keeps polling
            if (str_contains($msg, 'failed') || str_contains($msg, 'error') || str_contains($msg, 'cancelled')) {
                $status = 'failed';
            }
            
            return response()->json([
                'success' => false,
                'status' => $status,
                'message' => $result['message'] ?? 'Unable to retrieve payment status',
            ], 200);
        }

        $rawStatus = strtolower((string) (data_get($result, 'data.status') ?? 'pending'));
        // Map gateway-specific statuses to the canonical set: paid | pending | failed
        $status = match ($rawStatus) {
            'success', 'paid' => 'paid',
            'failed', 'error' => 'failed',
            'pending', 'processing', 'initiated', 'unknown' => 'pending',
            default => 'failed',
        };

        return response()->json([
            'success' => true,
            'status' => $status,
        ], 200);
    }
}
