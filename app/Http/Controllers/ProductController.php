<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\Product;
use App\Models\ResellerProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
        $networkOptions = $this->getNetworkOptions();

        return view('product_create', compact('networkOptions'));
    }

    // Store new product
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'network' => $this->networkValidationRule(),
            'category' => $this->categoryValidationRule(),
            'size' => 'nullable|string|max:50',
            'validity' => 'nullable|string|max:50',
            'tag' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
        ]);

        $vendor = $this->resolveVendor();

        Product::create([
            'vendor_id' => $vendor->id,
            'name' => $validated['name'],
            'description' => $this->buildStructuredDescription($request, $validated['description'] ?? null),
            'price' => $validated['price'],
            'is_active' => true,
        ]);

        return redirect()->route('vendor.products.index')->with('success', 'Product created successfully.');
    }

    // Show edit product form
    public function edit($id)
    {
        $product = $this->findVendorProduct($id);
        $metadata = $this->decodeDescription($product->description);
        $networkOptions = $this->getNetworkOptions();

        return view('product_edit', compact('product', 'metadata', 'networkOptions'));
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = $this->findVendorProduct($id);
        $metadata = $this->decodeDescription($product->description);
        $existingNetwork = $metadata['network'] ?? null;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'network' => $this->networkValidationRule($existingNetwork),
            'category' => $this->categoryValidationRule(),
            'size' => 'nullable|string|max:50',
            'validity' => 'nullable|string|max:50',
            'tag' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $oldPrice = $product->price;
        $newPrice = $validated['price'];

        $product->update([
            'name' => $validated['name'],
            'price' => $newPrice,
            'description' => $this->buildStructuredDescription($request, $validated['description'] ?? null),
            'is_active' => $request->boolean('is_active', $product->is_active),
        ]);

        // Sync base_price for all resellers when owner changes price
        if ($oldPrice != $newPrice) {
            ResellerProduct::where('product_id', $product->id)
                ->get()
                ->each(function ($resellerProduct) use ($newPrice) {
                    $resellerProduct->update([
                        'base_price' => $newPrice,
                        // selling_price auto-calculated in model boot()
                    ]);
                });
        }

        return redirect()->route('vendor.products.index')->with('success', 'Product updated successfully.');
    }

    // Delete product
    public function destroy($id)
    {
        $product = $this->findVendorProduct($id);

        // ResellerProduct rows are removed by FK cascade (see migration).
        $product->delete();

        return redirect()->route('vendor.products.index')->with('success', 'Product deleted successfully.');
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

    protected function categoryValidationRule(): string
    {
        return 'nullable|string|in:' . implode(',', $this->availableCategoryKeys());
    }

    protected function availableCategoryKeys(): array
    {
        $keys = array_keys(config('storefront.categories', []));
        return $keys ?: ['data'];
    }

    protected function getNetworkOptions()
    {
        return NetworkService::active()
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    protected function networkValidationRule(?string $currentNetwork = null): string
    {
        $names = $this->getNetworkOptions()
            ->pluck('name')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if ($currentNetwork && ! in_array($currentNetwork, $names, true)) {
            $names[] = $currentNetwork;
        }

        if (empty($names)) {
            return 'nullable|string|max:60';
        }

        return 'nullable|string|in:' . implode(',', $names);
    }

    protected function defaultCategory(): string
    {
        $categories = config('storefront.categories', []);

        return config('storefront.default_category')
            ?? array_key_first($categories)
            ?? 'data';
    }

    protected function buildStructuredDescription(Request $request, ?string $plainDescription): ?string
    {
        $payload = [
            'network' => $request->input('network'),
            'category' => $request->input('category', $this->defaultCategory()),
            'size' => $request->input('size'),
            'validity' => $request->input('validity'),
            'tag' => $request->input('tag'),
            'notes' => $request->input('notes'),
            'description' => $plainDescription,
        ];

        $filtered = array_filter($payload, function ($value) {
            return ! is_null($value) && $value !== '';
        });

        if (empty($filtered)) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function decodeDescription(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }
}
