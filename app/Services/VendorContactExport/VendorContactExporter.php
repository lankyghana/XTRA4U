<?php

namespace App\Services\VendorContactExport;

use App\Models\Vendor;
use App\Support\ContactPhone;
use Carbon\Carbon;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only selection of vendor contacts for the admin export. Only the whitelisted columns below are
 * ever selected, so secrets (password, remember_token, balances, API keys) cannot reach an export.
 */
final class VendorContactExporter
{
    public const FIELDS = [
        'name' => 'Vendor Name',
        'phone' => 'Phone',
        'email' => 'Email',
        'vendor_code' => 'Vendor Code',
        'status' => 'Status',
        'tier' => 'Tier',
        'registered_at' => 'Registration Date',
        'id' => 'Vendor ID',
    ];

    public const ORDERS = [
        'newest' => 'Newest vendors first',
        'oldest' => 'Oldest vendors first',
        'name_asc' => 'Name A-Z',
        'name_desc' => 'Name Z-A',
    ];

    private const CHUNK = 1000;

    /** Vendors matching the filters (before phone validity / de-duplication / limit). */
    public function matchingCount(array $o): int
    {
        return $this->query($o)->toBase()->getCountForPagination();
    }

    /**
     * Yields one contact array per exportable vendor, applying validity, de-duplication and limit in a
     * single streaming pass. Memory is bounded by the chunk size plus the de-dup key set (ints).
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function contacts(array $o, ExportStats $stats): Generator
    {
        $limit = $o['limit'] ?? null;
        $seen = [];

        $rows = $this->query($o)
            ->select([
                'vendors.id', 'vendors.name', 'vendors.email', 'vendors.phone_number',
                'vendors.vendor_code', 'vendors.is_approved', 'vendors.created_at',
                'vendor_tiers.name as tier_name',
            ])
            ->lazy(self::CHUNK);

        foreach ($rows as $vendor) {
            $stats->scanned++;

            $parsed = ContactPhone::parse($vendor->phone_number);
            if ($parsed === null) {
                $stats->skippedInvalid++;
                if (! empty($o['valid_only'])) {
                    continue;
                }
                $phone = trim((string) $vendor->phone_number);
            } else {
                if (! empty($o['remove_duplicates'])) {
                    if (isset($seen[$parsed['key']])) {
                        $stats->duplicatesRemoved++;

                        continue;
                    }
                    $seen[$parsed['key']] = true;
                }
                $phone = ($o['phone_format'] ?? 'local') === 'international' ? $parsed['international'] : $parsed['local'];
            }

            $stats->exported++;

            yield [
                'id' => (int) $vendor->id,
                'name' => $this->clean((string) $vendor->name),
                'phone' => $this->clean($phone),
                'email' => $this->clean((string) $vendor->email),
                'vendor_code' => $this->clean((string) $vendor->vendor_code),
                'status' => $vendor->is_approved ? 'Approved' : 'Pending',
                'tier' => $this->clean((string) $vendor->tier_name),
                'registered_at' => $vendor->created_at?->format('Y-m-d') ?? '',
            ];

            if ($limit !== null && $stats->exported >= $limit) {
                return;
            }
        }
    }

    /** Dry run used by the on-screen summary; identical logic to the download. */
    public function summarize(array $o): array
    {
        $stats = new ExportStats;
        foreach ($this->contacts($o, $stats) as $contact) {
            // consume only
        }

        return [
            'matching' => $this->matchingCount($o),
            'scanned' => $stats->scanned,
            'skipped_invalid' => $stats->skippedInvalid,
            'duplicates_removed' => $stats->duplicatesRemoved,
            'exported' => $stats->exported,
        ];
    }

    private function query(array $o): Builder
    {
        $like = null;
        if (($q = trim((string) ($o['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
        }

        $query = Vendor::query()
            ->leftJoin('vendor_tiers', 'vendor_tiers.id', '=', 'vendors.tier_id')
            ->when(($o['status'] ?? 'all') === 'approved', fn ($q) => $q->where('vendors.is_approved', true))
            ->when(($o['status'] ?? 'all') === 'pending', fn ($q) => $q->where('vendors.is_approved', false))
            ->when(! empty($o['tier_id']), fn ($q) => $q->where('vendors.tier_id', (int) $o['tier_id']))
            ->when(! empty($o['registered_from']), fn ($q) => $q->where('vendors.created_at', '>=', Carbon::parse($o['registered_from'])->startOfDay()))
            ->when(! empty($o['registered_to']), fn ($q) => $q->where('vendors.created_at', '<=', Carbon::parse($o['registered_to'])->endOfDay()))
            ->when($like !== null, function ($q) use ($like) {
                $q->where(function ($w) use ($like) {
                    foreach (['vendors.name', 'vendors.email', 'vendors.phone_number', 'vendors.vendor_code'] as $col) {
                        $w->orWhereRaw("{$col} LIKE ? ESCAPE '!'", [$like]);
                    }
                });
            });

        // vendors.id as the final tiebreaker keeps "first N" deterministic.
        return match ($o['order'] ?? 'newest') {
            'oldest' => $query->orderBy('vendors.created_at')->orderBy('vendors.id'),
            'name_asc' => $query->orderBy('vendors.name')->orderBy('vendors.id'),
            'name_desc' => $query->orderByDesc('vendors.name')->orderByDesc('vendors.id'),
            default => $query->orderByDesc('vendors.created_at')->orderByDesc('vendors.id'),
        };
    }

    /** Strip control characters (incl. CR/LF) so one value can never span lines. */
    private function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    }
}
