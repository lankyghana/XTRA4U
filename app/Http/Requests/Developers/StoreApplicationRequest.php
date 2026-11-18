<?php

namespace App\Http\Requests\Developers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Name and description only. There is deliberately NO owner/vendor field: the application always
 * belongs to the signed-in vendor, and anything else a client posts is ignored (validated() omits it).
 */
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
        ];
    }
}
