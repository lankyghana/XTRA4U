<?php

namespace App\Http\Requests\Developers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:191'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(config('developer_api.webhooks.events'))],
        ];
    }
}
