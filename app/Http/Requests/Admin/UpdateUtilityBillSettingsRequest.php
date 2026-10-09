<?php

namespace App\Http\Requests\Admin;

use App\Services\UtilityBills\VendorCommission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Server-side validation of the Utility Bills admin form. Route middleware
 * (admin.only) is the authorization boundary; this validates the values.
 * Commission uses exact decimal strings and technical upper bounds.
 */
class UpdateUtilityBillSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:255'],
            'billers' => ['present', 'array', 'max:50'],
            'billers.*.is_enabled' => ['required', 'boolean'],
            'billers.*.commission_type' => ['required', 'in:'.VendorCommission::TYPE_PERCENTAGE.','.VendorCommission::TYPE_FIXED],
            'billers.*.commission_value' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'billers.*.commission_value.regex' => 'Commission must be a non-negative number with at most 4 decimals.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Checkbox-style booleans arrive as "1"/"0"/absent.
        $billers = [];
        foreach ((array) $this->input('billers', []) as $key => $row) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,40}$/', $key) || ! is_array($row)) {
                continue;
            }
            $billers[$key] = [
                'is_enabled' => filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'commission_type' => $row['commission_type'] ?? null,
                'commission_value' => isset($row['commission_value']) ? trim((string) $row['commission_value']) : null,
            ];
        }

        $this->merge([
            'enabled' => filter_var($this->input('enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'billers' => $billers,
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('billers', []) as $key => $row) {
                $value = $row['commission_value'] ?? null;
                if (! is_string($value) || ! is_numeric($value)) {
                    continue;
                }

                $max = ($row['commission_type'] ?? null) === VendorCommission::TYPE_FIXED
                    ? VendorCommission::MAX_FIXED
                    : VendorCommission::MAX_PERCENTAGE;

                if ((float) $value > (float) $max) {
                    $unit = ($row['commission_type'] ?? null) === VendorCommission::TYPE_FIXED ? 'GHS '.$max : $max.'%';
                    $validator->errors()->add("billers.$key.commission_value", "Commission cannot exceed $unit.");
                }

                // Fixed commission is a money amount: two decimals at most.
                if (($row['commission_type'] ?? null) === VendorCommission::TYPE_FIXED && ! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value)) {
                    $validator->errors()->add("billers.$key.commission_value", 'A fixed commission has at most 2 decimals.');
                }
            }
        }];
    }
}
