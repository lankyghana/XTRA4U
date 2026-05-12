<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\ResultCheckerPricingTier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminResultCheckerPricingTierController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'network_service_id' => 'required|exists:network_services,id',
            'min_quantity' => 'required|integer|min:1',
            'max_quantity' => 'nullable|integer|min:' . $request->input('min_quantity', 1),
            'price' => 'required|numeric|min:0',
        ]);

        $serviceId = $validated['network_service_id'];
        $min = $validated['min_quantity'];
        $max = $validated['max_quantity'];

        // Validate for overlapping ranges
        $query = ResultCheckerPricingTier::where('network_service_id', $serviceId);

        $query->where(function ($q) use ($min, $max) {
            // New range is completely within an existing range
            $q->where('min_quantity', '<=', $min)->where('max_quantity', '>=', $max);
        })->orWhere(function ($q) use ($min, $max) {
            // New range contains an existing range
            $q->where('min_quantity', '>=', $min)->where('max_quantity', '<=', $max);
        })->orWhere(function ($q) use ($min, $max) {
            // New range overlaps with the start of an existing range
            $q->where('min_quantity', '<=', $min)->where('max_quantity', '>=', $min);
        })->orWhere(function ($q) use ($min, $max) {
            // New range overlaps with the end of an existing range
            $q->where('min_quantity', '<=', $max)->where('max_quantity', '>=', $max);
        });

        if ($query->exists()) {
            return back()->withErrors(['price' => 'The quantity range overlaps with an existing pricing tier.']);
        }

        ResultCheckerPricingTier::create($validated);

        return redirect()->route('admin.result-checkers.pins.index', ['service_id' => $serviceId])
            ->with('success', 'Pricing tier created successfully.');
    }

    public function destroy(ResultCheckerPricingTier $tier)
    {
        $serviceId = $tier->network_service_id;
        $tier->delete();

        return redirect()->route('admin.result-checkers.pins.index', ['service_id' => $serviceId])
            ->with('success', 'Pricing tier deleted successfully.');
    }
}
