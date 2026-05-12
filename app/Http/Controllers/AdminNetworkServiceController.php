<?php

namespace App\Http\Controllers;

use App\Http\Traits\SecureFileUpload;
use App\Models\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminNetworkServiceController extends Controller
{
    use SecureFileUpload;

    public function index()
    {
        $services = NetworkService::orderBy('category')->orderBy('name')->get();
        $categories = $this->categoryOptions();

        return view('admin.network_services.index', compact('services', 'categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', Rule::in($this->categoryOptions())],
            'service_type' => ['sometimes', 'string', Rule::in(['general', 'results_checker'])],
            'slug' => ['nullable', 'string', 'max:100', 'unique:network_services,slug'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => $this->imageValidationRules(false),
        ]);

        $exists = NetworkService::where('name', $validated['name'])
            ->where('category', $validated['category'])
            ->exists();

        if ($exists) {
            return back()
                ->withErrors(['name' => 'A service with this name already exists in the selected category.'])
                ->withInput();
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $this->secureImageUpload(
                $request->file('image'),
                'network-services',
                'public'
            );
        }

        // Auto-generate slug if not provided
        $slug = $validated['slug'] ?? \Illuminate\Support\Str::slug($validated['name']);

        NetworkService::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'category' => $validated['category'],
            'service_type' => $validated['service_type'] ?? 'general',
            'base_price' => $validated['base_price'] ?? 0,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'image_path' => $imagePath,
        ]);

        return redirect()->route('admin.network-services.index')->with('success', 'Network / service added.');
    }

    public function edit(NetworkService $network_service)
    {
        $categories = $this->categoryOptions();

        return view('admin.network_services.edit', [
            'service' => $network_service,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, NetworkService $network_service)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', Rule::in($this->categoryOptions())],
            'service_type' => ['sometimes', 'string', Rule::in(['general', 'results_checker'])],
            'slug' => ['nullable', 'string', 'max:100', Rule::unique('network_services', 'slug')->ignore($network_service->id)],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => $this->imageValidationRules(false),
        ]);

        $exists = NetworkService::where('name', $validated['name'])
            ->where('category', $validated['category'])
            ->where('id', '!=', $network_service->id)
            ->exists();

        if ($exists) {
            return back()
                ->withErrors(['name' => 'A service with this name already exists in the selected category.'])
                ->withInput();
        }

        $data = [
            'name' => $validated['name'],
            'category' => $validated['category'],
            'service_type' => $validated['service_type'] ?? $network_service->service_type,
            'slug' => $validated['slug'] ?? $network_service->slug,
            'base_price' => $validated['base_price'] ?? $network_service->base_price,
            'description' => $validated['description'] ?? $network_service->description,
            'is_active' => $request->boolean('is_active', true),
        ];

        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($network_service->image_path) {
                Storage::disk('public')->delete($network_service->image_path);
            }
            $data['image_path'] = $this->secureImageUpload(
                $request->file('image'),
                'network-services',
                'public'
            );
        }

        $network_service->update($data);

        return redirect()->route('admin.network-services.index')->with('success', 'Network / service updated.');
    }

    public function destroy(NetworkService $network_service)
    {
        // Delete image if exists
        if ($network_service->image_path) {
            Storage::disk('public')->delete($network_service->image_path);
        }
        
        $network_service->delete();

        return redirect()->route('admin.network-services.index')->with('success', 'Network / service removed.');
    }

    protected function categoryOptions(): array
    {
        $categoryConfig = config('storefront.categories', []);

        return array_keys($categoryConfig ?: ['data' => []]);
    }
}
