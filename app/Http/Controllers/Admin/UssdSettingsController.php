<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Vendor;
use Illuminate\Http\Request;

class UssdSettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::getGroup('ussd');
        $vendors  = Vendor::where('is_approved', true)->orderBy('business_name')->get(['id', 'business_name', 'vendor_code']);

        return view('admin.settings.ussd', compact('settings', 'vendors'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'ussd_enabled'          => 'sometimes|boolean',
            'ussd_service_code'     => 'nullable|string|max:50',
            'ussd_welcome_message'  => 'required|string|max:100',
            'ussd_support_number'   => 'nullable|string|max:20',
            'ussd_default_vendor_id'=> 'nullable|exists:vendors,id',
        ]);

        $map = [
            'ussd_enabled'           => $request->has('ussd_enabled') ? '1' : '0',
            'ussd_service_code'      => $request->input('ussd_service_code', ''),
            'ussd_welcome_message'   => $request->input('ussd_welcome_message'),
            'ussd_support_number'    => $request->input('ussd_support_number', ''),
            'ussd_default_vendor_id' => $request->input('ussd_default_vendor_id', ''),
        ];

        foreach ($map as $key => $value) {
            Setting::set($key, $value, 'ussd');
        }

        Setting::clearCache();

        return redirect()->route('admin.settings.ussd')
            ->with('success', 'USSD settings updated successfully.');
    }
}
