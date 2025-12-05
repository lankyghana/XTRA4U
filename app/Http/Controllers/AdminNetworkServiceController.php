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
            'is_active' => ['sometimes', 'boolean'],
            'image' => $this->imageValidationRules(false),
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $this->secureImageUpload(
                $request->file('image'),
                'network-services',
                'public'
            );
        }

        NetworkService::create([
            'name' => $validated['name'],
            'category' => $validated['category'],
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
            'is_active' => ['sometimes', 'boolean'],
            'image' => $this->imageValidationRules(false),
        ]);

        $data = [
            'name' => $validated['name'],
            'category' => $validated['category'],
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
