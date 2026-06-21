<?php

namespace App\Services;

use App\Models\UssdSession;

class UssdMenuService
{
    /**
     * Process the user input and return the next USSD menu string.
     * Moolre expects 'CON ' for continuous sessions, and 'END ' to terminate.
     */
    public function handle(UssdSession $session, string $rawText): string
    {
        // Parse the text. Some gateways send concatenated input (e.g. 1*2*055).
        // We extract the latest input to process the current state.
        $inputArray = explode('*', $rawText);
        $text = trim(end($inputArray));

        $step = $session->current_step;

        // Global back navigation
        if ($text === '0' && $step !== 'MAIN_MENU') {
            $session->update(['current_step' => 'MAIN_MENU']);
            $step = 'MAIN_MENU';
            $text = ''; // Reset input so main menu displays instead of matching options
        }

        switch ($step) {
            case 'MAIN_MENU':
                return $this->handleMainMenu($session, $text);
            
            // Phase 5: Buy Data Bundle Flow
            case 'DATA_NETWORK':
            case 'DATA_BUNDLE':
            case 'DATA_RECIPIENT':
            case 'DATA_CONFIRM':
                return $this->handleDataBundleFlow($session, $text);

            // Phase 6: Results Checker Flow
            case 'RESULT_EXAM_TYPE':
            case 'RESULT_QUANTITY':
            case 'RESULT_CONFIRM':
                return $this->handleResultsCheckerFlow($session, $text);

            // Phase 7: AFA Registration Flow
            case 'AFA_NAME':
            case 'AFA_INDEX':
            case 'AFA_PHONE':
            case 'AFA_CONFIRM':
                return $this->handleAfaRegistrationFlow($session, $text);

            default:
                $session->update(['current_step' => 'MAIN_MENU']);
                return "CON Welcome to XTRA4U\n1. Buy Data Bundle\n2. Results Checker\n3. AFA Registration\n\nSupport: 0531192005";
        }
    }

    private function handleMainMenu(UssdSession $session, string $text): string
    {
        if (empty($text)) {
            return "CON Welcome to XTRA4U\n1. Buy Data Bundle\n2. Results Checker\n\nSupport: 0531192005";
        }

        if ($text === '1') {
            $session->update(['current_step' => 'DATA_NETWORK']);
            return "CON Select Network:\n1. MTN\n2. Telecel\n3. AT\n\n0. Back";
        } elseif ($text === '2') {
            $checkers = \App\Models\NetworkService::where('service_type', 'results_checker')->where('is_active', true)->get();
            if ($checkers->isEmpty()) {
                return "CON No Results Checkers available currently.\n\n0. Back";
            }
            $session->updateData('available_checkers', $checkers->pluck('id')->toArray());
            
            $menu = "CON Select Exam Type:\n";
            foreach ($checkers as $index => $checker) {
                $menu .= ($index + 1) . ". {$checker->name}\n";
            }
            $menu .= "\n0. Back";
            
            $session->update(['current_step' => 'RESULT_EXAM_TYPE']);
            return $menu;
        }

        return "CON Invalid choice.\nWelcome to XTRA4U\n1. Buy Data Bundle\n2. Results Checker\n\nSupport: 0531192005";
    }

    private function handleDataBundleFlow(UssdSession $session, string $text): string
    {
        $step = $session->current_step;

        if ($step === 'DATA_NETWORK') {
            $networks = ['1' => 'MTN', '2' => 'Telecel', '3' => 'AT'];
            if (!isset($networks[$text])) {
                return "CON Invalid Network.\nSelect Network:\n1. MTN\n2. Telecel\n3. AT\n\n0. Back";
            }
            
            $selectedNetwork = $networks[$text];
            $session->updateData('network_name', $selectedNetwork);
            
            // Fetch products for this network
            $products = \App\Models\Product::where('is_active', true)->get()->filter(function($p) use ($selectedNetwork) {
                $meta = $p->decoded_description;
                $net = $meta['network'] ?? '';
                $cat = $meta['category'] ?? 'data'; // fallback to data if missing
                return strcasecmp($net, $selectedNetwork) === 0 && strcasecmp($cat, 'data') === 0;
            })->sortBy('price')->take(7)->values(); // Take top 7 to fit USSD window size
            
            if ($products->isEmpty()) {
                return "CON No bundles available for {$selectedNetwork}.\n\n0. Back";
            }
            
            $session->updateData('available_bundles', $products->pluck('id')->toArray());
            
            $menu = "CON Select {$selectedNetwork} Bundle:\n";
            foreach ($products as $index => $p) {
                $menu .= ($index + 1) . ". {$p->name} - GHS {$p->price}\n";
            }
            $menu .= "\n0. Back";
            
            $session->update(['current_step' => 'DATA_BUNDLE']);
            return $menu;
        }

        if ($step === 'DATA_BUNDLE') {
            $bundles = $session->getData('available_bundles', []);
            $index = (int)$text - 1;
            
            if (!isset($bundles[$index])) {
                return "CON Invalid selection.\nPlease enter a valid bundle number.\n\n0. Back";
            }
            
            $productId = $bundles[$index];
            $product = \App\Models\Product::find($productId);
            if (!$product) {
                return "CON Bundle not found.\n\n0. Back";
            }
            
            $session->updateData('product_id', $product->id);
            $session->updateData('product_name', $product->name);
            $session->updateData('product_price', $product->price);
            
            $session->update(['current_step' => 'DATA_RECIPIENT']);
            return "CON You selected {$product->name}.\nEnter recipient phone number:\n\n0. Back";
        }

        if ($step === 'DATA_RECIPIENT') {
            if (strlen($text) < 9) {
                return "CON Invalid phone number.\nEnter recipient phone number:\n\n0. Back";
            }
            
            $session->updateData('recipient_phone', $text);
            $session->update(['current_step' => 'DATA_CONFIRM']);
            
            $productName = $session->getData('product_name');
            $price = $session->getData('product_price');
            
            return "CON Confirm Purchase:\nBundle: {$productName}\nRecipient: {$text}\nPrice: GHS {$price}\n\n1. Confirm & Pay\n0. Back";
        }

        if ($step === 'DATA_CONFIRM') {
            if ($text !== '1') {
                return "CON Invalid selection.\n1. Confirm & Pay\n0. Back";
            }
            
            // Proceed to Order Creation & Payment
            $productId = $session->getData('product_id');
            $product = \App\Models\Product::with('vendor')->find($productId);
            $recipientPhone = $session->getData('recipient_phone');
            
            if (!$product) {
                return "END Bundle no longer available. Please try again.";
            }

            try {
                $gatewayManager = app(\App\Services\GatewayManager::class);
                $gatewayName = $gatewayManager->getDefaultGatewayName(\App\Models\PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION) ?? 'paystack';
                
                $sessionVendorId = $session->getData('vendor_id', 1);
                $isReseller = ($sessionVendorId != $product->vendor_id);

                $order = \App\Models\Order::create([
                    'recipient_phone_number' => $recipientPhone,
                    'mobile_money_number' => $session->phone_number,
                    'mobile_money_network' => $session->getData('network_name'),
                    'service_purchased' => $product->name,
                    'amount_paid' => $product->price,
                    'vendor_id' => $sessionVendorId,
                    'vendor_service_id' => $product->id,
                    'owner_vendor_id' => $isReseller ? $product->vendor_id : null,
                    'reseller_vendor_id' => $isReseller ? $sessionVendorId : null,
                    'is_reseller_order' => $isReseller,
                    'status' => 'Pending',
                    'payment_status' => 'unpaid',
                    'payment_gateway' => $gatewayName,
                ]);

                $vendorEmail = $product->vendor->email ?? 'ussd@xtra4u.com';
                $paymentService = app(\App\Services\PaymentService::class);
                
                $init = $paymentService->initiatePayment($order, $vendorEmail, (float) $product->price);
                
                if (!($init['success'] ?? false)) {
                    \Illuminate\Support\Facades\Log::error('USSD Payment Initiation Failed', ['order_id' => $order->id, 'response' => $init]);
                    return "END Failed to initiate payment. Please try again later.";
                }

                return "END Order placed successfully. Please check your phone for the payment prompt.";
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('USSD Order Creation Error', ['error' => $e->getMessage()]);
                return "END A system error occurred. Please try again.";
            }
        }

        return "END Data Bundle flow encountered an error.";
    }

    private function handleResultsCheckerFlow(UssdSession $session, string $text): string
    {
        $step = $session->current_step;

        if ($step === 'RESULT_EXAM_TYPE') {
            $checkers = $session->getData('available_checkers', []);
            $index = (int)$text - 1;

            if (!isset($checkers[$index])) {
                return "CON Invalid selection.\nPlease enter a valid exam type number.\n\n0. Back";
            }

            $serviceId = $checkers[$index];
            $service = \App\Models\NetworkService::find($serviceId);
            
            if (!$service || !$service->is_active) {
                return "CON Selected exam type is unavailable.\n\n0. Back";
            }

            $session->updateData('service_id', $service->id);
            $session->updateData('service_name', $service->name);

            $session->update(['current_step' => 'RESULT_QUANTITY']);
            return "CON Enter quantity to purchase (1-10):\n\n0. Back";
        }

        if ($step === 'RESULT_QUANTITY') {
            $qty = (int)$text;
            if ($qty < 1 || $qty > 10) {
                return "CON Invalid quantity. Please enter a number between 1 and 10.\n\n0. Back";
            }

            $session->updateData('quantity', $qty);
            $session->update(['current_step' => 'RESULT_PHONE']);
            return "CON Enter recipient phone number to receive the PINs (e.g. 0244...):\n\n0. Back";
        }

        if ($step === 'RESULT_PHONE') {
            if (strlen($text) < 9) {
                return "CON Invalid phone number.\nEnter recipient phone number:\n\n0. Back";
            }
            
            $session->updateData('recipient_phone', $text);
            $session->update(['current_step' => 'RESULT_CONFIRM']);
            
            // Calculate price
            $serviceId = $session->getData('service_id');
            $service = \App\Models\NetworkService::find($serviceId);
            $qty = $session->getData('quantity');
            
            // Assume no vendor markup for USSD global sales, use base price
            $totalPrice = $service->getPriceForQuantity($qty) * $qty;
            $session->updateData('total_price', $totalPrice);

            $serviceName = $session->getData('service_name');
            return "CON Confirm Purchase:\nExam: {$serviceName}\nQty: {$qty}\nRecipient: {$text}\nTotal: GHS {$totalPrice}\n\n1. Confirm & Pay\n0. Back";
        }

        if ($step === 'RESULT_CONFIRM') {
            if ($text !== '1') {
                return "CON Invalid selection.\n1. Confirm & Pay\n0. Back";
            }

            $serviceId = $session->getData('service_id');
            $service = \App\Models\NetworkService::find($serviceId);
            $qty = $session->getData('quantity');
            $recipientPhone = $session->getData('recipient_phone');
            $totalPrice = $session->getData('total_price');

            if (!$service || !$service->is_active) {
                return "END Selected exam type is no longer available.";
            }

            try {
                $sessionVendorId = $session->getData('vendor_id', 1);

                $order = \App\Models\ResultCheckerOrder::create([
                    'vendor_id' => $sessionVendorId,
                    'service_id' => $service->id,
                    'customer_phone' => $recipientPhone,
                    'customer_name' => 'USSD Customer',
                    'quantity' => $qty,
                    'unit_price' => $totalPrice / $qty,
                    'total_price' => $totalPrice,
                    'status' => 'pending_payment',
                ]);

                $gatewayManager = app(\App\Services\GatewayManager::class);
                $email = $recipientPhone . '@ussd.xtra4u.local';
                
                $paymentResult = $gatewayManager->collect($order, $email, $totalPrice);

                if ($paymentResult['reference'] ?? null) {
                    $order->update([
                        'payment_reference' => $paymentResult['reference'],
                        'payment_gateway' => $paymentResult['gateway_name'] ?? null,
                    ]);
                }

                if (!($paymentResult['success'] ?? false)) {
                    $order->update(['status' => 'failed']);
                    \Illuminate\Support\Facades\Log::error('USSD ResultChecker Payment Failed', ['order_id' => $order->id, 'response' => $paymentResult]);
                    return "END Failed to initiate payment. Please try again later.";
                }

                return "END Order placed successfully. Please check your phone for the payment prompt.";
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('USSD ResultChecker Order Error', ['error' => $e->getMessage()]);
                return "END A system error occurred. Please try again.";
            }
        }

        return "END Results Checker flow encountered an error.";
    }

    private function handleAfaRegistrationFlow(UssdSession $session, string $text): string
    {
        // To be implemented in Phase 7
        return "END AFA Registration feature is coming soon.";
    }
}
