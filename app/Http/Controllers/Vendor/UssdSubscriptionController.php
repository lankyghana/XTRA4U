<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\PurchaseUssdPlanRequest;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Services\Ussd\UssdConfig;
use App\Services\Ussd\UssdSubscriptionPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UssdSubscriptionController extends Controller
{
    public function __construct(
        private readonly UssdSubscriptionPurchaseService $purchases,
        private readonly UssdConfig $config,
    ) {}

    /**
     * The vendor's USSD subscription page: current plan, dialled code, session
     * usage, expiry, and the plans available to buy, renew, or upgrade to.
     */
    public function index()
    {
        $vendor = $this->authenticatedVendor();

        // Gate::forUser is required: these routes authenticate on the `vendor`
        // guard, but Gate resolves its user from the default (`web`) guard.
        Gate::forUser($vendor)->authorize('viewAny', UssdSubscription::class);

        $current = $vendor->currentUssdSubscription()->with('plan')->first();

        // Only ever the authenticated vendor's own rows.
        $history = UssdSubscription::forVendor($vendor->id)
            ->with('plan')
            ->whereNotNull('activated_at')
            ->latest('activated_at')
            ->limit(10)
            ->get();

        return view('vendor.ussd.subscription', [
            'vendor' => $vendor,
            'current' => $current,
            'history' => $history,
            'plans' => UssdPlan::active()->ordered()->get(),
            'baseCode' => $this->config->baseCode(),
            'ussdEnabled' => $this->config->isEnabled(),
        ]);
    }

    /**
     * Begin a plan purchase for the *authenticated* vendor.
     *
     * The vendor is taken from the session guard only. A plan id is the sole
     * thing the request may influence, and an inactive plan is rejected by
     * UssdSubscriptionPurchaseService before any payment is initiated.
     */
    public function purchase(PurchaseUssdPlanRequest $request)
    {
        $vendor = $this->authenticatedVendor();

        try {
            $this->purchases->assertGatewayReady();
        } catch (RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        }

        // Implicit binding is not used here: the plan id arrives in the body, and
        // we want a soft-deleted or missing plan to fail the same way as an
        // inactive one rather than 404 mid-checkout.
        $plan = UssdPlan::find($request->integer('plan_id'));

        if (! $plan || ! $plan->isPurchasable()) {
            return $this->fail($request, 'This plan is not available for purchase.');
        }

        $result = $this->purchases->initiate($vendor, $plan, [
            'payer_phone' => $request->input('payer_phone'),
            'network' => $request->input('network'),
        ]);

        if (! ($result['success'] ?? false)) {
            return $this->fail($request, $result['message'] ?? 'Failed to start the purchase.');
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'reference' => $result['reference'],
                'authorization_url' => $result['authorization_url'],
                'flow_type' => $result['flow_type'],
                'gateway_name' => $result['gateway_name'],
                'payment_type' => UssdSubscriptionPurchaseService::PURPOSE,
            ]);
        }

        if (! empty($result['authorization_url'])) {
            return redirect()->away($result['authorization_url']);
        }

        return redirect()->route('vendor.ussd.subscription.index')
            ->with('status', 'Check your phone to approve the payment prompt.');
    }

    /**
     * Public gateway callback. Deliberately unauthenticated — the gateway is
     * not carrying the vendor's session — and safe because it does nothing but
     * re-verify the reference against the gateway. The reference itself grants
     * no privilege: it can only ever activate the subscription it belongs to.
     */
    public function callback(Request $request, string $reference)
    {
        // The reference is our own UUID or a gateway-issued id; reject anything
        // that could not plausibly be either before touching the database.
        if (! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $reference)) {
            Log::warning('USSD subscription callback: malformed reference', ['reference' => $reference]);

            return $this->callbackResponse($request, false, 'Invalid payment reference.');
        }

        $result = $this->purchases->verifyAndActivate($reference);

        return $this->callbackResponse($request, $result['success'], $result['message']);
    }

    private function callbackResponse(Request $request, bool $success, string $message)
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => $success, 'message' => $message]);
        }

        // The gateway redirects here without a vendor session, so this lands on
        // the subscription page and the `vendor.approved` middleware sends an
        // unauthenticated visitor to the login form. Either way the flash shows.
        return redirect()->route('vendor.ussd.subscription.index')
            ->with($success ? 'status' : 'error', $message);
    }

    private function fail(Request $request, string $message)
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => $message], 400);
        }

        return back()->withErrors(['error' => $message]);
    }

    private function authenticatedVendor(): Vendor
    {
        $vendor = Auth::guard('vendor')->user();

        abort_unless($vendor instanceof Vendor, 403, 'Vendor account required.');

        return $vendor;
    }
}
