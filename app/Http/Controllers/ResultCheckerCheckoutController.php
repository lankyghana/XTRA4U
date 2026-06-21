<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\NetworkService;
use App\Models\VendorResultCheckerSetting;
use App\Models\ResultCheckerOrder;
use App\Services\GatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        try {
            return DB::transaction(function () use ($vendor, $validated) {
                // Load service
                $service = NetworkService::findOrFail($validated['service_id']);

                // Verify service is a result checker
                if ($service->service_type !== 'results_checker' || !$service->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This result checker is not available',
                    ], 404);
                }

                // Verify vendor has enabled this checker type
                $vendorSetting = VendorResultCheckerSetting::where('vendor_id', $vendor->id)
                    ->where('service_id', $service->id)
                    ->first();

                if (!$vendorSetting || !$vendorSetting->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This vendor does not offer this result checker',
                    ], 403);
                }

                // Calculate pricing
                $basePrice = $service->getPriceForQuantity((int) $validated['quantity']);
                $vendorProfit = $vendorSetting->profit_amount * $validated['quantity'];
                $unitPrice = $basePrice + $vendorSetting->profit_amount;
                $totalPrice = $unitPrice * $validated['quantity'];

                // Create order with pending_payment status
                $order = ResultCheckerOrder::create([
                    'vendor_id' => $vendor->id,
                    'service_id' => $service->id,
                    'customer_phone' => $validated['customer_phone'],
                    'customer_name' => $validated['customer_name'],
                    'quantity' => $validated['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'vendor_profit' => $vendorProfit,
                    'status' => 'pending_payment',
                ]);

                Log::info('ResultCheckerCheckoutController: Order created', [
                    'order_id' => $order->id,
                    'vendor_id' => $vendor->id,
                    'service_id' => $service->id,
                    'amount' => $totalPrice,
                ]);

                // Generate a unique email for the transaction (we don't have user email)
                // Using .com instead of .local because gateways like Paystack reject .local domains.
                $email = $validated['customer_phone'] . '@xtra4u.com';

                // Create a unique reference for the order
                $reference = 'XTRA4U-RC-' . strtoupper(uniqid()) . '-' . $order->id;

                // Initiate payment via gateway (use genericPayment since collect expects an Order model)
                $paymentResult = $this->gatewayManager->genericPayment([
                    'email' => $email,
                    'amount' => $totalPrice,
                    'callback_url' => route('result-checkers.payment.callback', ['order' => $order->id]),
                    'reference' => $reference,
                    'metadata' => [
                        'phone_number' => $order->customer_phone,
                        'payer_phone' => $validated['payer_phone'] ?? null,
                        'payer_network' => $validated['payer_network'] ?? null,
                    ],
                ]);

                // Store the payment reference on the order
                if ($paymentResult['reference'] ?? null) {
                    $order->update([
                        'payment_reference' => $paymentResult['reference'],
                        'payment_gateway' => $paymentResult['gateway_name'] ?? null,
                    ]);
                }

                // Determine response based on collection flow
                if (!($paymentResult['success'] ?? false)) {
                    // Payment initiation failed
                    $order->update(['status' => 'failed']);
                    return response()->json([
                        'success' => false,
                        'message' => $paymentResult['message'] ?? 'Payment gateway error',
                    ], 400);
                }

                // Payment initiation succeeded
                // Payment initiation succeeded
                return response()->json([
                    'success' => true,
                    'message' => 'Checkout initiated',
                    'order_id' => $order->id,
                    'reference' => $paymentResult['reference'] ?? null,
                    'redirect' => $paymentResult['authorization_url'] ?? null,
                    'payment_data' => [
                        'authorization_url' => $paymentResult['authorization_url'] ?? null,
                        'inline_form' => $paymentResult['inline_form'] ?? null,
                        'gateway' => $paymentResult['gateway_name'] ?? null,
                        'collection_flow' => $paymentResult['collection_flow'] ?? 'redirect',
                    ],
                ]);
            });
        } catch (\Exception $e) {
            Log::error('ResultCheckerCheckoutController: Error during checkout', [
                'error' => $e->getMessage(),
                'vendor_id' => $vendor->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during checkout',
            ], 500);
        }
    }
}
