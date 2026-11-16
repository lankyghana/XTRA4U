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
        return [
            'quote_reference' => ['required', 'string', 'max:40'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'payer_network' => ['nullable', 'string', 'in:MTN,TELECEL,AIRTELTIGO'],
        ];
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
