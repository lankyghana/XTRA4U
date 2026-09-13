<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Support\PaymentVerificationState;
use Illuminate\Http\Request;

class PaymentStatusController extends Controller
{
    public function status(Request $request, string $reference, PaymentService $paymentService)
    {
        // Verify against the gateway that actually created this payment —
        // never whichever gateway is currently the admin default — so
        // switching the default gateway can never affect a reference already
        // in flight under a different one. If no local payable matches this
        // reference at all, fall back to the default-gateway lookup (rare:
        // e.g. a stale/garbled reference) — that call is read-only either
        // way, so it cannot corrupt state.
        $gatewayName = $paymentService->resolvePayableGateway($reference);

        $result = $gatewayName
            ? $paymentService->checkPaymentStatusForGateway($reference, $gatewayName)
            : $paymentService->checkPaymentStatus($reference);

        // Previously this endpoint inferred financial state by searching the
        // verification error MESSAGE for the words "failed"/"error"/
        // "cancelled" — which meant a plain network timeout (whose message is
        // literally "Error verifying payment.") was reported to the polling
        // UI as a failed payment. PaymentVerificationState never looks at
        // message text: only an explicit, authoritative terminal status from
        // the gateway can produce 'failed'. Anything else — including the
        // verify call itself throwing — stays 'pending'.
        $state = PaymentVerificationState::from($result);

        $status = match ($state) {
            PaymentVerificationState::SUCCESS => 'paid',
            PaymentVerificationState::FAILED => 'failed',
            default => 'pending', // PENDING or UNKNOWN
        };

        return response()->json([
            'success' => $state === PaymentVerificationState::SUCCESS,
            'status' => $status,
            'message' => $result['message'] ?? null,
        ], 200);
    }
}
