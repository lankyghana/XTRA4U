<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\DeliveryStatus;
use Illuminate\Http\Request;

class DeliveryStatusController extends Controller
{
    public function index()
    {
        $active = DeliveryStatus::isActive();
        $level = DeliveryStatus::level();
        $message = DeliveryStatus::message();

        return view('admin.settings.delivery-status', compact('active', 'level', 'message'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'active' => ['nullable'],
            'level' => ['required', 'string', 'in:'.implode(',', DeliveryStatus::LEVELS)],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        DeliveryStatus::update(
            $request->boolean('active'),
            $validated['level'],
            $validated['message'] ?? null,
        );

        Setting::clearCache();

        return redirect()->route('admin.settings.delivery-status')
            ->with('success', 'Delivery status notice updated successfully.');
    }
}
