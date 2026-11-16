<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Requests\DeveloperApi\CreateQuoteRequest;
use App\Http\Resources\DeveloperApi\QuoteResource;
use App\Services\DeveloperApi\QuoteService;
use App\Support\DeveloperApi\ApiResponse;

class QuoteController extends ApiController
{
    public function store(CreateQuoteRequest $request, QuoteService $quotes)
    {
        $quote = $quotes->create($this->application($request), $request->validated('product_id'), $request->validated('recipient_phone'));

        return ApiResponse::success((new QuoteResource($quote))->resolve(), 'Quote created successfully', [], 201);
    }
}
