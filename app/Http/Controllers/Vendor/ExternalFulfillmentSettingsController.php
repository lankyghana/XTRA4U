<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExternalFulfillmentSettingsController extends Controller
{
    public function index()
    {
        $vendor = $this->resolveVendor();

        abort_if(! is_null($vendor->affiliate_vendor_id), 403, 'External fulfillment settings are only available for root vendors.');

        $settings = VendorSetting::getGroupForVendor($vendor->id, 'external_fulfillment');

        if (! empty($settings['external_fulfillment_token'])) {
            $settings['external_fulfillment_token_masked'] = '••••••••';
        }

        return view('vendor.settings.external-fulfillment', [
            'vendor' => $vendor,
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $vendor = $this->resolveVendor();

        abort_if(! is_null($vendor->affiliate_vendor_id), 403, 'External fulfillment settings are only available for root vendors.');

        $validated = $request->validate([
            'external_fulfillment_enabled' => 'nullable|boolean',
            'external_fulfillment_token' => 'nullable|string|max:255',
            'external_fulfillment_timeout_seconds' => 'nullable|integer|min:1|max:120',
        ]);

        $enabled = (bool) $request->boolean('external_fulfillment_enabled');

        if ($enabled) {
            $existingToken = trim((string) VendorSetting::getForVendor($vendor->id, 'external_fulfillment_token', ''));
            if (! $request->filled('external_fulfillment_token') && $existingToken === '') {
                return back()
                    ->withErrors(['external_fulfillment_token' => 'API token is required when enabling external fulfillment.'])
                    ->withInput();
            }
        }

        VendorSetting::setForVendor($vendor->id, 'external_fulfillment_enabled', $enabled ? '1' : '0', 'external_fulfillment');
        VendorSetting::setForVendor(
            $vendor->id,
            'external_fulfillment_timeout_seconds',
            (string) ((int) ($request->input('external_fulfillment_timeout_seconds') ?? 10)),
            'external_fulfillment'
        );

        if ($request->filled('external_fulfillment_token')) {
            VendorSetting::setForVendor(
                $vendor->id,
                'external_fulfillment_token',
                $request->input('external_fulfillment_token'),
                'external_fulfillment'
            );
        }

        return redirect()->route('vendor.settings.external-fulfillment')
            ->with('success', 'External fulfillment settings updated successfully.');
    }

    private function resolveVendor(): Vendor
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();

        $vendor = $vendorGuardUser instanceof Vendor
            ? $vendorGuardUser
            : ($user instanceof Vendor ? $user : null);

        abort_unless($vendor, 403, 'Vendor account required.');

        return $vendor;
    }
}
