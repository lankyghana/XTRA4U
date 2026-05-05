<?php

namespace App\Http\Controllers;

use App\Models\ResultCheckerOrder;
use App\Services\GatewayManager;
use App\Services\ResultCheckerService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResultCheckerPaymentCallbackController extends Controller
{
    public function __construct(
        protected GatewayManager $gatewayManager,
        protected ResultCheckerService $resultCheckerService,
    ) {}

    public function handle(Request $request, ResultCheckerOrder $order)
    {
        $reference = $this->resolveReference($request, $order);
        if (!$reference) {
            return response()->json([
                'success' => false,
                'message' => 'Missing payment reference',
            ], 400);
        }

        $verification = $this->gatewayManager->verifyCollection($reference);
        if (!($verification['success'] ?? false)) {
            return redirect()->route('result-checkers.status.show', $order)
                ->with('payment_failed', true)
                ->with('payment_message', $verification['message'] ?? 'Payment verification failed');
        }

        $status = strtolower((string) data_get($verification, 'data.status', ''));
        $isSuccess = in_array($status, ['success', 'successful', 'completed', 'paid'], true);

        if (!$isSuccess) {
            $order->update(['status' => 'failed']);

            return redirect()->route('result-checkers.status.show', $order)
                ->with('payment_failed', true)
                ->with('payment_message', 'Payment was not successful');
        }

        $this->resultCheckerService->handlePaymentCallback(
            $order,
            $reference,
            $order->payment_gateway
        );

        $order->refresh();
        if ($order->status === 'pending_stock') {
            return redirect()->route('result-checkers.pending-stock', $order);
        }

        return redirect()->route('result-checkers.success', $order);
    }

    public function webhook(Request $request)
    {
        $reference = $this->resolveReference($request);
        if (!$reference) {
            return response()->json([
                'success' => false,
                'message' => 'Missing payment reference',
            ], 400);
        }

        $order = ResultCheckerOrder::query()
            ->where('payment_reference', $reference)
            ->first();

        if (!$order) {
            $orderId = (int) $request->input('order_id');
            if ($orderId > 0) {
                $order = ResultCheckerOrder::find($orderId);
            }
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Result checker order not found',
            ], 404);
        }

        $verification = $this->gatewayManager->verifyCollection($reference);
        if (!($verification['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $verification['message'] ?? 'Payment verification failed',
            ], 400);
        }

        $status = strtolower((string) data_get($verification, 'data.status', ''));
        $isSuccess = in_array($status, ['success', 'successful', 'completed', 'paid'], true);

        if (!$isSuccess) {
            $order->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => 'Payment was not successful',
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

        if (!is_string($reference)) {
            return null;
        }

        $reference = trim($reference);
        return $reference !== '' ? Str::limit($reference, 255, '') : null;
    }
}
