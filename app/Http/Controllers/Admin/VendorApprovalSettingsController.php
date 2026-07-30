<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class VendorApprovalSettingsController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'vendor_approval_contact_number' => 'required|string|max:20',
        ]);

        Setting::set('vendor_approval_contact_number', $validated['vendor_approval_contact_number'], 'vendor_approval');
        Setting::clearCache();

        return redirect()->route('admin.settings.vendor-approval')
            ->with('success', 'Vendor approval contact number updated successfully.');
    }
}
