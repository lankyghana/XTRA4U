<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorResultCheckerSettingsController extends Controller
{
    public function index()
    {
        $vendor = $this->resolveVendor();

        $services = NetworkService::resultsChecker()
            ->whereNotNull('base_price')
            ->where('base_price', '>', 0)
            ->orderBy('name')
            ->get();

        $settings = VendorResultCheckerSetting::query()
            ->where('vendor_id', $vendor->id)
            ->get()
            ->keyBy('service_id');

        return view('vendor.result_checkers.index', [
            'vendor' => $vendor,
            'services' => $services,
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $vendor = $this->resolveVendor();

        // Only services the admin has priced are open to vendor configuration.
        $services = NetworkService::resultsChecker()
            ->whereNotNull('base_price')
            ->where('base_price', '>', 0)
            ->get();

        $rules = ['settings' => ['array']];
        foreach ($services as $service) {
            $rules["settings.{$service->id}.profit_amount"] = ['nullable', 'numeric', 'min:0'];
            $rules["settings.{$service->id}.is_active"] = ['nullable'];
        }

        $validated = $request->validate($rules);

        foreach ($services as $service) {
            $profit = data_get($validated, "settings.{$service->id}.profit_amount", 0);
            $isActive = $request->boolean("settings.{$service->id}.is_active");

            VendorResultCheckerSetting::updateOrCreate(
                [
                    'vendor_id' => $vendor->id,
                    'service_id' => $service->id,
                ],
                [
                    'profit_amount' => (float) $profit,
                    'is_active' => $isActive,
                ]
            );
        }

        return redirect()
            ->route('vendor.result-checkers.index')
            ->with('success', 'Result checker settings updated.');
    }

    private function resolveVendor(): Vendor
    {
        $vendorGuardUser = Auth::guard('vendor')->user();

        if (! $vendorGuardUser instanceof Vendor) {
            abort(403, 'Vendor account required.');
        }

        return $vendorGuardUser;
    }
}
