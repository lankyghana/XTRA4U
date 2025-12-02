<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index()
    {
        $vendor = $this->resolveVendor();
        $products = Product::where('vendor_id', $vendor->id)
            ->latest()
            ->paginate(12);

        return view('vendor.products.index', compact('vendor', 'products'));
    }

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

        $vendor = $this->resolveVendor();

        Product::create([
            'vendor_id' => $vendor->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'is_active' => true,
        ]);

        return redirect()->route('vendor.products.index')->with('success', 'Product created successfully.');
    }

    // Show edit product form
    public function edit($id)
    {
        $product = $this->findVendorProduct($id);
        return view('product_edit', compact('product'));
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = $this->findVendorProduct($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return redirect()->route('vendor.products.index')->with('success', 'Product updated successfully.');
    }

    protected function resolveVendor()
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();
        $vendor = $vendorGuardUser
            ?: ($user instanceof \App\Models\Vendor ? $user : ($user->vendor ?? null));

        abort_unless($vendor, 403, 'Vendor account required.');

        return $vendor;
    }

    protected function findVendorProduct($id): Product
    {
        $vendor = $this->resolveVendor();
        return Product::where('vendor_id', $vendor->id)->findOrFail($id);
    }
}
