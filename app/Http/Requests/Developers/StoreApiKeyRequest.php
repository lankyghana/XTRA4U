<?php

namespace App\Http\Requests\Developers;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'environment' => ['required', 'in:sandbox,production'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(\App\Services\DeveloperApi\ApiKeyService::availableScopes()))],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('environment') !== 'production') {
                return;
            }

            $bad = array_intersect((array) $this->input('scopes', []), (array) config('developer_api.sandbox_only_scopes', []));
            if ($bad !== []) {
                $validator->errors()->add('scopes', 'Sandbox-only scopes ('.implode(', ', $bad).') can only be given to a test (sandbox) key.');
            }
        }];
    }
}
