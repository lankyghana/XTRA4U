<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUssdPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard('admin')->user() ?: Auth::user();

        return Gate::forUser($user)->allows('update', $this->route('ussd_plan'));
    }

    public function rules(): array
    {
        $planId = $this->route('ussd_plan')?->id;

        return array_merge(StoreUssdPlanRequest::sharedRules(), [
            'extension_code' => [
                'required', 'string', 'regex:/^\d{1,10}$/',
                Rule::unique('ussd_plans', 'extension_code')->ignore($planId),
            ],
        ]);
    }

    public function messages(): array
    {
        return StoreUssdPlanRequest::sharedMessages();
    }
}
