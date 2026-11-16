<?php

namespace App\Http\Requests\DeveloperApi;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    /** Authorization is enforced by the api.key / api.scope middleware. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'network' => ['nullable', 'string', 'max:40'],
            'service' => ['nullable', 'string', 'max:40'],
            'vendor' => ['nullable', 'string', 'max:40'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'q' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:price,-price,name'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
