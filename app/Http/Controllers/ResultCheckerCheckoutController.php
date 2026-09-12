<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\ResultCheckerOrder;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Services\GatewayManager;
use App\Services\Payments\CheckoutIntentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResultCheckerCheckoutController extends Controller
{
    public function __construct(
        protected GatewayManager $gatewayManager,
        protected ?CheckoutIntentGuard $intentGuard = null,
    ) {
        $this->intentGuard = $intentGuard ?? app(CheckoutIntentGuard::class);
    }

    private function rcoIsSuccess(ResultCheckerOrder $o): bool
    {
        return $o->isPaid();
    }

    private function rcoIsTerminalFailure(ResultCheckerOrder $o): bool
    {
        return $o->status === 'failed';
    }

    private function rcoHasGateway(ResultCheckerOrder $o): bool
    {
        return (bool) $o->payment_gateway && (bool) $o->payment_reference;
    }

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
            // Phase 4: identifies one payment ATTEMPT for one checkout intent.
            // Optional for backward compatibility — see CheckoutIntentGuard.
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        // Service availability guard: an admin may have closed result checkers.
        if (\App\Support\ServiceAvailability::isClosed('results')) {
            return response()->json([
                'success' => false,
                'message' => \App\Support\ServiceAvailability::message(),
            ], 422);
        }

        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $intentScope = CheckoutIntentGuard::scopeForSession($request->session()->getId());

        $intentDecision = $this->intentGuard->evaluate(
            ResultCheckerOrder::class,
            $intentScope,
            $idempotencyKey,
            fn (ResultCheckerOrder $o) => $this->rcoIsSuccess($o),
            fn (ResultCheckerOrder $o) => $this->rcoIsTerminalFailure($o),
            fn (ResultCheckerOrder $o) => $this->rcoHasGateway($o),
        );

        if ($intentDecision['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
            $existing = $intentDecision['payable'];

            return response()->json([
                'success' => true,
                'message' => 'Payment already completed.',
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::STILL_PENDING) {
            $existing = $intentDecision['payable'];

            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous payment. Please don't pay again yet.",
                'order_id' => $existing->id,
                'reference' => $existing->payment_reference,
                'verify_url' => route('result-checkers.payment.callback', ['order' => $existing->id]),
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE && $idempotencyKey) {
            $idempotencyKey = $this->intentGuard->freshKeyAfterFailure($idempotencyKey);
        }

        // Validate service and vendor eligibility (read-only — no transaction needed).
        $service = NetworkService::findOrFail($validated['service_id']);

        if ($service->service_type !== 'results_checker' || ! $service->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This result checker is not available',
            ], 404);
        }

        $vendorSetting = VendorResultCheckerSetting::where('vendor_id', $vendor->id)
            ->where('service_id', $service->id)
            ->first();

        if (! $vendorSetting || ! $vendorSetting->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This vendor does not offer this result checker',
            ], 403);
        }

        // Calculate pricing.
        $basePrice = $service->getPriceForQuantity((int) $validated['quantity']);
        $vendorProfit = $vendorSetting->profit_amount * $validated['quantity'];
        $unitPrice = $basePrice + $vendorSetting->profit_amount;
        $totalPrice = $unitPrice * $validated['quantity'];

        // Persist the order, then initiate payment. Both are inside the same try/catch
        // so any DB or gateway error is caught and returned as a clean JSON response
        // rather than bubbling up as an unhandled 500.
        $order = null;
        try {
            // Race-safe: two concurrent submissions of the same checkout
            // intent can create at most one row here — see
            // CheckoutIntentGuard::createOrReuse().
            $creationResult = $this->intentGuard->createOrReuse(
                ResultCheckerOrder::class,
                $intentScope,
                $idempotencyKey,
                fn (?string $key) => ResultCheckerOrder::create([
                    'vendor_id' => $vendor->id,
                    'service_id' => $service->id,
                    'customer_phone' => $validated['customer_phone'],
                    'customer_name' => $validated['customer_name'] ?? null,
                    'quantity' => $validated['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'vendor_profit' => $vendorProfit,
                    'status' => 'pending_payment',
                    'idempotency_scope' => $key ? $intentScope : null,
                    'idempotency_key' => $key,
                ]),
                fn (ResultCheckerOrder $o) => $this->rcoIsSuccess($o),
                fn (ResultCheckerOrder $o) => $this->rcoIsTerminalFailure($o),
                fn (ResultCheckerOrder $o) => $this->rcoHasGateway($o),
            );

            if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
                $existing = $creationResult['payable'];

                return response()->json([
                    'success' => true,
                    'message' => 'Payment already completed.',
                    'order_id' => $existing->id,
                    'reference' => $existing->payment_reference,
                ]);
            }

            if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
                $existing = $creationResult['payable'];

                return response()->json([
                    'success' => true,
                    'status' => 'confirming',
                    'message' => "We're still confirming your previous payment. Please don't pay again yet.",
                    'order_id' => $existing->id,
                    'reference' => $existing->payment_reference,
                    'verify_url' => route('result-checkers.payment.callback', ['order' => $existing->id]),
                ]);
            }

            if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
                return response()->json(['success' => false, 'message' => 'Please try again.'], 409);
            }

            // PROCEED: a fresh order for this intent was just created.
            $order = $creationResult['payable'];

            Log::info('ResultCheckerCheckoutController: Order created', [
                'order_id' => $order->id,
                'vendor_id' => $vendor->id,
                'service_id' => $service->id,
                'amount' => $totalPrice,
            ]);

            // Using .com instead of .local — gateways like Paystack reject .local domains.
            $email = $validated['customer_phone'].'@xtra4u.com';
            $reference = 'XTRA4U-RC-'.strtoupper(uniqid('', true)).'-'.$order->id;

            $paymentResult = $this->gatewayManager->genericPayment([
                'email' => $email,
                'amount' => $totalPrice,
                'callback_url' => route('result-checkers.payment.callback', ['order' => $order->id]),
                'reference' => $reference,
                'metadata' => [
                    // For inline-MoMo gateways (BulkClix, Moolre), use the payer's phone for collection.
                    // Fall back to customer_phone if no payer_phone was provided (redirect flow).
                    'phone_number' => $validated['payer_phone'] ?? $order->customer_phone,
                    'payer_phone' => $validated['payer_phone'] ?? null,
                    'payer_network' => $validated['payer_network'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('ResultCheckerCheckoutController: Checkout exception', [
                'order_id' => $order?->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => collect($e->getTrace())->take(5)->map(fn ($f) => ($f['file'] ?? '?').':'.($f['line'] ?? '?').' '.($f['function'] ?? ''))->implode(' | '),
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
                'payment_gateway' => $paymentResult['gateway_name'] ?? null,
            ]);
        }

        if (! ($paymentResult['success'] ?? false)) {
            $order->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => $paymentResult['message'] ?? 'Payment gateway error',
            ], 400);
        }

        // flow_type/checkout_config are only present for gateways that launch a
        // client-side SDK popup (currently Payaza) — omitted for every other
        // gateway so existing behaviour is unchanged.
        $isSdkPopupFlow = ! empty($paymentResult['checkout_config']);

        return response()->json([
            'success' => true,
            'message' => 'Checkout initiated',
            'order_id' => $order->id,
            'reference' => $paymentResult['reference'] ?? null,
            'redirect' => $paymentResult['authorization_url'] ?? null,
            'flow_type' => $isSdkPopupFlow ? $paymentResult['flow_type'] : null,
            'checkout_config' => $isSdkPopupFlow ? $paymentResult['checkout_config'] : null,
            'verify_url' => route('result-checkers.payment.callback', ['order' => $order->id]),
            'payment_data' => [
                'authorization_url' => $paymentResult['authorization_url'] ?? null,
                'inline_form' => $paymentResult['inline_form'] ?? null,
                'gateway' => $paymentResult['gateway_name'] ?? null,
                'collection_flow' => $paymentResult['collection_flow'] ?? 'redirect',
            ],
        ]);
    }
}
