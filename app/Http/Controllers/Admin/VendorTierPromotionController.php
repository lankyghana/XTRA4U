<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\VendorTierQualificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorTierPromotionController extends Controller
{
    public function __construct(private readonly VendorTierQualificationService $qualificationService) {}

    public function index()
    {
        $eligible = Vendor::where('is_tier_eligible', true)
            ->with(['tier', 'eligibleTier'])
            ->latest('tier_qualified_at')
            ->paginate(30);

        // Attach performance snapshot for display
        $eligible->getCollection()->transform(function (Vendor $vendor) {
            $vendor->performance = $this->qualificationService->computePerformance($vendor);
            return $vendor;
        });

        return view('admin.vendor-tier-promotions.index', compact('eligible'));
    }

    public function approve(Request $request, Vendor $vendor)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        if (! $vendor->is_tier_eligible) {
            return back()->withErrors(['error' => 'This vendor is not eligible for promotion.']);
        }

        $admin = Auth::guard('admin')->user();
        $promoted = $this->qualificationService->approvePromotion(
            $vendor,
            $admin->id,
            $request->input('notes')
        );

        if (! $promoted) {
            return back()->withErrors(['error' => 'Promotion could not be applied.']);
        }

        $vendor->refresh();

        return back()->with('success', "Promoted {$vendor->name} to {$vendor->tier->name}.");
    }

    public function reject(Request $request, Vendor $vendor)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        if (! $vendor->is_tier_eligible) {
            return back()->withErrors(['error' => 'This vendor has no pending promotion.']);
        }

        $admin = Auth::guard('admin')->user();
        $this->qualificationService->rejectPromotion(
            $vendor,
            $admin->id,
            $request->input('notes')
        );

        return back()->with('success', "Promotion for {$vendor->name} was rejected.");
    }
}
