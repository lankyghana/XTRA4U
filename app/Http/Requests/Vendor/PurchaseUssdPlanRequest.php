<?php

namespace App\Http\Requests\Vendor;

use App\Models\UssdSubscription;
use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PurchaseUssdPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendor = Auth::guard('vendor')->user();

        return $vendor instanceof Vendor
            && Gate::forUser($vendor)->allows('purchase', UssdSubscription::class);
    }

    /**
     * Note what is absent: vendor_id. It is derived solely from the
     * authenticated session and is never accepted from the request body.
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:ussd_plans,id'],
            'payer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\s-]+$/'],
            'network' => ['nullable', 'string', 'max:20', 'alpha_dash'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.exists' => 'The selected plan does not exist.',
            'payer_phone.regex' => 'The payment phone number contains invalid characters.',
        ];
    }
}
