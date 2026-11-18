<?php

namespace App\Http\Requests\DeveloperApi;

use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'quote_reference' => ['required', 'string', 'max:40'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'payer_network' => ['nullable', 'string', 'in:MTN,TELECEL,AIRTELTIGO'],
        ];

        // Your own reference for the order. Sandbox-only: live requests keep ignoring unknown fields exactly
        // as before (the environment comes from the API key, never from the request).
        if ($this->attributes->get('api.environment') === 'sandbox') {
            $rules['client_reference'] = ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/ -]+$/'];
        }

        return $rules;
    }

    public function idempotencyKey(): string
    {
        $key = trim((string) $this->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $key)) {
            throw new ApiException(ApiError::IDEMPOTENCY_KEY_REQUIRED, 'A valid Idempotency-Key header (max 100 chars: letters, digits, . _ : -) is required.', 400);
        }

        return $key;
    }
}
