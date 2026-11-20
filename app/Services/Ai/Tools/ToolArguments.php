<?php

namespace App\Services\Ai\Tools;

/**
 * Arguments that have ALREADY passed schema validation. A tool only ever sees this object, never the raw model
 * output, and it contains only the parameters the tool declared.
 */
final class ToolArguments
{
    /** @param array<string,string|int|float|bool> $values */
    public function __construct(private readonly array $values) {}

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function string(string $name, ?string $default = null): ?string
    {
        return $this->has($name) ? (string) $this->values[$name] : $default;
    }

    public function int(string $name, ?int $default = null): ?int
    {
        return $this->has($name) ? (int) $this->values[$name] : $default;
    }

    public function float(string $name, ?float $default = null): ?float
    {
        return $this->has($name) ? (float) $this->values[$name] : $default;
    }

    public function bool(string $name, ?bool $default = null): ?bool
    {
        return $this->has($name) ? (bool) $this->values[$name] : $default;
    }

    /** @return array<string,string|int|float|bool> */
    public function all(): array
    {
        return $this->values;
    }
}
