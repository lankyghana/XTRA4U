<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Exceptions\AiConfigurationException;

/**
 * The tool system's resource limits and the prohibited-argument list, read from `config('ai.tools')` and validated:
 * a missing, non-integer or non-positive value is a configuration error (fail closed), never "unlimited".
 */
final class ToolLimits
{
    private const KEYS = [
        'max_calls_per_turn', 'max_arguments', 'max_argument_bytes', 'max_string_length', 'max_result_bytes',
        'max_result_items', 'max_result_depth', 'max_result_string_length', 'max_execution_seconds', 'max_turn_seconds',
    ];

    /** @param list<string> $prohibitedNames already normalised */
    private function __construct(
        public readonly int $maxCallsPerTurn,
        public readonly int $maxArguments,
        public readonly int $maxArgumentBytes,
        public readonly int $maxStringLength,
        public readonly int $maxResultBytes,
        public readonly int $maxResultItems,
        public readonly int $maxResultDepth,
        public readonly int $maxResultStringLength,
        public readonly int $maxExecutionSeconds,
        public readonly int $maxTurnSeconds,
        public readonly array $prohibitedNames,
    ) {}

    public static function fromConfig(): self
    {
        $cfg = (array) config('ai.tools.limits', []);

        foreach (self::KEYS as $key) {
            if (! isset($cfg[$key]) || ! is_int($cfg[$key]) || $cfg[$key] < 1) {
                throw new AiConfigurationException("Invalid AI tool limit [{$key}].");
            }
        }

        $names = config('ai.tools.prohibited_argument_names');
        if (! is_array($names) || $names === [] || array_filter($names, fn ($n) => ! is_string($n) || $n === '') !== []) {
            throw new AiConfigurationException('The AI tool prohibited-argument list is not configured correctly.');
        }

        return new self(
            $cfg['max_calls_per_turn'], $cfg['max_arguments'], $cfg['max_argument_bytes'], $cfg['max_string_length'],
            $cfg['max_result_bytes'], $cfg['max_result_items'], $cfg['max_result_depth'], $cfg['max_result_string_length'],
            $cfg['max_execution_seconds'], $cfg['max_turn_seconds'],
            array_values(array_unique(array_map([self::class, 'normalise'], $names))),
        );
    }

    /** "vendor-id", "vendorId", "VENDOR_ID" all become "vendorid". */
    public static function normalise(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    /**
     * Is this argument/parameter name an identity, ownership, approval or permission field? Exact matches, plus any
     * name that merely CONTAINS one of the "...id" identity tokens ("target_vendor_id", "forVendorId").
     */
    public function isProhibitedName(string $name): bool
    {
        $n = self::normalise($name);

        if ($n === '') {
            return true;
        }

        foreach ($this->prohibitedNames as $p) {
            if ($n === $p) {
                return true;
            }
            if (strlen($p) >= 6 && str_ends_with($p, 'id') && str_contains($n, $p)) {
                return true;
            }
        }

        return false;
    }
}
