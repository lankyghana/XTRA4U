<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Requests\DeveloperApi\CreateQuoteRequest;
use App\Http\Resources\DeveloperApi\QuoteResource;
use App\Services\DeveloperApi\QuoteService;
use App\Support\DeveloperApi\ApiEnvironment;
use App\Support\DeveloperApi\ApiResponse;

class QuoteController extends ApiController
{
    public function store(CreateQuoteRequest $request, QuoteService $quotes)
    {
        // The environment is the API key's, never the request's. A sandbox quote is stored as such and
        // can only be read back by sandbox keys; nothing financial is created in either environment.
        $quote = $quotes->create($this->application($request), $request->validated('product_id'), $request->validated('recipient_phone'), ApiEnvironment::of($request));

        return ApiResponse::success((new QuoteResource($quote))->resolve(), 'Quote created successfully', [], 201);
    }
}
