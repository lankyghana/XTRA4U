<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    // Show create product form
    public function create()
    {
        return view('product_create');
    }

    // Store new product
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
        ]);

        Product::create([
            'vendor_id' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'is_active' => true,
        ]);

        return redirect()->route('vendor.dashboard')->with('success', 'Product created successfully.');
    }

    // Show edit product form
    public function edit($id)
    {
        $product = Product::findOrFail($id);
        return view('product_edit', compact('product'));
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return redirect()->route('vendor.dashboard')->with('success', 'Product updated successfully.');
    }
}
