<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentStatusController extends Controller
{
    public function status(Request $request, string $reference, PaymentService $paymentService)
    {
        $result = $paymentService->checkPaymentStatus($reference);

        // Normalize and sanitize the status response so the frontend cannot
        // obtain raw gateway data or sensitive fields.
        if (!isset($result['success']) || !$result['success']) {
            return response()->json([
                'success' => false,
                'status' => 'pending',
                'message' => $result['message'] ?? 'Unable to retrieve payment status',
            ], 200);
        }

        $rawStatus = strtolower((string) (data_get($result, 'data.status') ?? 'pending'));
        // Map gateway-specific statuses to the canonical set: paid | pending | failed
        $status = match ($rawStatus) {
            'success', 'paid' => 'paid',
            'failed', 'error' => 'failed',
            default => 'pending',
        };

        return response()->json([
            'success' => true,
            'status' => $status,
        ], 200);
    }
}
