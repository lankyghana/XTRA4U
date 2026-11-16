<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Requests\DeveloperApi\ListProductsRequest;
use App\Services\DeveloperApi\PublicCatalogService;
use App\Support\DeveloperApi\ApiResponse;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    public function __construct(private readonly PublicCatalogService $catalog) {}

    public function networks(Request $request)
    {
        return ApiResponse::success($this->catalog->networks($this->application($request)), 'Networks retrieved successfully');
    }

    public function services()
    {
        return ApiResponse::success($this->catalog->services(), 'Services retrieved successfully');
    }

    public function products(ListProductsRequest $request)
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $offers = $this->catalog->offers($this->application($request), $filters);
        $total = $offers->count();

        return ApiResponse::success($offers->forPage($page, $perPage)->values(), 'Products retrieved successfully', [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function product(Request $request, string $offer)
    {
        $found = $this->catalog->find($this->application($request), $offer);

        abort_if($found === null, 404);

        return ApiResponse::success($found, 'Product retrieved successfully');
    }
}
