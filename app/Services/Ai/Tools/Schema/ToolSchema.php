<?php

namespace App\Services\Ai\Tools\Schema;

use App\Services\Ai\Tools\Exceptions\AiToolRegistrationException;
use App\Services\Ai\Tools\Exceptions\ToolInputRejected;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolLimits;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The complete, closed list of parameters a tool accepts. Validation is strict:
 *   1. size / count limits, 2. prohibited (identity/permission) names, 3. unexpected names, 4. required,
 *   5. exact PHP type (no coercion: "5" is not an integer, 1 is not a boolean), 6. Laravel validation rules
 *   generated from the declaration (length, range, enum membership, pattern).
 * Failures carry parameter names and problem codes only - never the submitted values.
 */
final class ToolSchema
{
    public const MAX_PARAMETERS = 8;

    /** @var array<string,ToolParameter> */
    private array $parameters = [];

    public function __construct(ToolParameter ...$parameters)
    {
        if (count($parameters) > self::MAX_PARAMETERS) {
            throw new AiToolRegistrationException('A tool may declare at most '.self::MAX_PARAMETERS.' parameters.');
        }

        foreach ($parameters as $p) {
            if (isset($this->parameters[$p->name])) {
                throw new AiToolRegistrationException("Duplicate tool parameter [{$p->name}].");
            }
            $this->parameters[$p->name] = $p;
        }
    }

    /** @return list<string> */
    public function parameterNames(): array
    {
        return array_keys($this->parameters);
    }

    /** Provider-neutral JSON schema (an "object" that forbids any other property). */
    public function toJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) array_map(fn (ToolParameter $p) => $p->toJsonSchema(), $this->parameters),
            'required' => array_values(array_map(fn (ToolParameter $p) => $p->name, array_filter($this->parameters, fn ($p) => $p->required))),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<mixed>  $raw  untrusted, straight from the model
     *
     * @throws ToolInputRejected
     */
    public function validate(array $raw, ToolLimits $limits): ToolArguments
    {
        if (count($raw) > $limits->maxArguments) {
            throw new ToolInputRejected('arguments_too_large');
        }

        $encoded = json_encode($raw, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false || strlen($encoded) > $limits->maxArgumentBytes) {
            throw new ToolInputRejected('arguments_too_large');
        }

        if ($raw !== [] && array_is_list($raw)) {
            throw new ToolInputRejected('invalid_arguments', [['parameter' => '(arguments)', 'problem' => 'not_an_object']]);
        }

        // Prohibited names first: identity and permissions never come from the model, whatever else is wrong.
        $prohibited = [];
        foreach (array_keys($raw) as $key) {
            if (! is_string($key) || $limits->isProhibitedName($key)) {
                $prohibited[] = ['parameter' => $this->safeName($key), 'problem' => 'prohibited'];
            }
        }
        if ($prohibited !== []) {
            throw new ToolInputRejected('prohibited_argument', $prohibited);
        }

        $problems = [];
        foreach (array_keys($raw) as $key) {
            if (! isset($this->parameters[$key])) {
                $problems[] = ['parameter' => $this->safeName($key), 'problem' => 'unexpected'];
            }
        }

        $present = [];
        foreach ($this->parameters as $name => $p) {
            $value = $raw[$name] ?? null;

            if ($value === null) {
                if ($p->required) {
                    $problems[] = ['parameter' => $name, 'problem' => 'required'];
                }

                continue; // optional and absent (null is treated as absent)
            }

            if (! $this->hasExactType($p, $value)) {
                $problems[] = ['parameter' => $name, 'problem' => is_array($value) || is_object($value) ? 'unsupported_value' : 'wrong_type'];

                continue;
            }

            // Laravel skips non-implicit rules for an empty string, so the empty case is decided here.
            if ($value === '' && $p->type === ToolParameterType::String) {
                if (($p->minLength ?? 0) > 0) {
                    $problems[] = ['parameter' => $name, 'problem' => 'min'];

                    continue;
                }
                if ($p->pattern !== null && preg_match($p->pattern, '') !== 1) {
                    $problems[] = ['parameter' => $name, 'problem' => 'regex'];

                    continue;
                }
            }

            $present[$name] = $value;
        }

        if ($problems !== []) {
            throw new ToolInputRejected('invalid_arguments', $problems);
        }

        $rules = [];
        foreach (array_keys($present) as $name) {
            $rules[$name] = $this->rulesFor($this->parameters[$name], $limits);
        }

        $validator = Validator::make($present, $rules);
        if ($validator->fails()) {
            foreach ($validator->failed() as $field => $failedRules) {
                $problems[] = ['parameter' => (string) $field, 'problem' => strtolower((string) array_key_first($failedRules))];
            }

            throw new ToolInputRejected('invalid_arguments', $problems);
        }

        return new ToolArguments($present);
    }

    private function hasExactType(ToolParameter $p, mixed $value): bool
    {
        return match ($p->type) {
            ToolParameterType::String => is_string($value),
            ToolParameterType::Integer => is_int($value),
            ToolParameterType::Number => is_int($value) || (is_float($value) && is_finite($value)),
            ToolParameterType::Boolean => is_bool($value),
            ToolParameterType::Enum => is_string($value),
        };
    }

    /** @return list<mixed> */
    private function rulesFor(ToolParameter $p, ToolLimits $limits): array
    {
        switch ($p->type) {
            case ToolParameterType::String:
                $rules = ['string', 'min:'.($p->minLength ?? 0), 'max:'.min((int) $p->maxLength, $limits->maxStringLength)];
                if ($p->pattern !== null) {
                    $rules[] = 'regex:'.$p->pattern;
                }

                return $rules;
            case ToolParameterType::Integer:
                return ['integer', 'min:'.$p->min, 'max:'.$p->max];
            case ToolParameterType::Number:
                return ['numeric', 'min:'.$p->min, 'max:'.$p->max];
            case ToolParameterType::Boolean:
                return ['boolean'];
            case ToolParameterType::Enum:
                return ['string', Rule::in($p->values)];
        }
    }

    /** Only well-formed identifiers are echoed back to the model; anything else is reported generically. */
    private function safeName(int|string $key): string
    {
        return is_string($key) && preg_match(ToolParameter::NAME_PATTERN, $key) === 1 ? $key : '(invalid name)';
    }
}
