<?php

namespace App\Http\Requests\Ussd;

use App\Services\Ussd\GwoSevo\GwoSevoProtocol;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GwoSevoGatewayRequest extends FormRequest
{
    /**
     * Authentication is the `ussd.gwosevo` middleware's job; this request only
     * validates the payload it carries.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * JSON clients may send numbers where the guide shows strings (sessionID
     * 12345, network 1). Normalise scalars so validation sees one shape.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['sessionID', 'ussdString', 'network', 'msisdn'] as $key) {
            $value = $this->input($key);
            if (is_int($value) || is_float($value)) {
                $merge[$key] = (string) $value;
            }
        }

        $network = $merge['network'] ?? $this->input('network');
        if (is_string($network) && strlen($network) === 1 && ctype_digit($network)) {
            $merge['network'] = '0'.$network;
        }

        $op = $this->input('ussdServiceOp');
        if (is_string($op) && ctype_digit($op)) {
            $merge['ussdServiceOp'] = (int) $op;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            // Gateway-issued and opaque. Constrained to characters that can
            // never break out of a log line or a lookup.
            'sessionID' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'ussdServiceOp' => ['required', 'integer', Rule::in([GwoSevoProtocol::OP_START, GwoSevoProtocol::OP_CONTINUE])],
            'ussdString' => ['nullable', 'string', 'max:182', 'regex:/^[0-9A-Za-z*#_. -]*$/'],
            'network' => ['required', 'string', Rule::in(GwoSevoProtocol::networkCodes())],
            'msisdn' => ['required', 'string', 'regex:/^\+?[0-9]{9,15}$/'],
        ];
    }

    /**
     * The gateway renders our reply straight to a handset; a 422 would surface
     * as a generic network error. Fail in-protocol, echoing the session id only
     * when it is safe to do so.
     */
    protected function failedValidation(Validator $validator): void
    {
        $sessionId = $this->input('sessionID');
        $echo = is_string($sessionId) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $sessionId) ? $sessionId : '';

        throw new HttpResponseException(
            GwoSevoProtocol::end('Invalid request. Please dial again.', $echo)
        );
    }
}
