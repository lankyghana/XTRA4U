<?php

namespace App\Services\Ai\VendorAssistant\Tools;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\Tools\SafeText;
use App\Services\Ai\Tools\ToolContext;
use Carbon\Carbon;

/**
 * Behaviour shared by the Vendor Assistant's tools: the same surface, the same authorization rule, and the same
 * output formatting (so every figure the model sees is formatted one way).
 */
final class VendorToolSupport
{
    public const CURRENCY = 'GHS';

    /** Human labels for the dashboard's period filters. */
    public const PERIOD_LABELS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week (Monday to today)',
        'this_month' => 'This month (1st to today)',
        'all_time' => 'All time',
    ];

    /** A money amount as a plain two-decimal string ("1065.00"): no float surprises, no thousands separators. */
    public static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /** Free text a vendor typed (product names): single line, no markup, no links, bounded. */
    public static function text(mixed $value, int $max = 80): string
    {
        return SafeText::clean($value, $max);
    }

    /**
     * The name shown for an order's product. Orders created long ago sometimes stored an opaque 32-character id as
     * the service name; the dashboard shows those as "Unknown Service", and so do the tools.
     */
    public static function serviceName(mixed $name, int $max = 80): string
    {
        $text = self::text($name, $max);

        return ($text === '' || preg_match('/^[a-f0-9]{32}$/i', $text) === 1) ? 'Unknown service' : $text;
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? Carbon::instance($value)->format('Y-m-d H:i') : null;
    }

    /** True only for a context built by ToolContext::forVendor() on the vendor surface. */
    public static function isVendorContext(ToolContext $context): bool
    {
        return $context->surface === AiSurface::Vendor
            && $context->vendorId !== null
            && $context->can(ToolContext::CAP_VENDOR_APPROVED);
    }

    /** @return array{from: ?string, to: ?string} */
    public static function periodRange(array $range): array
    {
        return [
            'from' => $range[0]?->toDateString(),
            'to' => $range[1]?->toDateString(),
        ];
    }
}
