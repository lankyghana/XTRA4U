<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\NetworkService;
use App\Models\VendorResultCheckerSetting;
use App\Models\ResultCheckerOrder;
use App\Services\GatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResultCheckerCheckoutController extends Controller
{
    public function __construct(
        protected GatewayManager $gatewayManager,
    ) {}

    /**
     * Initiate result checker order checkout
     * POST /store/{vendor}/result-checkers/checkout
     */
    public function initiateCheckout(Request $request, Vendor $vendor)
    {
        $validated = $request->validate([
            'service_id' => ['required', 'exists:network_services,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'payer_network' => ['nullable', 'string', 'max:20'],
        ]);

        // Service availability guard: an admin may have closed result checkers.
        if (\App\Support\ServiceAvailability::isClosed('results')) {
            return response()->json([
                'success' => false,
                'message' => \App\Support\ServiceAvailability::message(),
            ], 422);
        }

        // Validate service and vendor eligibility (read-only — no transaction needed).
        $service = NetworkService::findOrFail($validated['service_id']);

        if ($service->service_type !== 'results_checker' || !$service->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This result checker is not available',
            ], 404);
        }

        $vendorSetting = VendorResultCheckerSetting::where('vendor_id', $vendor->id)
            ->where('service_id', $service->id)
            ->first();

        if (!$vendorSetting || !$vendorSetting->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This vendor does not offer this result checker',
            ], 403);
        }

        // Calculate pricing.
        $basePrice    = $service->getPriceForQuantity((int) $validated['quantity']);
        $vendorProfit = $vendorSetting->profit_amount * $validated['quantity'];
        $unitPrice    = $basePrice + $vendorSetting->profit_amount;
        $totalPrice   = $unitPrice * $validated['quantity'];

        // Persist the order, then initiate payment. Both are inside the same try/catch
        // so any DB or gateway error is caught and returned as a clean JSON response
        // rather than bubbling up as an unhandled 500.
        $order = null;
        try {
            $order = ResultCheckerOrder::create([
                'vendor_id'      => $vendor->id,
                'service_id'     => $service->id,
                'customer_phone' => $validated['customer_phone'],
                'customer_name'  => $validated['customer_name'] ?? null,
                'quantity'       => $validated['quantity'],
                'unit_price'     => $unitPrice,
                'total_price'    => $totalPrice,
                'vendor_profit'  => $vendorProfit,
                'status'         => 'pending_payment',
            ]);

            Log::info('ResultCheckerCheckoutController: Order created', [
                'order_id'   => $order->id,
                'vendor_id'  => $vendor->id,
                'service_id' => $service->id,
                'amount'     => $totalPrice,
            ]);

            // Using .com instead of .local — gateways like Paystack reject .local domains.
            $email     = $validated['customer_phone'] . '@xtra4u.com';
            $reference = 'XTRA4U-RC-' . strtoupper(uniqid('', true)) . '-' . $order->id;

            $paymentResult = $this->gatewayManager->genericPayment([
                'email'        => $email,
                'amount'       => $totalPrice,
                'callback_url' => route('result-checkers.payment.callback', ['order' => $order->id]),
                'reference'    => $reference,
                'metadata'     => [
                    // For inline-MoMo gateways (BulkClix, Moolre), use the payer's phone for collection.
                    // Fall back to customer_phone if no payer_phone was provided (redirect flow).
                    'phone_number'  => $validated['payer_phone'] ?? $order->customer_phone,
                    'payer_phone'   => $validated['payer_phone'] ?? null,
                    'payer_network' => $validated['payer_network'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('ResultCheckerCheckoutController: Checkout exception', [
                'order_id'  => $order?->id,
                'vendor_id' => $vendor->id,
                'error'     => $e->getMessage(),
                'class'     => get_class($e),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => collect($e->getTrace())->take(5)->map(fn ($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''))->implode(' | '),
            ]);
            if ($order) {
                $order->update(['status' => 'failed']);
            }

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during checkout',
            ], 500);
        }

        // Store the payment reference returned by the gateway.
        if ($paymentResult['reference'] ?? null) {
            $order->update([
                'payment_reference' => $paymentResult['reference'],
                'payment_gateway'   => $paymentResult['gateway_name'] ?? null,
            ]);
        }

        if (!($paymentResult['success'] ?? false)) {
            $order->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => $paymentResult['message'] ?? 'Payment gateway error',
            ], 400);
        }

        return response()->json([
            'success'      => true,
            'message'      => 'Checkout initiated',
            'order_id'     => $order->id,
            'reference'    => $paymentResult['reference'] ?? null,
            'redirect'     => $paymentResult['authorization_url'] ?? null,
            'payment_data' => [
                'authorization_url' => $paymentResult['authorization_url'] ?? null,
                'inline_form'       => $paymentResult['inline_form'] ?? null,
                'gateway'           => $paymentResult['gateway_name'] ?? null,
                'collection_flow'   => $paymentResult['collection_flow'] ?? 'redirect',
            ],
        ]);
    }
}
