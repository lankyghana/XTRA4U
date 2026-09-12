<?php

namespace App\Http\Controllers;

use App\Models\ResultCheckerOrder;
use App\Services\GatewayManager;
use App\Services\ResultCheckerService;
use App\Support\PaymentVerificationState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ResultCheckerPaymentCallbackController extends Controller
{
    public function __construct(
        protected GatewayManager $gatewayManager,
        protected ResultCheckerService $resultCheckerService,
    ) {}

    /**
     * Gateway redirect callback (browser navigation) — and, when called via
     * fetch() with an Accept: application/json header, the same verify+
     * complete logic used by the Payaza popup's confirmation step (see
     * InlinePaymentManager.openPayaza()'s `verify_url` mode). No new payment
     * logic here: this is the identical GatewayManager::verifyCollectionWithGateway()
     * + ResultCheckerService::handlePaymentCallback() pipeline the redirect
     * flow and the webhook already use — only the response shape differs.
     */
    public function handle(Request $request, ResultCheckerOrder $order)
    {
        $wantsJson = $request->wantsJson() || $request->ajax();

        $reference = $this->resolveReference($request, $order);
        if (! $reference) {
            return response()->json([
                'success' => false,
                'message' => 'Missing payment reference',
            ], 400);
        }

        // Idempotency fast-path: already settled, nothing left to verify.
        if ($order->status === 'completed' || $order->paid_at) {
            $redirect = route('result-checkers.success', $order);

            if ($wantsJson) {
                return response()->json([
                    'success' => true,
                    'status' => 'success',
                    'message' => 'Payment already completed.',
                    'redirect' => $redirect,
                ]);
            }

            return redirect($redirect);
        }

        // Verify against the gateway that actually created this order — never
        // whichever gateway is currently the admin default.
        $verification = $this->gatewayManager->verifyCollectionWithGateway($order->payment_gateway, $reference);

        $state = PaymentVerificationState::from($verification);

        if ($state === PaymentVerificationState::FAILED) {
            $order->update(['status' => 'failed']);

            if ($wantsJson) {
                return response()->json([
                    'success' => true,
                    'status' => 'failed',
                    'message' => 'Payment was not successful',
                ]);
            }

            return redirect()->route('result-checkers.status.show', $order)
                ->with('payment_failed', true)
                ->with('payment_message', 'Payment was not successful');
        }

        if ($state !== PaymentVerificationState::SUCCESS) {
            // PENDING (gateway says still processing) or UNKNOWN (the verify
            // call itself failed — network/timeout/malformed response).
            // Neither is proof the customer wasn't charged: JSON callers (the
            // Payaza popup's polling) keep waiting, and the order itself is
            // left untouched rather than flashed as failed.
            if ($wantsJson) {
                return response()->json([
                    'success' => true,
                    'status' => 'pending',
                    'message' => $verification['message'] ?? 'Payment pending confirmation.',
                ]);
            }

            return redirect()->route('result-checkers.status.show', $order);
        }

        // Amount mismatch guard. Most gateways lock the amount in server-side
        // at initiation, so this is a no-op for them; it matters for Payaza,
        // whose Web Checkout SDK sets the charge amount client-side.
        $verifiedAmount = data_get($verification, 'data.amount');
        $expectedAmount = (float) $order->total_price;
        if ($verifiedAmount !== null && $expectedAmount > 0 && round((float) $verifiedAmount, 2) < round($expectedAmount, 2)) {
            Log::error('Result checker callback: verified amount is less than expected order amount - refusing to fulfil', [
                'order_id' => $order->id,
                'reference' => $reference,
                'expected_amount' => $expectedAmount,
                'verified_amount' => $verifiedAmount,
            ]);

            if ($wantsJson) {
                return response()->json([
                    'success' => true,
                    'status' => 'failed',
                    'message' => 'Payment amount mismatch. Please contact support.',
                ]);
            }

            return redirect()->route('result-checkers.status.show', $order)
                ->with('payment_failed', true)
                ->with('payment_message', 'Payment amount mismatch. Please contact support.');
        }

        $this->resultCheckerService->handlePaymentCallback(
            $order,
            $reference,
            $order->payment_gateway
        );

        $order->refresh();
        $redirect = $order->status === 'pending_stock'
            ? route('result-checkers.pending-stock', $order)
            : route('result-checkers.success', $order);

        if ($wantsJson) {
            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Payment completed.',
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect);
    }

    public function webhook(Request $request)
    {
        $reference = $this->resolveReference($request);
        if (! $reference) {
            return response()->json([
                'success' => false,
                'message' => 'Missing payment reference',
            ], 400);
        }

        $order = ResultCheckerOrder::query()
            ->where('payment_reference', $reference)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Result checker order not found',
            ], 404);
        }

        // Verify against the gateway that actually created this order — never
        // whichever gateway is currently the admin default.
        $verification = $this->gatewayManager->verifyCollectionWithGateway($order->payment_gateway, $reference);

        $state = PaymentVerificationState::from($verification);

        if ($state === PaymentVerificationState::FAILED) {
            $order->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => 'Payment was not successful',
            ], 400);
        }

        if ($state !== PaymentVerificationState::SUCCESS) {
            // PENDING or UNKNOWN — leave the order untouched. Return 200 so
            // the gateway doesn't endlessly retry a webhook that told us
            // nothing new; a later webhook/browser check/reconciliation pass
            // will resolve it.
            return response()->json([
                'success' => true,
                'message' => $verification['message'] ?? 'Payment pending',
            ]);
        }

        // Amount mismatch guard — see handle() above for why this matters for Payaza.
        $verifiedAmount = data_get($verification, 'data.amount');
        $expectedAmount = (float) $order->total_price;
        if ($verifiedAmount !== null && $expectedAmount > 0 && round((float) $verifiedAmount, 2) < round($expectedAmount, 2)) {
            Log::error('Result checker webhook: verified amount is less than expected order amount - refusing to fulfil', [
                'order_id' => $order->id,
                'reference' => $reference,
                'expected_amount' => $expectedAmount,
                'verified_amount' => $verifiedAmount,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment amount mismatch',
            ], 400);
        }

        $this->resultCheckerService->handlePaymentCallback(
            $order,
            $reference,
            $order->payment_gateway
        );

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed successfully',
            'order_id' => $order->id,
            'status' => $order->fresh()->status,
        ]);
    }

    private function resolveReference(Request $request, ?ResultCheckerOrder $order = null): ?string
    {
        $reference = $request->input('reference')
            ?? $request->input('trxref')
            ?? data_get($request->input('data'), 'reference')
            ?? data_get($request->input('eventData'), 'reference')
            ?? $request->input('externalref')
            ?? $request->input('externalRef')
            ?? $order?->payment_reference;

        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);

        return $reference !== '' ? Str::limit($reference, 255, '') : null;
    }
}
