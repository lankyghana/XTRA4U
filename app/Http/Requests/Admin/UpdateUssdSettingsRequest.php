<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateUssdSettingsRequest extends FormRequest
{
    /**
     * Mirrors the AdminOnly middleware: either the dedicated admin guard, or a
     * web user carrying role=admin. Checked here as well so the rule survives
     * any future re-routing of this endpoint.
     */
    public function authorize(): bool
    {
        $user = Auth::guard('admin')->user() ?: Auth::user();

        return $user !== null && ($user->role ?? 'admin') === 'admin';
    }

    public function rules(): array
    {
        return [
            'ussd_enabled' => ['sometimes', 'boolean'],

            // Legacy display-only key, retained for backward compatibility.
            'ussd_service_code' => ['nullable', 'string', 'max:50'],

            // e.g. *203*  — leading star, digit groups, optional trailing star.
            'ussd_base_code' => ['nullable', 'string', 'max:20', 'regex:/^\*\d+(\*\d+)*\*?$/'],

            'ussd_provider' => ['sometimes', 'string', 'in:moolre'],
            'ussd_welcome_message' => ['required', 'string', 'max:100'],
            'ussd_support_number' => ['nullable', 'string', 'max:20'],
            'ussd_default_vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],

            'ussd_session_timeout_seconds' => ['sometimes', 'integer', 'min:30', 'max:600'],
            'ussd_max_requests_per_session' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ussd_max_retry_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],

            'ussd_gateway_ip_allowlist' => ['nullable', 'string', 'max:500', $this->ipAllowlistRule()],
            'ussd_gateway_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'ussd_gateway_secret_clear' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ussd_base_code.regex' => 'The base code must look like *203* — a leading asterisk, digits, and optional separators.',
            'ussd_gateway_secret.min' => 'The gateway secret must be at least 16 characters.',
        ];
    }

    /**
     * Every entry must be a bare IP or a CIDR block. Rejecting malformed values
     * here prevents a typo from silently widening the allowlist to nothing.
     */
    private function ipAllowlistRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            foreach (array_map('trim', explode(',', $value)) as $entry) {
                if ($entry === '') {
                    continue;
                }

                [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $fail("\"{$entry}\" is not a valid IP address or CIDR block.");

                    return;
                }

                if ($prefix !== null) {
                    $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
                    if (! ctype_digit($prefix) || (int) $prefix > $max) {
                        $fail("\"{$entry}\" has an invalid CIDR prefix.");

                        return;
                    }
                }
            }
        };
    }
}
