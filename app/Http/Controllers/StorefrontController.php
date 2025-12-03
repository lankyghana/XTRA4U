<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class StorefrontController extends Controller
{
	public function index()
	{
		return view('storefront.index');
	}

	public function showVendorStore(Vendor $vendor)
	{
		$vendor->load(['products' => function ($query) {
			$query->where('is_active', true);
		}]);

		$categoryConfig = config('storefront.categories', []);
		$defaultCategory = config('storefront.default_category')
			?? (array_key_first($categoryConfig) ?? 'data');

		$products = $vendor->products->map(function ($product) use ($defaultCategory) {
			$metadata = $this->decodeDescription($product->description);
			$product->structured_metadata = $metadata;
			$product->display_metadata = $metadata;
			$product->display_category = $metadata['category'] ?? $defaultCategory;
			$product->display_service = $metadata['service'] ?? $metadata['network'] ?? $product->name;
			return $product;
		});

		$services = $this->buildServicePayload($products, $defaultCategory);
		$categories = $this->buildGlobalCategoryList($services, $categoryConfig, $defaultCategory);

		return view('vendor_store', [
			'vendor' => $vendor,
			'services' => $services,
			'categories' => $categories,
		]);
	}

	private function decodeDescription(?string $value): array
	{
		if (! $value) {
			return [];
		}

		$decoded = json_decode($value, true);

		return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
			? $decoded
			: [];
	}

	private function buildServicePayload(Collection $products, string $defaultCategory): Collection
	{
		$grouped = [];
		
		// Fetch all network services with images for quick lookup
		$networkServices = NetworkService::active()
			->whereNotNull('image_path')
			->get()
			->keyBy(function ($service) {
				return strtolower($service->name);
			});

		foreach ($products as $product) {
			$meta = $product->structured_metadata ?? [];
			$category = $meta['category'] ?? $defaultCategory;
			$serviceName = $product->display_service;
			$serviceKey = md5($category . '::' . $serviceName);

			$package = [
				'id' => $product->id,
				'name' => $product->name,
				'price' => (float) $product->price,
				'size' => $meta['size'] ?? null,
				'validity' => $meta['validity'] ?? null,
				'tag' => $meta['tag'] ?? $meta['promo'] ?? null,
				'notes' => $meta['notes'] ?? $product->description,
			];

			if (! isset($grouped[$serviceKey])) {
				// Look up the network service image
				$networkService = $networkServices->get(strtolower($serviceName));
				$logoUrl = $networkService ? $networkService->image_url : null;
				
				$grouped[$serviceKey] = [
					'key' => $serviceKey,
					'name' => $serviceName,
					'category' => $category,
					'logo' => $logoUrl,
					'packages' => [],
				];
			}

			$grouped[$serviceKey]['packages'][] = $package;
		}

		return collect($grouped)->values();
	}

	private function buildGlobalCategoryList(Collection $services, array $categoryConfig, string $defaultCategory): Collection
	{
		$categoryMeta = collect($categoryConfig);
		$serviceCounts = $services
			->groupBy('category')
			->map(fn ($group) => $group->sum(fn ($service) => count($service['packages'])));

		$globalCategories = $categoryMeta->map(function ($meta, $categoryKey) use ($serviceCounts) {
			$label = $meta['label'] ?? Str::title(str_replace(['-', '_'], ' ', $categoryKey));
			$description = $meta['description'] ?? 'Explore services in the ' . Str::lower($label) . ' category.';

			return [
				'id' => $categoryKey,
				'value' => $categoryKey,
				'label' => $label,
				'description' => $description,
				'serviceCount' => $serviceCounts->get($categoryKey, 0),
			];
		})->values();

		$vendorOnlyCategories = $services
			->pluck('category')
			->unique()
			->reject(fn ($category) => $categoryMeta->has($category));

		$vendorExtras = $vendorOnlyCategories->map(function ($categoryKey) use ($serviceCounts) {
			$label = Str::title(str_replace(['-', '_'], ' ', $categoryKey));
			return [
				'id' => $categoryKey,
				'value' => $categoryKey,
				'label' => $label,
				'description' => 'Explore services in the ' . Str::lower($label) . ' category.',
				'serviceCount' => $serviceCounts->get($categoryKey, 0),
			];
		});

		$combined = $globalCategories->concat($vendorExtras);

		if ($combined->isEmpty()) {
			$label = Str::title(str_replace(['-', '_'], ' ', $defaultCategory));
			$combined = collect([
				[
					'id' => $defaultCategory,
					'value' => $defaultCategory,
					'label' => $label,
					'description' => 'Select a category to explore packages.',
					'serviceCount' => 0,
				],
			]);
		}

		return $combined->values();
	}
}
