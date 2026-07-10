<?php

namespace App\Http\Requests\Ussd;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UssdGatewayRequest extends FormRequest
{
    /**
     * Authentication of the caller is the `ussd.gateway` middleware's job;
     * this request only validates the payload it carries.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Aggregator-issued and opaque. Constrained to characters that can
            // never break out of a route segment or a log line.
            'sessionId' => ['required', 'string', 'min:6', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'phoneNumber' => ['required', 'string', 'min:9', 'max:20', 'regex:/^\+?[0-9]+$/'],
            'network' => ['nullable', 'string', 'max:20', 'alpha_dash'],
            'serviceCode' => ['nullable', 'string', 'max:32'],

            // A USSD payload can carry at most 182 characters, and only digits,
            // the separator, and the terminator ever appear in a dialled string.
            'text' => ['nullable', 'string', 'max:182', 'regex:/^[0-9A-Za-z*#_. -]*$/'],
        ];
    }

    /**
     * The caller is a USSD aggregator rendering our body straight to a handset.
     * A 422 with a JSON body would surface as gibberish, so fail in-protocol.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response('END Invalid request. Please dial again.', 200)
                ->header('Content-Type', 'text/plain')
        );
    }
}
