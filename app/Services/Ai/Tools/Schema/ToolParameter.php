<?php

namespace App\Services\Ai\Tools\Schema;

use App\Services\Ai\Tools\Exceptions\AiToolRegistrationException;

/**
 * One business parameter a tool accepts. Scalars only (string, integer, number, boolean, enum): no nested objects,
 * no arrays, so an argument set can never be complex. There is deliberately no type for "class name", "model",
 * "SQL", "path", "URL" or "expression".
 */
final class ToolParameter
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';

    /** Absolute ceiling for any string parameter, whatever a tool asks for. */
    public const MAX_STRING = 1000;

    /** @param list<string>|null $values */
    private function __construct(
        public readonly string $name,
        public readonly ToolParameterType $type,
        public readonly string $description,
        public readonly bool $required,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly ?array $values = null,
        public readonly ?string $pattern = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new AiToolRegistrationException('Invalid tool parameter name.');
        }

        if (trim($description) === '' || mb_strlen($description) > 200) {
            throw new AiToolRegistrationException("Tool parameter [{$name}] needs a description of 1-200 characters.");
        }

        if ($type === ToolParameterType::String && ($maxLength === null || $maxLength < 1 || $maxLength > self::MAX_STRING || ($minLength ?? 0) > $maxLength || ($minLength ?? 0) < 0)) {
            throw new AiToolRegistrationException("Tool parameter [{$name}] needs a maxLength between 1 and ".self::MAX_STRING.'.');
        }

        if (($type === ToolParameterType::Integer || $type === ToolParameterType::Number) && ($min === null || $max === null || $min > $max)) {
            throw new AiToolRegistrationException("Numeric tool parameter [{$name}] needs explicit min and max bounds.");
        }

        if ($type === ToolParameterType::Enum
            && ($values === null || $values === [] || count($values) > 50
                || array_filter($values, fn ($v) => ! is_string($v) || $v === '' || strlen($v) > 64) !== [])) {
            throw new AiToolRegistrationException("Enum tool parameter [{$name}] needs 1-50 short string values.");
        }

        if ($pattern !== null && @preg_match($pattern, '') === false) {
            throw new AiToolRegistrationException("Tool parameter [{$name}] has an invalid pattern.");
        }
    }

    public static function string(string $name, string $description, bool $required = false, int $maxLength = 200, int $minLength = 0, ?string $pattern = null): self
    {
        return new self($name, ToolParameterType::String, $description, $required, $minLength, $maxLength, null, null, null, $pattern);
    }

    public static function integer(string $name, string $description, int $min, int $max, bool $required = false): self
    {
        return new self($name, ToolParameterType::Integer, $description, $required, null, null, $min, $max);
    }

    public static function number(string $name, string $description, float $min, float $max, bool $required = false): self
    {
        return new self($name, ToolParameterType::Number, $description, $required, null, null, $min, $max);
    }

    public static function boolean(string $name, string $description, bool $required = false): self
    {
        return new self($name, ToolParameterType::Boolean, $description, $required);
    }

    /** @param list<string> $values */
    public static function enum(string $name, string $description, array $values, bool $required = false): self
    {
        return new self($name, ToolParameterType::Enum, $description, $required, null, null, null, null, array_values($values));
    }

    /** Provider-neutral JSON-schema fragment. */
    public function toJsonSchema(): array
    {
        $schema = ['description' => $this->description];

        switch ($this->type) {
            case ToolParameterType::String:
                $schema += ['type' => 'string', 'maxLength' => $this->maxLength];
                if (($this->minLength ?? 0) > 0) {
                    $schema['minLength'] = $this->minLength;
                }
                break;
            case ToolParameterType::Integer:
            case ToolParameterType::Number:
                $schema += ['type' => $this->type->value, 'minimum' => $this->min, 'maximum' => $this->max];
                break;
            case ToolParameterType::Boolean:
                $schema += ['type' => 'boolean'];
                break;
            case ToolParameterType::Enum:
                $schema += ['type' => 'string', 'enum' => $this->values];
                break;
        }

        return $schema;
    }
}
