<?php

namespace App\Services\Ai\CustomerAssistant\Tools;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\Tools\SafeText;
use App\Services\Ai\Tools\ToolActorType;
use App\Services\Ai\Tools\ToolContext;

/**
 * Behaviour shared by the Customer Assistant's tools: the same authorization rule and one way of cleaning free text.
 *
 * Everything vendors or administrators typed (product names, notes, FAQ answers) is UNTRUSTED data: reduced to a short,
 * single-line, markup-free, link-free string before it goes anywhere near a model.
 */
final class CustomerToolSupport
{
    public const PRICE_NOTE = 'This is the current catalogue price. The amount you pay is confirmed on the checkout page before you pay.';

    public static function text(mixed $value, int $max = 80): string
    {
        return SafeText::clean($value, $max);
    }

    /** Optional text: null when there is nothing left after cleaning. */
    public static function optionalText(mixed $value, int $max): ?string
    {
        $text = self::text($value, $max);

        return $text === '' ? null : $text;
    }

    /** True only for a context built by ToolContext::forGuest() on the customer surface. */
    public static function isGuestContext(ToolContext $context): bool
    {
        return $context->surface === AiSurface::Customer
            && $context->actorType === ToolActorType::Guest
            && $context->vendorId === null
            && $context->can(ToolContext::CAP_GUEST_SESSION);
    }

    /**
     * The customer-safe shape of one catalogue item: no internal ids, no vendor, no cost or margin, no provider data.
     *
     * @param  array<string,mixed>  $item  from PublicCatalogueReadService
     * @return array<string,mixed>
     */
    public static function productSummary(array $item, bool $withNotes = false): array
    {
        $out = [
            'ref' => (string) $item['ref'],
            'name' => self::text($item['name'], 80),
            'category' => (string) $item['category'],
            'service' => self::text($item['service'] ?? '', 60),
            'size' => self::optionalText($item['size'] ?? null, 40),
            'validity' => self::optionalText($item['validity'] ?? null, 40),
            'price' => (string) $item['price'],
            'currency' => 'GHS',
        ];

        if ($withNotes) {
            $out['notes'] = self::optionalText($item['notes'] ?? null, 300);
        }

        return $out;
    }
}
