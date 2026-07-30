<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ServiceAvailability;
use Illuminate\Http\Request;

class ServiceAvailabilityController extends Controller
{
    public function update(Request $request)
    {
        $categories = ServiceAvailability::categories();

        $validated = $request->validate([
            'open' => ['nullable', 'array'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        // Checkboxes only appear in the payload when checked, so an absent key
        // means the admin closed that category.
        $openInput = $validated['open'] ?? [];

        foreach ($categories as $category) {
            ServiceAvailability::setOpen($category, array_key_exists($category, $openInput));
        }

        ServiceAvailability::setMessage($validated['message'] ?? null);

        Setting::clearCache();

        return redirect()->route('admin.settings.service-availability')
            ->with('success', 'Service availability updated successfully.');
    }
}
