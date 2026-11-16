<?php

namespace App\Http\Requests\DeveloperApi;

use Illuminate\Foundation\Http\FormRequest;

class CreateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'max:40'],
            'recipient_phone' => ['required', 'string', 'max:20'],
        ];
    }
}
