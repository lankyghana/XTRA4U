<?php

namespace App\Http\Controllers;

use App\Models\AfaRegistration;
use App\Models\Vendor;
use App\Services\AffiliateChainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorAfaController extends Controller
{
    /**
     * Get the authenticated vendor
     */
    protected function vendor(): Vendor
    {
        return Auth::guard('vendor')->user();
    }

    /**
     * Display the AFA registrations dashboard
     */
    public function index(Request $request)
    {
        $vendor = $this->vendor();

        // Show registrations where vendor is either:
        // - Reseller (reseller_vendor_id = me): Reseller who sold the service
        // - Provider (vendor_id = me) ONLY for direct registrations (non-reseller orders)
        $baseQuery = AfaRegistration::query()
            ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
            ->where(function ($q) use ($vendor) {
                $q->where(function ($q2) use ($vendor) {
                    $q2->where('is_reseller_order', true)
                        ->where('reseller_vendor_id', $vendor->id);
                })->orWhere(function ($q2) use ($vendor) {
                    $q2->where(function ($q3) {
                        $q3->whereNull('is_reseller_order')
                            ->orWhere('is_reseller_order', false);
                    })->where('vendor_id', $vendor->id);
                });
            });

        $query = clone $baseQuery;

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by name, phone, or reference
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%")
                  ->orWhere('id_number', 'like', "%{$search}%");
            });
        }

        $registrations = $query->orderBy('created_at', 'desc')->paginate(15);

        // Get counts for tabs
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', AfaRegistration::STATUS_PENDING)->count(),
            'processing' => (clone $baseQuery)->where('status', AfaRegistration::STATUS_PROCESSING)->count(),
            'completed' => (clone $baseQuery)->where('status', AfaRegistration::STATUS_COMPLETED)->count(),
            'rejected' => (clone $baseQuery)->where('status', AfaRegistration::STATUS_REJECTED)->count(),
        ];

        return view('vendor.afa.index', [
            'vendor' => $vendor,
            'registrations' => $registrations,
            'counts' => $counts,
            'currentStatus' => $request->status ?? 'all',
            'search' => $request->search,
        ]);
    }

    /**
     * Show a single AFA registration
     */
    public function show(AfaRegistration $registration)
    {
        $vendor = $this->vendor();

        // Authorization:
        // - Reseller orders: only reseller can view
        // - Direct orders: only provider can view
        $isResellerOrder = (bool) $registration->is_reseller_order;
        $canView = $isResellerOrder
            ? ((int) $registration->reseller_vendor_id === (int) $vendor->id)
            : ((int) $registration->vendor_id === (int) $vendor->id);

        if (!$canView) {
            abort(403, 'Unauthorized');
        }

        return view('vendor.afa.show', [
            'vendor' => $vendor,
            'registration' => $registration,
        ]);
    }

    /**
     * Update registration status
     */
    public function updateStatus(Request $request, AfaRegistration $registration)
    {
        $vendor = $this->vendor();

        // Fulfillment ownership rule:
        // - Reseller orders: reseller fulfills and can update
        // - Direct orders: provider fulfills and can update
        $isResellerOrder = (bool) $registration->is_reseller_order;
        $canManage = $isResellerOrder
            ? ((int) $registration->reseller_vendor_id === (int) $vendor->id)
            : ((int) $registration->vendor_id === (int) $vendor->id);

        if (!$canManage) {
            // Friendly message instead of harsh 403 error
            $errorMessage = $isResellerOrder
                ? 'This registration is a reseller sale. Only the reseller can manage its fulfillment status.'
                : 'You do not have permission to manage this registration.';
            
            return back()->with('error', $errorMessage);
        }

        $request->validate([
            'status' => ['required', 'in:processing,approved,completed,rejected'],
            'notes' => ['nullable', 'string', 'max:500'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $status = $request->status;

        switch ($status) {
            case 'processing':
                $registration->markAsProcessing();
                $message = 'Registration marked as processing.';
                break;
            case 'approved':
                $registration->markAsApproved($request->notes);
                $message = 'Registration approved successfully.';
                break;
            case 'completed':
                $registration->markAsCompleted($request->notes);
                $message = 'Registration completed successfully.';
                break;
            case 'rejected':
                $registration->markAsRejected($request->rejection_reason);
                $message = 'Registration rejected.';
                break;
            default:
                $message = 'Status updated.';
        }

        return back()->with('success', $message);
    }

    /**
     * Bulk update status
     */
    public function bulkUpdate(Request $request)
    {
        $vendor = $this->vendor();

        $request->validate([
            'registration_ids' => ['required', 'array'],
            'registration_ids.*' => ['integer', 'exists:afa_registrations,id'],
            'status' => ['required', 'in:processing,approved,completed'],
        ]);

        // Fulfillment ownership rule (reversed): only the provider (vendor_id) can bulk-update statuses.
        $updated = AfaRegistration::query()
            ->whereIn('id', $request->registration_ids)
            ->where('vendor_id', $vendor->id)
            ->update(['status' => $request->status]);

        return back()->with('success', "{$updated} registration(s) updated successfully.");
    }

    /**
     * AFA Settings - Set pricing
     */
    public function settings()
    {
        $vendor = $this->vendor();

        // Only show AFA providers that this vendor is linked to as an affiliate.
        // Today that means: the affiliate parent vendor referenced by affiliate_vendor_id.
        $availableAfaVendors = collect();
        if (!is_null($vendor->affiliate_vendor_id)) {
            $availableAfaVendors = Vendor::where('id', $vendor->affiliate_vendor_id)
                ->where('is_approved', true)
                ->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('afa_enabled', true)->where('afa_price', '>', 0);
                    })->orWhere(function ($qq) {
                        $qq->where('afa_reseller_enabled', true)->where('afa_selling_price', '>', 0);
                    });
                })
                ->select('id', 'name', 'vendor_code', 'afa_price', 'afa_selling_price', 'afa_enabled', 'afa_reseller_enabled')
                ->get();
        }
        
        // Get current source vendor if reseller is enabled
        $sourceVendor = null;
        if ($vendor->afa_source_vendor_id) {
            $sourceVendor = Vendor::find($vendor->afa_source_vendor_id);
        }

        return view('vendor.afa.settings', [
            'vendor' => $vendor,
            'availableAfaVendors' => $availableAfaVendors,
            'sourceVendor' => $sourceVendor,
        ]);
    }

    /**
     * Update AFA settings
     */
    public function updateSettings(Request $request)
    {
        $vendor = $this->vendor();
        
        // Determine if vendor is setting up as direct provider or reseller
        $mode = $request->input('afa_mode', 'direct');

        if ($mode === 'direct') {
            // Direct AFA provider settings
            $request->validate([
                'afa_price' => ['required', 'numeric', 'min:1', 'max:10000'],
                'afa_enabled' => ['sometimes', 'boolean'],
            ]);

            $vendor->update([
                'afa_price' => $request->afa_price,
                'afa_enabled' => $request->boolean('afa_enabled', false),
                // Disable reseller mode when in direct mode
                'afa_reseller_enabled' => false,
                'afa_source_vendor_id' => null,
                'afa_base_price' => 0,
                'afa_markup' => 0,
                'afa_selling_price' => 0,
            ]);

            return back()->with('success', 'AFA settings updated successfully.');
        } else {
            // Reseller AFA settings
            $request->validate([
                'afa_source_vendor_id' => ['required', 'exists:vendors,id'],
                'afa_markup' => ['required', 'numeric', 'min:0', 'max:10000'],
            ]);

            // Enforce business rule: vendor can only resell AFA from their affiliate parent.
            if (is_null($vendor->affiliate_vendor_id)) {
                return back()->with('error', 'You must join an affiliate (parent vendor) before selecting an AFA provider.');
            }

            if ((int) $request->afa_source_vendor_id !== (int) $vendor->affiliate_vendor_id) {
                return back()->with('error', 'You can only select your affiliate parent as your AFA provider.');
            }
            
            // Get the source vendor's base price
            $sourceVendor = Vendor::find($request->afa_source_vendor_id);
            $chainService = new AffiliateChainService();
            $basePrice = $sourceVendor ? $chainService->getVendorAfaSellingPrice($sourceVendor) : null;
            if (!$sourceVendor || !$basePrice || $basePrice <= 0) {
                return back()->with('error', 'Selected vendor does not offer AFA service.');
            }

            // Ensure there is a root provider behind this source (provider or reseller source).
            $rootProvider = $chainService->resolveRootAfaProviderFromSeller($sourceVendor);
            if (!$rootProvider) {
                return back()->with('error', 'Selected vendor does not have an active AFA provider in its affiliate chain.');
            }

            $markup = $request->afa_markup;
            $sellingPrice = $basePrice + $markup;

            $vendor->update([
                // Disable direct mode when in reseller mode
                'afa_enabled' => false,
                'afa_price' => 0,
                // Enable reseller mode
                'afa_reseller_enabled' => true,
                'afa_source_vendor_id' => $request->afa_source_vendor_id,
                'afa_base_price' => $basePrice,
                'afa_markup' => $markup,
                'afa_selling_price' => $sellingPrice,
            ]);

            return back()->with('success', 'AFA Reseller settings updated successfully. Your selling price is GH₵' . number_format($sellingPrice, 2));
        }
    }

    /**
     * Disable AFA reselling
     */
    public function disableReseller()
    {
        $vendor = $this->vendor();
        
        $vendor->update([
            'afa_reseller_enabled' => false,
            'afa_source_vendor_id' => null,
            'afa_base_price' => 0,
            'afa_markup' => 0,
            'afa_selling_price' => 0,
        ]);
        
        return back()->with('success', 'AFA Reseller mode disabled.');
    }

    /**
     * Get registration statistics
     */
    public function stats()
    {
        $vendor = $this->vendor();

        $stats = [
            'total_registrations' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)->count(),
            'pending_review' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->where('status', AfaRegistration::STATUS_PENDING)->count(),
            'total_earnings' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->sum('vendor_earning'),
            'this_month' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return response()->json($stats);
    }
}
