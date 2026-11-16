<?php

namespace App\Http\Requests\Developers;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'in:marketplace,vendor'],
            'vendor_code' => ['required_if:type,vendor', 'nullable', 'string', 'max:40'],
        ];
    }
}
