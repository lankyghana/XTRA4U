<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentService;
use App\Support\PaymentVerificationState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function handle(Request $request)
    {
        $reference = $request->input('reference')
            ?? $request->input('trxref')
            ?? $request->input('externalref')
            ?? $request->input('externalRef');
        if (! $reference) {
            return response()->json(['success' => false, 'message' => 'Missing payment reference'], 400);
        }

        $order = Order::where('payment_reference', $reference)->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found for reference'], 404);
        }

        // Fast-path: the Paystack webhook fires before the redirect and marks the
        // order as paid in the DB. If we see payment_status = 'paid'/'completed' here
        // we can skip the Paystack API call entirely — the source of truth (our DB)
        // already confirms the payment succeeded.
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            Log::info('Payment callback: order already paid, skipping re-verification', [
                'reference' => $reference,
                'order_id' => $order->id,
            ]);

            return redirect()->route('checkout.success', ['order' => $order->id]);
        }

        // Always verify against the gateway that actually created this order —
        // never "whichever gateway is currently the admin default" — so that
        // switching the default gateway has zero effect on an order already
        // in flight under a different one.
        $verification = $this->paymentService->checkPaymentStatusForGateway($reference, $order->payment_gateway);

        $state = PaymentVerificationState::from($verification);

        if ($state === PaymentVerificationState::FAILED) {
            $order->update([
                'payment_status' => 'failed',
                'status' => 'Failed',
            ]);
            \App\Models\Transaction::where('order_id', $order->id)
                ->whereNotIn('payment_status', ['completed', 'successful'])
                ->update(['payment_status' => 'failed']);

            return $this->redirectBackToStoreOrCheckout($order, 'Payment failed.', true);
        }

        if ($state !== PaymentVerificationState::SUCCESS) {
            // PENDING (gateway says still processing) or UNKNOWN (the verify
            // call itself failed — network/timeout/malformed response). Either
            // way this is not proof the customer wasn't charged, so the order
            // is left untouched — no mutation, reference stays available for
            // the next check.
            Log::info('Payment callback: verification unresolved, leaving order pending', [
                'reference' => $reference,
                'order_id' => $order->id,
                'state' => $state,
                'response' => $verification,
            ]);

            return $this->redirectBackToStoreOrCheckout($order, 'Payment pending. Please wait for confirmation.', false);
        }

        // Update order amount if gateway returned it. Every gateway's verifyPayment()
        // already normalizes data.amount to major units (GHS) — including Paystack's,
        // which converts from pesewas internally.
        $amount = data_get($verification, 'data.amount');

        // Amount mismatch guard (mirrors CheckoutController::verify()). A no-op for
        // gateways that lock the amount in server-side at initiation; matters for
        // Payaza, whose Web Checkout SDK sets the charge amount client-side.
        $expectedAmount = (float) $order->amount_paid;
        if ($amount !== null && $expectedAmount > 0 && round((float) $amount, 2) < round($expectedAmount, 2)) {
            Log::error('Payment callback: verified amount is less than expected order amount - refusing to fulfil', [
                'order_id' => $order->id,
                'reference' => $reference,
                'expected_amount' => $expectedAmount,
                'verified_amount' => $amount,
            ]);

            return $this->redirectBackToStoreOrCheckout($order, 'Payment amount mismatch. Please contact support.', true);
        }

        if ($amount) {
            $order->amount_paid = (float) $amount;
        }

        // Complete order flows (wallet, notifications, transactions)
        $this->paymentService->completeOrder($order);

        return redirect()->route('checkout.success', ['order' => $order->id]);
    }

    private function redirectBackToStoreOrCheckout(Order $order, string $message, bool $isError)
    {
        try {
            $order->loadMissing('vendor');
        } catch (\Throwable $e) {
            // Best-effort only; fall back to checkout.
        }

        $vendorCode = $order->vendor?->vendor_code;
        if (is_string($vendorCode) && $vendorCode !== '') {
            if ($isError) {
                return redirect()->route('storefront.vendor', $vendorCode)
                    ->with('payment_failed', true)
                    ->with('payment_message', $message);
            }

            return redirect()->route('storefront.vendor', $vendorCode)->with('success', $message);
        }

        if ($isError) {
            return redirect()->route('checkout.show')
                ->with('payment_failed', true)
                ->with('payment_message', $message);
        }

        return redirect()->route('checkout.show')
            ->with('payment_pending', true)
            ->with('payment_reference', $order->payment_reference)
            ->with('payment_message', $message);
    }
}
