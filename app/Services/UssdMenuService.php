<?php

namespace App\Services;

use App\Models\NetworkService;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResultCheckerOrder;
use App\Models\UssdSession;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\Ussd\UssdConfig;
use Illuminate\Support\Facades\Log;

class UssdMenuService
{
    public function __construct(
        private readonly UssdConfig $config,
        private readonly CheckoutIntentGuard $intentGuard,
    ) {}

    /**
     * Phase 5 finding: this is a telco USSD dial session, not a web request —
     * a customer confirming "1. Confirm & Pay" has no idempotency key to send
     * (no JS, no hidden field), and USSD aggregators are known to retry a
     * request whose response didn't arrive before their own short timeout.
     * Two near-simultaneous "confirm" deliveries for the SAME dial session
     * would otherwise each create their own Order/ResultCheckerOrder and
     * initialize their own charge — the exact class of bug Phase 4 closed
     * for the web checkout endpoints, missed here because this controller
     * was never in Phase 4's scan. Reuses the identical CheckoutIntentGuard
     * every other surface uses; the aggregator's own opaque `sessionId` (see
     * UssdGatewayRequest) is the natural "same intent" identity — a single
     * dial session can only ever confirm one purchase.
     */
    private function orderIsSuccess(Order $o): bool
    {
        return in_array($o->payment_status, ['paid', 'completed'], true);
    }

    private function orderIsTerminalFailure(Order $o): bool
    {
        return $o->payment_status === 'failed';
    }

    private function orderHasGateway(Order $o): bool
    {
        return (bool) $o->payment_gateway && (bool) $o->payment_reference;
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
     * Advance the menu one step.
     *
     * `$input` is the single keypress this request represents, already stripped
     * of the dialled prefix by UssdRequestParser. This service no longer sees
     * the raw gateway text — previously it read end($segments) as the keypress
     * while UssdSessionService read $segments[0] as the vendor code, so the two
     * disagreed about what the same string meant.
     *
     * Returns a string prefixed 'CON ' to continue or 'END ' to terminate.
     */
    public function handle(UssdSession $session, string $input): string
    {
        $text = trim($input);
        $step = $session->current_step;

        // Global back navigation.
        if ($text === '0' && $step !== 'MAIN_MENU') {
            $session->forceFill(['current_step' => 'MAIN_MENU', 'retry_count' => 0])->save();
            $step = 'MAIN_MENU';
            $text = '';
        }

        return match ($step) {
            'MAIN_MENU' => $this->handleMainMenu($session, $text),

            'DATA_NETWORK',
            'DATA_BUNDLE',
            'DATA_RECIPIENT',
            'DATA_CONFIRM' => $this->handleDataBundleFlow($session, $text),

            'RESULT_EXAM_TYPE',
            'RESULT_QUANTITY',
            'RESULT_PHONE',
            'RESULT_CONFIRM' => $this->handleResultsCheckerFlow($session, $text),

            default => $this->resetToMainMenu($session),
        };
    }

    private function resetToMainMenu(UssdSession $session): string
    {
        $session->forceFill(['current_step' => 'MAIN_MENU'])->save();

        return $this->mainMenuText();
    }

    private function mainMenuText(): string
    {
        $welcome = $this->config->welcomeMessage();
        $support = $this->config->supportNumber();
        $footer = $support ? "\n\nSupport: {$support}" : '';

        return "CON {$welcome}\n1. Buy Data Bundle\n2. Results Checker{$footer}";
    }

    /**
     * Render a retry prompt, or terminate once the admin-configured retry cap
     * is reached. Without this, a caller could loop on invalid input forever on
     * a single reserved session.
     */
    private function invalid(UssdSession $session, string $body): string
    {
        $session->increment('retry_count');

        if ($session->retry_count >= $this->config->maxRetryAttempts()) {
            return 'END Too many invalid entries. Please dial again.';
        }

        return "CON {$body}";
    }

    private function isValidPhoneNumber(string $value): bool
    {
        return (bool) preg_match('/^\+?\d{9,15}$/', $value);
    }

    private function handleMainMenu(UssdSession $session, string $text): string
    {
        if ($text === '') {
            return $this->mainMenuText();
        }

        if ($text === '1') {
            $session->forceFill(['current_step' => 'DATA_NETWORK', 'retry_count' => 0])->save();

            return "CON Select Network:\n1. MTN\n2. Telecel\n3. AT\n\n0. Back";
        }

        if ($text === '2') {
            $checkers = NetworkService::where('service_type', 'results_checker')
                ->where('is_active', true)
                ->get();

            if ($checkers->isEmpty()) {
                return "CON No Results Checkers available currently.\n\n0. Back";
            }

            $session->setDataMany(['available_checkers' => $checkers->pluck('id')->toArray()]);

            $menu = "CON Select Exam Type:\n";
            foreach ($checkers as $index => $checker) {
                $menu .= ($index + 1).". {$checker->name}\n";
            }
            $menu .= "\n0. Back";

            $session->forceFill(['current_step' => 'RESULT_EXAM_TYPE', 'retry_count' => 0])->save();

            return $menu;
        }

        return $this->invalid($session, "Invalid choice.\n".ltrim($this->mainMenuText(), 'CON '));
    }

    private function handleDataBundleFlow(UssdSession $session, string $text): string
    {
        $step = $session->current_step;

        if ($step === 'DATA_NETWORK') {
            $networks = ['1' => 'MTN', '2' => 'Telecel', '3' => 'AT'];

            if (! isset($networks[$text])) {
                return $this->invalid($session, "Invalid Network.\nSelect Network:\n1. MTN\n2. Telecel\n3. AT\n\n0. Back");
            }

            $selectedNetwork = $networks[$text];

            $products = Product::where('is_active', true)->get()->filter(function ($p) use ($selectedNetwork) {
                $meta = $p->decoded_description;
                $net = $meta['network'] ?? '';
                $cat = $meta['category'] ?? 'data';

                return strcasecmp($net, $selectedNetwork) === 0 && strcasecmp($cat, 'data') === 0;
            })->sortBy('price')->take(7)->values();

            if ($products->isEmpty()) {
                return "CON No bundles available for {$selectedNetwork}.\n\n0. Back";
            }

            $session->setDataMany([
                'network_name' => $selectedNetwork,
                'available_bundles' => $products->pluck('id')->toArray(),
            ]);

            $menu = "CON Select {$selectedNetwork} Bundle:\n";
            foreach ($products as $index => $p) {
                $menu .= ($index + 1).". {$p->name} - GHS {$p->price}\n";
            }
            $menu .= "\n0. Back";

            $session->forceFill(['current_step' => 'DATA_BUNDLE', 'retry_count' => 0])->save();

            return $menu;
        }

        if ($step === 'DATA_BUNDLE') {
            $bundles = $session->getData('available_bundles', []);
            $index = (int) $text - 1;

            if (! isset($bundles[$index])) {
                return $this->invalid($session, "Invalid selection.\nPlease enter a valid bundle number.\n\n0. Back");
            }

            $product = Product::find($bundles[$index]);

            if (! $product) {
                return "CON Bundle not found.\n\n0. Back";
            }

            $session->setDataMany([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $product->price,
            ]);

            $session->forceFill(['current_step' => 'DATA_RECIPIENT', 'retry_count' => 0])->save();

            return "CON You selected {$product->name}.\nEnter recipient phone number:\n\n0. Back";
        }

        if ($step === 'DATA_RECIPIENT') {
            if (! $this->isValidPhoneNumber($text)) {
                return $this->invalid($session, "Invalid phone number.\nEnter recipient phone number:\n\n0. Back");
            }

            $session->updateData('recipient_phone', $text);
            $session->forceFill(['current_step' => 'DATA_CONFIRM', 'retry_count' => 0])->save();

            $productName = $session->getData('product_name');
            $price = $session->getData('product_price');

            return "CON Confirm Purchase:\nBundle: {$productName}\nRecipient: {$text}\nPrice: GHS {$price}\n\n1. Confirm & Pay\n0. Back";
        }

        if ($step === 'DATA_CONFIRM') {
            if ($text !== '1') {
                return $this->invalid($session, "Invalid selection.\n1. Confirm & Pay\n0. Back");
            }

            return $this->placeDataOrder($session);
        }

        return 'END Data Bundle flow encountered an error.';
    }

    private function placeDataOrder(UssdSession $session): string
    {
        $product = Product::with('vendor')->find($session->getData('product_id'));
        $recipientPhone = $session->getData('recipient_phone');

        if (! $product) {
            return 'END Bundle no longer available. Please try again.';
        }

        $scope = CheckoutIntentGuard::scopeForUssdDialSession((string) $session->session_id);
        $key = 'ussd-data-order';

        try {
            $intentDecision = $this->intentGuard->evaluate(
                Order::class,
                $scope,
                $key,
                fn (Order $o) => $this->orderIsSuccess($o),
                fn (Order $o) => $this->orderIsTerminalFailure($o),
                fn (Order $o) => $this->orderHasGateway($o),
            );

            if ($intentDecision['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
                return 'END Payment already confirmed for this order. Thank you!';
            }

            if ($intentDecision['action'] === CheckoutIntentGuard::STILL_PENDING) {
                return 'END We are still confirming your payment. Please do not dial again — check your phone for the payment prompt.';
            }

            if ($intentDecision['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
                $key = $this->intentGuard->freshKeyAfterFailure($key);
            }

            $gatewayManager = app(GatewayManager::class);
            $gatewayName = $gatewayManager->getDefaultGatewayName(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION) ?? 'paystack';

            // The session's vendor is resolved from the dialled code and stored
            // on the row. There is no fallback: previously an unresolved vendor
            // silently booked the order — and its commission — against vendor 1.
            $sessionVendorId = $session->vendor_id;
            $isReseller = ($sessionVendorId !== $product->vendor_id);

            $creationResult = $this->intentGuard->createOrReuse(
                Order::class,
                $scope,
                $key,
                fn (?string $k) => Order::create([
                    'recipient_phone_number' => $recipientPhone,
                    'mobile_money_number' => $session->phone_number,
                    'mobile_money_network' => $session->getData('network_name'),
                    'service_purchased' => $product->name,
                    'amount_paid' => $product->price,
                    // Freeze this order's financial terms at creation, exactly
                    // as the web checkout does. A USSD order is a normal order
                    // and is held to the same immutable-terms invariant.
                    ...\App\Services\Payments\OrderPricingSnapshot::forOwnedProduct($product),
                    'vendor_id' => $sessionVendorId,
                    'vendor_service_id' => $product->id,
                    'owner_vendor_id' => $isReseller ? $product->vendor_id : null,
                    'reseller_vendor_id' => $isReseller ? $sessionVendorId : null,
                    'is_reseller_order' => $isReseller,
                    'status' => 'Pending',
                    'payment_status' => 'unpaid',
                    'payment_gateway' => $gatewayName,
                    'idempotency_scope' => $k ? $scope : null,
                    'idempotency_key' => $k,
                ]),
                fn (Order $o) => $this->orderIsSuccess($o),
                fn (Order $o) => $this->orderIsTerminalFailure($o),
                fn (Order $o) => $this->orderHasGateway($o),
            );

            if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
                return 'END Payment already confirmed for this order. Thank you!';
            }

            if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
                return 'END We are still confirming your payment. Please do not dial again — check your phone for the payment prompt.';
            }

            if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
                // Vanishingly rare double-collision — see CheckoutController
                // for the identical reasoning. Never attribute someone else's
                // (or our own prior) failed row to this confirmation.
                return 'END A system error occurred. Please dial again.';
            }

            $order = $creationResult['payable'];

            $customerEmail = $session->phone_number.'@ussd.xtra4u.local';
            $init = app(PaymentService::class)->initiatePayment($order, $customerEmail, (float) $product->price);

            if (! ($init['success'] ?? false)) {
                Log::error('USSD Payment Initiation Failed', ['order_id' => $order->id, 'response' => $init]);

                return 'END Failed to initiate payment. Please try again later.';
            }

            return 'END Order placed successfully. Please check your phone for the payment prompt.';
        } catch (\Exception $e) {
            Log::error('USSD Order Creation Error', ['error' => $e->getMessage()]);

            return 'END A system error occurred. Please try again.';
        }
    }

    private function handleResultsCheckerFlow(UssdSession $session, string $text): string
    {
        $step = $session->current_step;

        if ($step === 'RESULT_EXAM_TYPE') {
            $checkers = $session->getData('available_checkers', []);
            $index = (int) $text - 1;

            if (! isset($checkers[$index])) {
                return $this->invalid($session, "Invalid selection.\nPlease enter a valid exam type number.\n\n0. Back");
            }

            $service = NetworkService::find($checkers[$index]);

            if (! $service || ! $service->is_active) {
                return "CON Selected exam type is unavailable.\n\n0. Back";
            }

            $session->setDataMany([
                'service_id' => $service->id,
                'service_name' => $service->name,
            ]);

            $session->forceFill(['current_step' => 'RESULT_QUANTITY', 'retry_count' => 0])->save();

            return "CON Enter quantity to purchase (1-10):\n\n0. Back";
        }

        if ($step === 'RESULT_QUANTITY') {
            $qty = (int) $text;

            if (! ctype_digit($text) || $qty < 1 || $qty > 10) {
                return $this->invalid($session, "Invalid quantity. Please enter a number between 1 and 10.\n\n0. Back");
            }

            $session->updateData('quantity', $qty);
            $session->forceFill(['current_step' => 'RESULT_PHONE', 'retry_count' => 0])->save();

            return "CON Enter recipient phone number to receive the PINs (e.g. 0244...):\n\n0. Back";
        }

        if ($step === 'RESULT_PHONE') {
            if (! $this->isValidPhoneNumber($text)) {
                return $this->invalid($session, "Invalid phone number.\nEnter recipient phone number:\n\n0. Back");
            }

            $service = NetworkService::find($session->getData('service_id'));

            if (! $service) {
                return 'END Selected exam type is no longer available.';
            }

            $qty = (int) $session->getData('quantity');
            $totalPrice = $service->getPriceForQuantity($qty) * $qty;

            $session->setDataMany([
                'recipient_phone' => $text,
                'total_price' => $totalPrice,
            ]);
            $session->forceFill(['current_step' => 'RESULT_CONFIRM', 'retry_count' => 0])->save();

            $serviceName = $session->getData('service_name');

            return "CON Confirm Purchase:\nExam: {$serviceName}\nQty: {$qty}\nRecipient: {$text}\nTotal: GHS {$totalPrice}\n\n1. Confirm & Pay\n0. Back";
        }

        if ($step === 'RESULT_CONFIRM') {
            if ($text !== '1') {
                return $this->invalid($session, "Invalid selection.\n1. Confirm & Pay\n0. Back");
            }

            return $this->placeResultCheckerOrder($session);
        }

        return 'END Results Checker flow encountered an error.';
    }

    private function placeResultCheckerOrder(UssdSession $session): string
    {
        $service = NetworkService::find($session->getData('service_id'));
        $qty = (int) $session->getData('quantity');
        $recipientPhone = $session->getData('recipient_phone');
        $totalPrice = $session->getData('total_price');

        if (! $service || ! $service->is_active) {
            return 'END Selected exam type is no longer available.';
        }

        $scope = CheckoutIntentGuard::scopeForUssdDialSession((string) $session->session_id);
        $key = 'ussd-rc-order';

        try {
            $intentDecision = $this->intentGuard->evaluate(
                ResultCheckerOrder::class,
                $scope,
                $key,
                fn (ResultCheckerOrder $o) => $this->rcoIsSuccess($o),
                fn (ResultCheckerOrder $o) => $this->rcoIsTerminalFailure($o),
                fn (ResultCheckerOrder $o) => $this->rcoHasGateway($o),
            );

            if ($intentDecision['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
                return 'END Payment already confirmed for this order. Thank you!';
            }

            if ($intentDecision['action'] === CheckoutIntentGuard::STILL_PENDING) {
                return 'END We are still confirming your payment. Please do not dial again — check your phone for the payment prompt.';
            }

            if ($intentDecision['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
                $key = $this->intentGuard->freshKeyAfterFailure($key);
            }

            $creationResult = $this->intentGuard->createOrReuse(
                ResultCheckerOrder::class,
                $scope,
                $key,
                fn (?string $k) => ResultCheckerOrder::create([
                    'vendor_id' => $session->vendor_id,
                    'service_id' => $service->id,
                    'customer_phone' => $recipientPhone,
                    'customer_name' => 'USSD Customer',
                    'quantity' => $qty,
                    'unit_price' => $totalPrice / $qty,
                    'total_price' => $totalPrice,
                    'status' => 'pending_payment',
                    'idempotency_scope' => $k ? $scope : null,
                    'idempotency_key' => $k,
                ]),
                fn (ResultCheckerOrder $o) => $this->rcoIsSuccess($o),
                fn (ResultCheckerOrder $o) => $this->rcoIsTerminalFailure($o),
                fn (ResultCheckerOrder $o) => $this->rcoHasGateway($o),
            );

            if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
                return 'END Payment already confirmed for this order. Thank you!';
            }

            if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
                return 'END We are still confirming your payment. Please do not dial again — check your phone for the payment prompt.';
            }

            if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
                return 'END A system error occurred. Please dial again.';
            }

            $order = $creationResult['payable'];

            // Phase 5 fix: GatewayManager::collect() is type-hinted to Order
            // and would throw a TypeError (uncaught by the catch(\Exception)
            // below — TypeError extends \Error, not \Exception) for a
            // ResultCheckerOrder. This USSD-dial result-checker path was
            // therefore completely non-functional before this fix; use the
            // same genericPayment() entry point ResultCheckerCheckoutController
            // already uses correctly for this payable type.
            $email = $recipientPhone.'@ussd.xtra4u.local';
            $reference = 'XTRA4U-RC-'.strtoupper(uniqid('', true)).'-'.$order->id;
            $paymentResult = app(GatewayManager::class)->genericPayment([
                'email' => $email,
                'amount' => $totalPrice,
                'callback_url' => route('result-checkers.payment.callback', ['order' => $order->id]),
                'reference' => $reference,
                'metadata' => [
                    'phone_number' => $recipientPhone,
                ],
            ]);

            if ($paymentResult['reference'] ?? null) {
                $order->update([
                    'payment_reference' => $paymentResult['reference'],
                    'payment_gateway' => $paymentResult['gateway_name'] ?? null,
                ]);
            }

            if (! ($paymentResult['success'] ?? false)) {
                $order->update(['status' => 'failed']);
                Log::error('USSD ResultChecker Payment Failed', ['order_id' => $order->id, 'response' => $paymentResult]);

                return 'END Failed to initiate payment. Please try again later.';
            }

            return 'END Order placed successfully. Please check your phone for the payment prompt.';
        } catch (\Exception $e) {
            Log::error('USSD ResultChecker Order Error', ['error' => $e->getMessage()]);

            return 'END A system error occurred. Please try again.';
        }
    }
}
