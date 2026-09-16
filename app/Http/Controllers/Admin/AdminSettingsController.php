<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Vendor;
use App\Models\VendorTier;
use App\Models\VendorTierHistory;
use App\Services\VendorTierQualificationService;
use App\Support\DeliveryStatus;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function __construct(private readonly VendorTierQualificationService $qualificationService) {}

    /**
     * Single consolidated settings page: service availability, delivery status,
     * vendor approval contact, vendor tiers, tier promotions, and tier history —
     * all shown as tabs on one page.
     */
    public function index(Request $request)
    {
        $tab = $this->resolveTab($request);

        $categoryConfig = config('storefront.categories', []);
        $statuses = ServiceAvailability::statuses();
        $availabilityMessage = ServiceAvailability::message();
        $openCount = collect($statuses)->filter()->count();
        $totalCount = count($statuses);

        $deliveryActive = DeliveryStatus::isActive();
        $deliveryLevel = DeliveryStatus::level();
        $deliveryMessage = DeliveryStatus::message();

        $vendorApprovalSettings = Setting::getGroup('vendor_approval');

        $platformServiceAssignments = PlatformServiceVendor::assignments();
        $platformServiceVendorChoices = collect(PlatformServiceVendor::categories())
            ->mapWithKeys(fn (string $category) => [$category => PlatformServiceVendor::eligibleVendors($category)]);

        $tiers = VendorTier::withCount('vendors')
            ->with('rules')
            ->ordered()
            ->get();

        $eligible = Vendor::where('is_tier_eligible', true)
            ->with(['tier', 'eligibleTier'])
            ->latest('tier_qualified_at')
            ->paginate(30, ['*'], 'promotions_page');

        $eligible->getCollection()->transform(function (Vendor $vendor) {
            $vendor->performance = $this->qualificationService->computePerformance($vendor);

            return $vendor;
        });

        $histories = VendorTierHistory::with(['vendor', 'previousTier', 'newTier', 'admin'])
            ->latest()
            ->paginate(50, ['*'], 'history_page');

        return view('admin.settings.index', compact(
            'tab',
            'categoryConfig', 'statuses', 'availabilityMessage', 'openCount', 'totalCount',
            'deliveryActive', 'deliveryLevel', 'deliveryMessage',
            'vendorApprovalSettings',
            'platformServiceAssignments', 'platformServiceVendorChoices',
            'tiers',
            'eligible',
            'histories',
        ));
    }

    private function resolveTab(Request $request): string
    {
        return match (true) {
            $request->routeIs('admin.settings.delivery-status') => 'delivery-status',
            $request->routeIs('admin.settings.vendor-approval') => 'vendor-approval',
            $request->routeIs('admin.settings.platform-service-vendors') => 'platform-service-vendors',
            $request->routeIs('admin.vendor-tiers.index') => 'vendor-tiers',
            $request->routeIs('admin.vendor-tier-promotions.index') => 'vendor-tier-promotions',
            $request->routeIs('admin.vendor-tier-history.index') => 'vendor-tier-history',
            default => 'service-availability',
        };
    }
}
