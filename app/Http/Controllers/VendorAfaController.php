<?php

namespace App\Http\Controllers;

use App\Models\AfaRegistration;
use App\Models\Vendor;
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
        
        $query = AfaRegistration::where('vendor_id', $vendor->id)
            ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED);

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
            'all' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)->count(),
            'pending' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->where('status', AfaRegistration::STATUS_PENDING)->count(),
            'processing' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->where('status', AfaRegistration::STATUS_PROCESSING)->count(),
            'completed' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->where('status', AfaRegistration::STATUS_COMPLETED)->count(),
            'rejected' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->where('status', AfaRegistration::STATUS_REJECTED)->count(),
        ];

        // Get stats for the dashboard
        $stats = [
            'pending' => $counts['pending'],
            'processing' => $counts['processing'],
            'completed' => $counts['completed'],
            'total_earnings' => AfaRegistration::where('vendor_id', $vendor->id)
                ->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)
                ->where('status', AfaRegistration::STATUS_COMPLETED)
                ->sum('vendor_earning'),
        ];

        return view('vendor.afa.index', [
            'vendor' => $vendor,
            'registrations' => $registrations,
            'stats' => $stats,
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

        // Ensure vendor owns this registration
        if ($registration->vendor_id !== $vendor->id) {
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

        // Ensure vendor owns this registration
        if ($registration->vendor_id !== $vendor->id) {
            abort(403, 'Unauthorized');
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

        $updated = AfaRegistration::where('vendor_id', $vendor->id)
            ->whereIn('id', $request->registration_ids)
            ->update(['status' => $request->status]);

        return back()->with('success', "{$updated} registration(s) updated successfully.");
    }

    /**
     * AFA Settings - Set pricing
     */
    public function settings()
    {
        $vendor = $this->vendor();
        
        // Get vendors who offer AFA for reselling (exclude self)
        $availableAfaVendors = Vendor::where('id', '!=', $vendor->id)
            ->where('is_approved', true)
            ->where('afa_enabled', true)
            ->where('afa_price', '>', 0)
            ->select('id', 'name', 'vendor_code', 'afa_price')
            ->get();
        
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
            
            // Get the source vendor's base price
            $sourceVendor = Vendor::find($request->afa_source_vendor_id);
            if (!$sourceVendor || !$sourceVendor->afa_enabled || $sourceVendor->afa_price <= 0) {
                return back()->with('error', 'Selected vendor does not offer AFA service.');
            }
            
            $basePrice = $sourceVendor->afa_price;
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
