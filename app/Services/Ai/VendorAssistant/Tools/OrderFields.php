<?php

namespace App\Services\Ai\VendorAssistant\Tools;

use App\Models\Order;

/**
 * The only order columns the Vendor Assistant ever loads, and how they are presented. There is deliberately no
 * recipient phone, mobile-money number, payment reference, gateway, integrity or provider column in this list: what is
 * never selected can never be returned, logged or leaked by a later edit to a tool.
 */
final class OrderFields
{
    /** @var list<string> */
    public const LIST_COLUMNS = [
        'id', 'vendor_id', 'owner_vendor_id', 'is_reseller_order', 'vendor_service_id', 'service_purchased',
        'amount_paid', 'status', 'payment_status', 'created_at', 'payment_completed_at', 'external_fulfillment_completed_at',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function summary(Order $order, int $vendorId): array
    {
        return [
            'ref' => (int) $order->id,
            'product' => VendorToolSupport::serviceName($order->service?->name ?: $order->service_purchased),
            'amount_paid_by_customer' => VendorToolSupport::money($order->amount_paid),
            'status' => (string) $order->status,
            'your_role' => (int) $order->vendor_id === $vendorId ? 'seller' : 'product_owner',
            'is_affiliate_order' => (bool) $order->is_reseller_order,
            'created_at' => VendorToolSupport::date($order->created_at),
            'paid_at' => VendorToolSupport::date($order->payment_completed_at),
            'delivered_at' => VendorToolSupport::date($order->external_fulfillment_completed_at),
        ];
    }
}
