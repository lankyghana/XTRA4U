<?php

namespace App\Services\UtilityBills\Data;

/**
 * The provider biller catalog. min/max are PROVIDER-wide limits (the docs expose
 * them once for all billers, not per biller) and may be absent.
 */
final class Catalog
{
    /**
     * @param  array<string,Biller>  $billers  keyed by biller key
     */
    public function __construct(
        public readonly array $billers,
        public readonly ?string $minAmount,
        public readonly ?string $maxAmount,
        public readonly string $currency,
        public readonly int $fetchedAt,
    ) {}

    public function biller(string $key): ?Biller
    {
        return $this->billers[$key] ?? null;
    }

    public function toArray(): array
    {
        return [
            'billers' => array_map(fn (Biller $b) => $b->toArray(), array_values($this->billers)),
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'currency' => $this->currency,
            'fetched_at' => $this->fetchedAt,
        ];
    }

    public static function fromArray(array $a): self
    {
        $billers = [];
        foreach ($a['billers'] as $row) {
            $b = Biller::fromArray($row);
            $billers[$b->key] = $b;
        }

        return new self($billers, $a['min_amount'], $a['max_amount'], $a['currency'], (int) $a['fetched_at']);
    }
}
