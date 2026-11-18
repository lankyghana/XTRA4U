<?php

namespace App\Http\Requests\DeveloperApi;

use App\Services\DeveloperApi\Sandbox\SandboxOrderTransitions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Exactly one of `event` (a single predefined step) or `scenario` (a named sequence). Both come from fixed
 * allow-lists. Any attempt to send a status value is rejected outright rather than silently ignored, so a
 * client can never believe it has set one.
 */
class SimulateSandboxOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event' => ['nullable', 'string', 'required_without:scenario', 'prohibits:scenario', Rule::in(SandboxOrderTransitions::STEPS)],
            'scenario' => ['nullable', 'string', 'required_without:event', 'prohibits:event', Rule::in(array_keys(SandboxOrderTransitions::SCENARIOS))],

            // Arbitrary state injection is refused, not ignored.
            'status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'fulfillment_status' => ['prohibited'],
            'amount' => ['prohibited'],
            'paid' => ['prohibited'],
        ];
    }
}
