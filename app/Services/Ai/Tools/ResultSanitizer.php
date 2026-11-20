<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Logging\Redactor;

/**
 * Last line of defence on what a tool returns. The tool is responsible for returning a minimal, allow-listed array;
 * this makes sure that whatever it returns is plain, bounded data:
 *   - only null / bool / int / finite float / string / arrays (objects, closures, resources are refused),
 *   - credential-looking keys are dropped, strings pass through Redactor (keys, bearer tokens, emails, phone numbers,
 *     long digit runs) and over-long strings are truncated (and flagged),
 *   - depth, item count and total JSON size are capped; over the cap the result is REFUSED, never silently cut.
 */
final class ResultSanitizer
{
    /** Keys that never reach a model, whatever a tool returned. */
    private const FORBIDDEN_KEYS = [
        'password', 'passwd', 'secret', 'api_key', 'apikey', 'key_hash', 'authorization', 'cookie', 'credential',
        'private_key', 'client_secret', 'webhook_secret', 'signing_secret', 'delivered_pins', 'pin', 'pins',
        'card_number', 'cvv', 'ghana_card', 'remember_token',
    ];

    public function __construct(private readonly ToolLimits $limits) {}

    /**
     * @param  array<mixed>  $data
     * @return array{0:array<mixed>,1:bool,2:int} sanitised data, truncated?, JSON bytes
     *
     * @throws \InvalidArgumentException invalid_result | result_too_large
     */
    public function sanitize(array $data): array
    {
        $truncated = false;
        $clean = $this->walk($data, 1, $truncated);

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('invalid_result');
        }

        if (strlen($json) > $this->limits->maxResultBytes) {
            throw new \InvalidArgumentException('result_too_large');
        }

        return [$clean, $truncated, strlen($json)];
    }

    /** @param array<mixed> $node */
    private function walk(array $node, int $depth, bool &$truncated): array
    {
        if ($depth > $this->limits->maxResultDepth) {
            throw new \InvalidArgumentException('result_too_large');
        }

        if (count($node) > $this->limits->maxResultItems) {
            throw new \InvalidArgumentException('result_too_large');
        }

        $out = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && $this->isForbiddenKey($key)) {
                continue;
            }

            $out[$key] = match (true) {
                $value === null, is_bool($value), is_int($value) => $value,
                is_float($value) => is_finite($value) ? $value : throw new \InvalidArgumentException('invalid_result'),
                is_string($value) => $this->cleanString($value, $truncated),
                is_array($value) => $this->walk($value, $depth + 1, $truncated),
                default => throw new \InvalidArgumentException('invalid_result'), // objects, closures, resources
            };
        }

        return $out;
    }

    private function cleanString(string $value, bool &$truncated): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = Redactor::redact($value);

        if (mb_strlen($value) > $this->limits->maxResultStringLength) {
            $truncated = true;
            $value = mb_substr($value, 0, $this->limits->maxResultStringLength);
        }

        return $value;
    }

    private function isForbiddenKey(string $key): bool
    {
        $k = strtolower($key);

        return in_array($k, self::FORBIDDEN_KEYS, true)
            || $k === 'token'
            || str_ends_with($k, '_token')
            || str_ends_with($k, '_secret')
            || str_ends_with($k, '_password');
    }
}
