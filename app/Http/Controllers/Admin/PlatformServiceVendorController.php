<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Vendor;
use App\Support\PlatformServiceVendor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformServiceVendorController extends Controller
{
    public function update(Request $request)
    {
        $categories = PlatformServiceVendor::categories();

        $validated = $request->validate([
            'vendor' => ['nullable', 'array'],
            'vendor.*' => ['nullable', 'integer'],
        ]);

        $vendorInput = $validated['vendor'] ?? [];

        // Validate every non-empty selection server-side: it must be an
        // approved vendor. Never trust a vendor id from the browser as-is —
        // a stale dropdown could reference a vendor deleted or unapproved
        // since the page was rendered.
        $errors = [];
        foreach ($categories as $category) {
            $vendorId = $vendorInput[$category] ?? null;

            if ($vendorId === null || $vendorId === '') {
                continue;
            }

            $isValid = Vendor::where('id', (int) $vendorId)
                ->where('is_approved', true)
                ->exists();

            if (! $isValid) {
                $errors["vendor.{$category}"] = 'Select a valid, approved vendor for this service.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($categories as $category) {
            $vendorId = $vendorInput[$category] ?? null;
            PlatformServiceVendor::setVendorFor($category, $vendorId !== null && $vendorId !== '' ? (int) $vendorId : null);
        }

        Setting::clearCache();

        return redirect()->route('admin.settings.platform-service-vendors')
            ->with('success', 'Platform service vendor assignments updated successfully.');
    }
}
