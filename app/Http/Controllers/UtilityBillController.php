<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Services\UtilityBills\Data\LookupResult;
use App\Services\UtilityBills\Exceptions\SaleNotAllowed;
use App\Services\UtilityBills\Exceptions\UtilityProviderException;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\UtilityBillAvailability;
use App\Services\UtilityBills\UtilityBillCheckoutService;
use App\Services\UtilityBills\UtilityBillLookupService;
use App\Support\PaymentVerificationState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Public Utility Bills flow (direct XTRA4U page and per-storefront page).
 *
 * Thin by design: provider HTTP lives in KingFlexyUtilityProvider, rules in the
 * UtilityBills services. The storefront vendor is the route-bound vendor of the
 * page the customer is on (or none on the direct page); a vendor id is never
 * read from the request body.
 */
class UtilityBillController extends Controller
{
    public function __construct(
        private UtilityBillAvailability $availability,
        private UtilityBillLookupService $lookups,
        private UtilityBillCheckoutService $checkout,
        private KingFlexyUtilityProvider $provider,
        private PaymentService $payments,
        private PaymentIntegrityGuard $integrity,
    ) {}

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function direct()
    {
        return $this->page(null);
    }

    public function store(Vendor $vendor)
    {
        abort_unless($vendor->is_approved, 404);

        return $this->page($vendor);
    }

    private function page(?Vendor $vendor)
    {
        if (! $this->availability->serviceOpen()) {
            return $this->closed($this->availability->closedMessage());
        }

        $billers = $this->availability->sellable();

        if ($billers->isEmpty()) {
            return $this->closed('Utility bills are temporarily unavailable. Please try again later.');
        }

        [$catalog] = $this->provider->catalogForDisplay();
        $scope = $vendor ? ['vendor' => $vendor->vendor_code] : [];

        return view('utility-bills.index', [
            'vendor' => $vendor,
            'billers' => $billers->map(fn ($row) => [
                'key' => $row['biller']->key,
                'label' => $row['biller']->label,
                'account_label' => $row['biller']->accountLabel,
                'requires_phone' => $row['biller']->requiresPhone,
                'lookup_by' => $row['biller']->lookupBy,
            ])->values(),
            'limits' => ['min' => $catalog?->minAmount, 'max' => $catalog?->maxAmount],
            'routes' => [
                'lookup' => $vendor ? route('storefront.utility-bills.lookup', $scope) : route('utility-bills.lookup'),
                'checkout' => $vendor ? route('storefront.utility-bills.checkout', $scope) : route('utility-bills.checkout'),
                'verify' => route('utility-bills.verify'),
            ],
            'requiresInlineMomo' => PaymentGatewayConfig::defaultCollectionRequiresPayerPhone(),
            'shopUrl' => $vendor ? route('storefront.vendor', ['vendor' => $vendor->vendor_code]) : route('services.utility-bills'),
        ]);
    }

    private function closed(string $message)
    {
        return response()->view('pages.services-closed', [
            'title' => 'Utility Bills Unavailable',
            'message' => $message,
            'backHref' => route('storefront.index'),
        ], 503);
    }

    // ------------------------------------------------------------------
    // Lookup / checkout (JSON)
    // ------------------------------------------------------------------

    public function lookupDirect(Request $request): JsonResponse
    {
        return $this->lookup($request, null);
    }

    public function lookupStore(Request $request, Vendor $vendor): JsonResponse
    {
        abort_unless($vendor->is_approved, 404);

        return $this->lookup($request, $vendor);
    }

    private function lookup(Request $request, ?Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'biller' => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'account' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $out = $this->lookups->lookup(
                $request->session()->getId(),
                $vendor?->id,
                $data['biller'],
                $data['account'] ?? null,
                $data['phone'] ?? null,
            );
        } catch (SaleNotAllowed $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (UtilityProviderException $e) {
            return response()->json(['success' => false, 'message' => $e->userMessage()], 502);
        }

        /** @var LookupResult $result */
        $result = $out['result'];
        $token = $out['token'];

        return response()->json([
            'success' => true,
            'token' => $token,
            'biller' => ['key' => $out['biller']->key, 'label' => $out['biller']->label, 'account_label' => $out['biller']->accountLabel],
            'account_name' => $result->accountName,
            'account_masked' => $result->accountNumber ? UtilityBillOrder::mask($result->accountNumber) : UtilityBillOrder::mask($out['input_account']),
            'bouquet' => $result->bouquet,
            // A negative provider amount_due is an account CREDIT, never "Amount due: -20".
            'amount_owing' => LookupResult::owing($result->amountDue),
            'account_credit' => LookupResult::credit($result->amountDue),
            'meters' => array_map(fn (array $m) => [
                'id' => $this->meterId($token, $m['meterNumber']),
                'name' => $m['name'],
                'meter_masked' => UtilityBillOrder::mask($m['meterNumber']),
                'amount_owing' => LookupResult::owing($m['outstanding']),
                'account_credit' => LookupResult::credit($m['outstanding']),
            ], $result->meters),
        ]);
    }

    public function checkoutDirect(Request $request): JsonResponse
    {
        return $this->placeOrder($request, null);
    }

    public function checkoutStore(Request $request, Vendor $vendor): JsonResponse
    {
        abort_unless($vendor->is_approved, 404);

        return $this->placeOrder($request, $vendor);
    }

    private function placeOrder(Request $request, ?Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'lookup_token' => ['required', 'string', 'size:40'],
            'selected_meter_id' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'string', 'max:12'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'payer_network' => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $sessionId = $request->session()->getId();

        // Translate the opaque meter id back to the meter number the PROVIDER returned.
        $meter = null;
        if (! empty($data['selected_meter_id'])) {
            $snapshot = $this->lookups->resolve($data['lookup_token'], $sessionId, $vendor?->id);
            foreach ($snapshot['result']['meters'] ?? [] as $candidate) {
                if (hash_equals($this->meterId($data['lookup_token'], $candidate['meterNumber']), $data['selected_meter_id'])) {
                    $meter = $candidate['meterNumber'];
                }
            }
        }

        try {
            $out = $this->checkout->checkout([
                'session_id' => $sessionId,
                'vendor_id' => $vendor?->id,
                'lookup_token' => $data['lookup_token'],
                'selected_meter' => $meter,
                'amount' => $data['amount'],
                'payer_phone' => $data['payer_phone'] ?? null,
                'payer_network' => $data['payer_network'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (SaleNotAllowed $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        /** @var UtilityBillOrder $u */
        $u = $out['order'];

        if ($out['outcome'] === UtilityBillCheckoutService::OUTCOME_ALREADY_PAID) {
            return response()->json(['success' => true, 'status' => 'success', 'message' => 'Payment already completed.', 'redirect' => $u->statusUrl()]);
        }

        if ($out['outcome'] === UtilityBillCheckoutService::OUTCOME_CONFIRMING) {
            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous payment. Please don't pay again yet.",
                'reference' => $u->order->payment_reference,
                'verify_url' => route('utility-bills.verify'),
                'redirect' => null,
            ]);
        }

        $init = $out['init'];
        $isSdkPopup = ! empty($init['checkout_config']);

        return response()->json([
            'success' => true,
            'message' => $init['message'] ?? 'Redirecting to payment.',
            'public_ref' => $u->public_ref,
            'reference' => $init['reference'] ?? null,
            'redirect' => $init['authorization_url'] ?? null,
            'flow_type' => $isSdkPopup ? $init['flow_type'] : null,
            'gateway_name' => $isSdkPopup ? ($init['gateway_name'] ?? null) : null,
            'checkout_config' => $isSdkPopup ? $init['checkout_config'] : null,
            'verify_url' => route('utility-bills.verify'),
            'status_url' => $u->statusUrl(),
        ]);
    }

    /**
     * Browser/popup payment confirmation. Same pipeline as CheckoutController::verify:
     * gateway verification against the order's own gateway, the central integrity
     * guard, then canonical PaymentService::completeOrder. The browser is never trusted.
     */
    public function verify(Request $request): JsonResponse
    {
        $reference = (string) $request->validate(['reference' => ['required', 'string', 'max:255']])['reference'];

        $order = Order::query()->where('payment_reference', $reference)->first();
        $utility = $order?->utilityBillOrder()->first();

        if (! $order || ! $utility) {
            return response()->json(['success' => false, 'message' => 'Order not found for reference.'], 404);
        }

        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return response()->json(['success' => true, 'status' => 'success', 'redirect' => $utility->statusUrl()]);
        }

        $verification = $this->payments->checkPaymentStatusForGateway($reference, $order->payment_gateway);
        $state = PaymentVerificationState::from($verification);

        if ($state === PaymentVerificationState::FAILED) {
            $order->update(['payment_status' => 'failed', 'status' => 'Failed']);

            return response()->json(['success' => true, 'status' => 'failed', 'message' => 'Payment failed.']);
        }

        if ($state !== PaymentVerificationState::SUCCESS) {
            return response()->json(['success' => true, 'status' => 'pending', 'message' => 'Payment pending. Please approve the Mobile Money prompt.']);
        }

        $integrity = $this->integrity->guard($order, $verification, $order->payment_gateway);

        if (! $integrity->passed) {
            return response()->json(['success' => true, 'status' => 'failed', 'message' => 'We could not confirm this payment. Please contact support.']);
        }

        $order->amount_paid = $integrity->confirmedAmount;
        $this->payments->completeOrder($order);

        return response()->json(['success' => true, 'status' => 'success', 'redirect' => $utility->statusUrl()]);
    }

    // ------------------------------------------------------------------
    // Customer status / receipt
    // ------------------------------------------------------------------

    public function status(string $token): Response
    {
        $u = $this->findByToken($token);

        return response()
            ->view('utility-bills.status', ['u' => $u, 'view' => $this->present($u)])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Local status only: the browser never talks to KiNG FLEXY. */
    public function poll(string $token): JsonResponse
    {
        $u = $this->findByToken($token);

        return response()->json($this->present($u))->header('Cache-Control', 'no-store, private');
    }

    private function findByToken(string $token): UtilityBillOrder
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return UtilityBillOrder::query()->with('order')->where('access_token', $token)->firstOrFail();
    }

    /** Customer-safe, privacy-masked view model. Never says "successful" before the provider completed. */
    private function present(UtilityBillOrder $u): array
    {
        $paymentStatus = $u->order->payment_status;
        $paid = in_array($paymentStatus, ['paid', 'completed'], true) && $u->order->allowsSettlement();

        if (! $paid) {
            $stage = $paymentStatus === 'failed' ? 'payment_failed' : 'awaiting_payment';
            $headline = $stage === 'payment_failed' ? 'Payment was not successful' : 'Waiting for your payment';
            $detail = $stage === 'payment_failed'
                ? 'No bill payment was made. You can start again.'
                : 'We are confirming your payment. This page updates automatically.';
        } elseif ($u->fulfillment_status === FulfillmentStatus::COMPLETED) {
            [$stage, $headline, $detail] = ['completed', 'Utility payment successful', 'Your bill payment has been completed.'];
        } elseif (in_array($u->fulfillment_status, [FulfillmentStatus::FAILED, FulfillmentStatus::PROVIDER_REFUNDED], true)) {
            [$stage, $headline, $detail] = ['needs_support', 'We could not complete your bill payment', 'Your payment is safe. Our team has been alerted and will resolve it. Please keep your reference.'];
        } elseif ($u->fulfillment_status === FulfillmentStatus::ATTENTION) {
            [$stage, $headline, $detail] = ['delayed', 'Payment received. Your bill payment is taking longer than usual', 'Our team is looking into it. Please keep your reference.'];
        } else {
            [$stage, $headline, $detail] = ['processing', 'Payment received', 'Your utility payment is being processed.'];
        }

        return [
            'stage' => $stage,
            'terminal' => in_array($stage, ['completed', 'needs_support', 'payment_failed'], true),
            'headline' => $headline,
            'detail' => $detail,
            'status_label' => $stage === 'completed' ? 'Completed' : ($paid ? FulfillmentStatus::label($u->fulfillment_status) : ucfirst(str_replace('_', ' ', $stage))),
            'reference' => $u->public_ref,
            'biller' => $u->biller_label,
            'account_masked' => $u->maskedAccount(),
            'account_name' => $u->account_name,
            'amount' => number_format((float) $u->bill_amount, 2),
            'currency' => $u->currency,
            'date' => ($u->fulfilled_at ?? $u->created_at)?->format('d M Y, H:i'),
        ];
    }

    private function meterId(string $token, string $meterNumber): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$meterNumber, (string) config('app.key')), 0, 32);
    }
}
