<?php

namespace App\Http\Controllers;

use App\Mail\AfaOrderPlacedMail;
use App\Models\AfaRegistration;
use App\Models\Vendor;
use App\Models\PaymentGatewayConfig;
use App\Services\PaymentService;
use App\Services\AfaPaymentService;
use App\Services\AffiliateChainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AfaRegistrationController extends Controller
{
    protected PaymentService $paymentService;
    protected AfaPaymentService $afaPaymentService;

    public function __construct(PaymentService $paymentService, AfaPaymentService $afaPaymentService)
    {
        $this->paymentService = $paymentService;
        $this->afaPaymentService = $afaPaymentService;
    }

    /**
     * Show the AFA registration form for a vendor
     */
    public function show(Vendor $vendor)
    {
        // Check if vendor has AFA service enabled (direct or reseller)
        $afaPrice = null;
        $isReseller = false;
        $sourceVendor = null;
        
        if ($vendor->afa_enabled && $vendor->afa_price > 0) {
            // Direct AFA service
            $afaPrice = $vendor->afa_price;
        } elseif ($vendor->afa_reseller_enabled && $vendor->afa_selling_price > 0 && $vendor->afa_source_vendor_id) {
            // Reseller AFA service
            $afaPrice = $vendor->afa_selling_price;
            $isReseller = true;
            $chainService = new AffiliateChainService();
            $sourceVendor = $chainService->resolveRootAfaProviderFromSeller($vendor);

            if (!$sourceVendor) {
                return redirect()->route('storefront.vendor', $vendor->vendor_code)
                    ->with('error', 'AFA Registration is currently not available.');
            }
        }
        
        if (!$afaPrice || $afaPrice <= 0) {
            return redirect()->route('storefront.vendor', $vendor->vendor_code)
                ->with('error', 'AFA Registration is not available from this vendor.');
        }

        return view('afa.register', [
            'vendor' => $vendor,
            'price' => $afaPrice,
            'isReseller' => $isReseller,
            'sourceVendor' => $sourceVendor,
            'regions' => AfaRegistration::getRegions(),
            'idTypes' => AfaRegistration::getIdTypes(),
            // Inline/API gateways like BulkClix require payer phone/network before initiation.
            'requiresInlineMomo' => PaymentGatewayConfig::defaultCollectionRequiresPayerPhone(),
        ]);
    }

    /**
     * Process the AFA registration form submission
     */
    public function store(Request $request, Vendor $vendor)
    {
        $requiresInlineMomo = PaymentGatewayConfig::defaultCollectionRequiresPayerPhone();

        // Build validation rules based on ID type
        $idType = $request->input('id_type');
        $idNumberRules = ['required', 'string', 'max:50'];
        
        // Add specific validation based on ID type
        if ($idType === 'ghana_card') {
            // Ghana Card format: GHA-XXXXXXXXX-X (total 15 characters)
            $idNumberRules[] = 'regex:/^GHA-[0-9]{9}-[0-9]$/i';
        } elseif ($idType === 'drivers_license') {
            // Driver's License: Alphanumeric, typically 9-12 characters
            $idNumberRules[] = 'regex:/^[A-Z0-9]{9,12}$/i';
        } elseif ($idType === 'voters_id') {
            // Voter's ID: Typically 10 digits
            $idNumberRules[] = 'regex:/^[0-9]{10}$/';
        }
        
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'id_type' => ['required', 'string', 'in:ghana_card,drivers_license,voters_id'],
            'id_number' => $idNumberRules,
            'date_of_birth' => ['required', 'date', 'before:today'],
            'phone_number' => ['required', 'string', 'min:10', 'max:15'],
            'location' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:255'],
			// Inline/API gateways like BulkClix require payer phone/network before initiation.
			'payer_phone' => $requiresInlineMomo
				? ['required', 'string', 'min:10', 'max:15']
				: ['nullable', 'string', 'min:10', 'max:15'],
			'payer_network' => $requiresInlineMomo
				? ['required', 'string', 'in:MTN,TELECEL,AIRTELTIGO']
				: ['nullable', 'string', 'in:MTN,TELECEL,AIRTELTIGO'],
        ], [
            'id_number.regex' => $this->getIdNumberErrorMessage($idType),
        ]);

        // Determine if this is a direct or reseller AFA order
        $isResellerOrder = false;
        $afaPrice = null;
        $sourceVendorId = null;
        $basePrice = 0;
        $markupPrice = 0;
        $affiliateChainSnapshot = null;
        
        if ($vendor->afa_enabled && $vendor->afa_price > 0) {
            // Direct AFA order
            $afaPrice = $vendor->afa_price;
            $sourceVendorId = $vendor->id;
        } elseif ($vendor->afa_reseller_enabled && $vendor->afa_selling_price > 0 && $vendor->afa_source_vendor_id) {
            // Reseller AFA order
            $chainService = new AffiliateChainService();
            $payout = $chainService->computeAfaPayoutFromSeller($vendor);
            if (!($payout['ok'] ?? false)) {
                return back()->with('error', 'AFA Registration is currently not available.');
            }

            $isResellerOrder = true;
            $afaPrice = $vendor->afa_selling_price;
            $sourceVendorId = (int) $payout['owner_vendor_id'];
            $basePrice = (float) $payout['base_amount'];
            $markupPrice = (float) $vendor->afa_markup;
            $affiliateChainSnapshot = $payout['snapshot'] ?? null;

            // Guard against misconfiguration (markups/base not matching selling price)
            $expected = (float) ($payout['expected_amount'] ?? 0);
            if ($expected > 0 && abs($expected - (float) $afaPrice) > 0.01) {
                return back()->with('error', 'AFA pricing is misconfigured for this vendor. Please try again later.');
            }
        }

        if (!$afaPrice || $afaPrice <= 0) {
            return back()->with('error', 'AFA Registration is not available from this vendor.');
        }

        // Calculate commissions (2% platform fee)
        $commissionRate = 0.02;
        
        if ($isResellerOrder) {
            // Snapshot-based multi-level logic: platform commission is per-portion.
            // For stored summary fields, we keep the owner + immediate seller amounts.
            $ownerCommission = round($basePrice * $commissionRate, 2);
            $resellerCommission = round($markupPrice * $commissionRate, 2);
            $platformCommission = $ownerCommission + $resellerCommission;
            $vendorEarning = round($basePrice - $ownerCommission, 2); // Root provider earning
            $resellerEarning = round($markupPrice - $resellerCommission, 2); // Immediate seller earning

            if (is_array($affiliateChainSnapshot) && !empty($affiliateChainSnapshot)) {
                // Recompute totals from snapshot to include all resellers.
                $platformCommission = 0.0;
                $vendorEarning = 0.0;
                $resellerEarning = 0.0;

                foreach ($affiliateChainSnapshot as $idx => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $role = (string) ($entry['role'] ?? '');
                    $portion = $role === 'owner'
                        ? (float) ($entry['amount'] ?? 0)
                        : (float) ($entry['markup'] ?? 0);

                    if ($portion <= 0) {
                        continue;
                    }

                    $commission = round($portion * $commissionRate, 2);
                    $earning = round($portion - $commission, 2);
                    $platformCommission = round($platformCommission + $commission, 2);

                    if ($role === 'owner') {
                        $vendorEarning = $earning;
                    }

                    if ($role === 'reseller' && (int) ($entry['vendor_id'] ?? 0) === (int) $vendor->id) {
                        $resellerEarning = $earning;
                    }
                }
            }
        } else {
            // Direct order - single vendor
            $platformCommission = round($afaPrice * $commissionRate, 2);
            $vendorEarning = $afaPrice - $platformCommission;
            $resellerEarning = 0;
        }

        try {
            DB::beginTransaction();

            // Create the AFA registration
            $registration = AfaRegistration::create([
                'vendor_id' => $sourceVendorId, // The source vendor who processes the registration
                'reseller_vendor_id' => $isResellerOrder ? $vendor->id : null, // The reseller who sold it
                'full_name' => $validated['full_name'],
                'id_type' => $validated['id_type'],
                'id_number' => $validated['id_number'],
                'date_of_birth' => $validated['date_of_birth'],
                'phone_number' => $validated['phone_number'],
                'location' => $validated['location'],
                'region' => $validated['region'],
                'occupation' => $validated['occupation'] ?? null,
                'amount' => $afaPrice,
                'vendor_price' => $isResellerOrder ? $basePrice : $afaPrice,
                'platform_commission' => $platformCommission,
                'vendor_earning' => $vendorEarning,
                'reseller_earning' => $resellerEarning,
                'affiliate_chain_snapshot' => $affiliateChainSnapshot,
                'is_reseller_order' => $isResellerOrder,
                'status' => AfaRegistration::STATUS_PENDING,
                'payment_status' => AfaRegistration::PAYMENT_PENDING,
                'reference' => AfaRegistration::generateReference(),
            ]);

            DB::commit();

            // Use the selling vendor's email for payment (reseller or direct).
            // Some vendor accounts may not have an email set; payment gateways like Paystack require one.
            $paymentEmail = $vendor->email ?: 'noreply@xtra4u.com';

            // Initiate payment using the active default payment gateway
            $paymentResult = $this->paymentService->initiateGenericPayment(
                $paymentEmail,
                $afaPrice,
                route('afa.callback'),
                $registration->reference,
                [
                    'type' => 'afa_registration',
                    'registration_id' => $registration->id,
                    'vendor_id' => $sourceVendorId,
                    'reseller_vendor_id' => $isResellerOrder ? $vendor->id : null,
                    'customer_name' => $registration->full_name,
                    'is_reseller_order' => $isResellerOrder,
                    // Needed for inline/API collections like BulkClix.
                    'phone_number' => $validated['payer_phone'] ?? null,
					'network' => $validated['payer_network'] ?? null,
					'payer_network' => $validated['payer_network'] ?? null,
                ]
            );

            if ($paymentResult['success']) {
                // Update registration with payment reference
                $registration->update([
                    'payment_reference' => $paymentResult['reference'],
                    'payment_gateway' => $paymentResult['gateway_name'] ?? PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)?->gateway_name,
                ]);

                // AJAX flow (inline gateways like BulkClix): return payload so frontend can poll.
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => $paymentResult['message'] ?? 'Payment initiated. Please approve the MoMo prompt.',
                        'reference' => $paymentResult['reference'] ?? null,
                        'redirect' => $paymentResult['authorization_url'] ?? null,
                        'verify_url' => route('afa.verify'),
                        'success_url' => route('afa.success', ['reference' => $registration->reference]),
                    ]);
                }

                // Non-AJAX fallback.
                if (!empty($paymentResult['authorization_url'])) {
                    return redirect($paymentResult['authorization_url']);
                }

                return redirect()->route('afa.register', $vendor->vendor_code)
                    ->with('success', 'Payment initiated. Please approve the MoMo prompt, then check status.');
            }

            // Payment initiation failed
            $registration->update(['status' => AfaRegistration::STATUS_CANCELLED]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment could not be initiated: ' . ($paymentResult['message'] ?? 'Unknown error'),
                ], 422);
            }

            return back()->with('error', 'Payment could not be initiated: ' . ($paymentResult['message'] ?? 'Unknown error'));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AFA Registration Error', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'An error occurred. Please try again.',
                ], 500);
            }

            return back()->with('error', 'An error occurred. Please try again.');
        }
    }

    /**
     * Verify AFA payment for inline polling flows.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $paymentReference = (string) $validated['reference'];

        $registration = AfaRegistration::where('payment_reference', $paymentReference)
            ->with('vendor')
            ->first();

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found for reference.',
            ], 404);
        }

        // Idempotency: if already marked paid, return success immediately.
        if ($registration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Payment completed.',
                'redirect' => route('afa.success', ['reference' => $registration->reference]),
            ]);
        }

        $verification = $this->paymentService->checkPaymentStatus($paymentReference);
        if (! ($verification['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $verification['message'] ?? 'Verification failed.',
            ], 422);
        }

        $paymentStatus = strtolower((string) data_get($verification, 'data.status', ''));
        if ($paymentStatus === 'pending' || $paymentStatus === 'unknown' || $paymentStatus === '') {
            return response()->json([
                'success' => true,
                'status' => 'pending',
                'message' => 'Payment pending. Please approve the MoMo prompt.',
            ]);
        }

        $isSuccess = in_array($paymentStatus, ['success', 'successful', 'completed', 'paid'], true);
        if (! $isSuccess) {
            $registration->update([
                'payment_status' => AfaRegistration::PAYMENT_FAILED,
                'status' => AfaRegistration::STATUS_CANCELLED,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'failed',
                'message' => 'Payment failed.',
            ]);
        }

        $this->afaPaymentService->completeRegistration($registration);

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Payment completed.',
            'redirect' => route('afa.success', ['reference' => $registration->reference]),
        ]);
    }

    /**
     * Handle payment callback
     */
    public function paymentCallback(Request $request)
    {
        // Payment gateways use different parameter names.
        // - Paystack: reference or trxref
        // - Flutterwave: tx_ref
        // - Moolre: externalref (and sometimes externalRef)
        $reference = $request->get('reference')
            ?? $request->get('trxref')
            ?? $request->get('tx_ref')
            ?? $request->get('externalref')
            ?? $request->get('externalRef');

        if (!$reference) {
            return redirect()->route('storefront.index')->with('error', 'Invalid payment reference.');
        }

        // Find the registration by payment reference
        $registration = AfaRegistration::where('payment_reference', $reference)->first();

        if (!$registration) {
            return redirect()->route('storefront.index')->with('error', 'Registration not found.');
        }

        // Idempotency: if already marked paid, do not re-verify, re-credit, or re-notify.
        if ($registration->payment_status === AfaRegistration::PAYMENT_COMPLETED) {
            return redirect()->route('afa.success', $registration->reference);
        }

        // Verify payment with the active default gateway
        $verificationResult = $this->paymentService->checkPaymentStatus($reference);

        if ($verificationResult['success']) {
            $paymentStatus = strtolower((string) data_get($verificationResult, 'data.status', ''));

            if ($paymentStatus === 'pending' || $paymentStatus === 'unknown') {
                return redirect()->route('storefront.vendor', $registration->vendor->vendor_code)
                    ->with('success', 'Payment pending. Please approve the MoMo prompt or try again shortly.');
            }

            if ($paymentStatus && ! in_array($paymentStatus, ['success', 'successful', 'completed'], true)) {
                // Payment verification returned a terminal non-success state.
                if ($registration->payment_status !== AfaRegistration::PAYMENT_COMPLETED) {
                    $registration->update([
                        'payment_status' => AfaRegistration::PAYMENT_FAILED,
                        'status' => AfaRegistration::STATUS_CANCELLED,
                    ]);
                }

                return redirect()->route('storefront.vendor', $registration->vendor->vendor_code)
                    ->with('error', 'Payment failed. Please try again.');
            }

            // Use dedicated AFA payment service to handle completion
            $this->afaPaymentService->completeRegistration($registration);

            return redirect()->route('afa.success', $registration->reference);
        }

        // Payment verification failed
        // Do not overwrite a completed payment if the callback is replayed.
        if ($registration->payment_status !== AfaRegistration::PAYMENT_COMPLETED) {
            $registration->update([
                'payment_status' => AfaRegistration::PAYMENT_FAILED,
                'status' => AfaRegistration::STATUS_CANCELLED,
            ]);
        }

        return redirect()->route('storefront.vendor', $registration->vendor->vendor_code)
            ->with('error', 'Payment verification failed. Please try again.');
    }

    /**
     * Show success page
     */
    public function success(string $reference)
    {
        $registration = AfaRegistration::where('reference', $reference)
            ->with('vendor')
            ->firstOrFail();

        return view('afa.success', [
            'registration' => $registration,
        ]);
    }

    /**
     * Check AFA registration status
     */
    public function checkStatus(Request $request)
    {
        $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $registration = AfaRegistration::where('reference', $request->reference)
            ->orWhere('phone_number', $request->reference)
            ->with('vendor:id,name')
            ->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'No registration found with this reference or phone number.',
            ]);
        }

        return response()->json([
            'success' => true,
            'registration' => [
                'reference' => $registration->reference,
                'full_name' => $registration->full_name,
                'status' => $registration->status,
                'status_label' => $registration->status_label,
                'status_color' => $registration->status_color,
                'vendor_name' => $registration->vendor->name ?? 'N/A',
                'amount' => number_format($registration->amount, 2),
                'date' => $registration->created_at->format('M d, Y'),
                'rejection_reason' => $registration->rejection_reason,
            ],
        ]);
    }

    /**
     * Get custom error message for ID number validation based on type
     */
    protected function getIdNumberErrorMessage(?string $idType): string
    {
        return match ($idType) {
            'ghana_card' => 'Ghana Card number must be in format: GHA-123456789-0',
            'drivers_license' => 'Driver\'s License must be 9-12 alphanumeric characters',
            'voters_id' => 'Voter\'s ID must be exactly 10 digits',
            default => 'Please enter a valid ID number',
        };
    }
}
