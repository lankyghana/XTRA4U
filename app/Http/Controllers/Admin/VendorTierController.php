<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorTier;
use App\Models\VendorTierRule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VendorTierController extends Controller
{
    public function create()
    {
        $ruleKeys = VendorTierRule::supportedRuleKeys();
        $operators = VendorTierRule::supportedOperators();

        return view('admin.vendor-tiers.create', compact('ruleKeys', 'operators'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'description'    => 'nullable|string|max:500',
            'priority'       => 'required|integer|min:0|max:999',
            'discount_type'  => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => 'required|numeric|min:0|max:100',
            'is_active'      => 'boolean',
        ]);

        $data['slug'] = $this->uniqueSlug($request->input('name'));
        $data['is_active'] = $request->boolean('is_active');

        $tier = VendorTier::create($data);

        $this->syncRules($tier, $request->input('rules', []));

        return redirect()->route('admin.vendor-tiers.index')
            ->with('success', "Tier \"{$tier->name}\" created successfully.");
    }

    public function edit(VendorTier $vendorTier)
    {
        $vendorTier->load('rules');
        $ruleKeys = VendorTierRule::supportedRuleKeys();
        $operators = VendorTierRule::supportedOperators();

        return view('admin.vendor-tiers.edit', compact('vendorTier', 'ruleKeys', 'operators'));
    }

    public function update(Request $request, VendorTier $vendorTier)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'description'    => 'nullable|string|max:500',
            'priority'       => 'required|integer|min:0|max:999',
            'discount_type'  => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => 'required|numeric|min:0|max:100',
            'is_active'      => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $vendorTier->update($data);

        $this->syncRules($vendorTier, $request->input('rules', []));

        return redirect()->route('admin.vendor-tiers.index')
            ->with('success', "Tier \"{$vendorTier->name}\" updated successfully.");
    }

    public function destroy(VendorTier $vendorTier)
    {
        if ($vendorTier->vendors()->exists()) {
            return back()->withErrors(['error' => "Cannot delete \"{$vendorTier->name}\" — vendors are currently assigned to this tier."]);
        }

        if ($vendorTier->eligibleVendors()->exists()) {
            return back()->withErrors(['error' => "Cannot delete \"{$vendorTier->name}\" — vendors are eligible for this tier."]);
        }

        $name = $vendorTier->name;
        $vendorTier->delete();

        return redirect()->route('admin.vendor-tiers.index')
            ->with('success', "Tier \"{$name}\" deleted.");
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer|exists:vendor_tiers,id',
        ]);

        foreach ($request->input('order') as $priority => $tierId) {
            VendorTier::where('id', $tierId)->update(['priority' => $priority]);
        }

        return response()->json(['ok' => true]);
    }

    private function syncRules(VendorTier $tier, array $rules): void
    {
        $tier->rules()->delete();

        $validKeys = array_keys(VendorTierRule::supportedRuleKeys());
        $validOperators = VendorTierRule::supportedOperators();
        $seen = [];

        foreach ($rules as $rule) {
            $key = $rule['rule_key'] ?? null;
            $operator = $rule['operator'] ?? '>=';
            $value = $rule['value'] ?? null;

            if (! $key || ! in_array($key, $validKeys) || ! in_array($operator, $validOperators) || $value === null || $value === '') {
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            VendorTierRule::create([
                'tier_id'  => $tier->id,
                'rule_key' => $key,
                'operator' => $operator,
                'value'    => (float) $value,
            ]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (VendorTier::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
