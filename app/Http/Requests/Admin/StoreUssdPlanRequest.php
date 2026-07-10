<?php

namespace App\Http\Requests\Admin;

use App\Models\UssdPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreUssdPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard('admin')->user() ?: Auth::user();

        return Gate::forUser($user)->allows('create', UssdPlan::class);
    }

    public function rules(): array
    {
        return array_merge(self::sharedRules(), [
            // Unique across soft-deleted rows too, matching the database index:
            // a retired plan keeps its extension reserved so previously issued
            // USSD codes can never be re-minted for a different plan.
            'extension_code' => [
                'required', 'string', 'regex:/^\d{1,10}$/',
                Rule::unique('ussd_plans', 'extension_code'),
            ],
        ]);
    }

    /**
     * Shared with UpdateUssdPlanRequest; only extension_code uniqueness differs.
     */
    public static function sharedRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'included_sessions' => ['required', 'integer', 'min:1', 'max:10000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function sharedMessages(): array
    {
        return [
            'extension_code.regex' => 'The extension code must be digits only, e.g. 45.',
            'extension_code.unique' => 'That extension code is already reserved by another plan.',
        ];
    }

    public function messages(): array
    {
        return self::sharedMessages();
    }
}
