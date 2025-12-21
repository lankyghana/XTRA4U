<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;

class FixOrderProductNames extends Command
{
    protected $signature = 'orders:fix-product-names';
    protected $description = 'Fix historical orders to show the correct product name instead of MD5 hash';

    public function handle()
    {
        $count = 0;
        $orders = Order::whereRaw('LENGTH(service_purchased) = 32')
            ->whereRaw("service_purchased REGEXP '^[a-f0-9]{32}$'")
            ->get();

        foreach ($orders as $order) {
            $name = null;
            // Try vendor_service_id if numeric
            if (is_numeric($order->vendor_service_id)) {
                $product = Product::find($order->vendor_service_id);
                if ($product) {
                    $name = $product->name;
                }
            }
            // Try reseller_product_id if set
            if (!$name && $order->reseller_product_id) {
                $resellerProduct = ResellerProduct::with('product')->find($order->reseller_product_id);
                if ($resellerProduct && $resellerProduct->product) {
                    $name = $resellerProduct->product->name;
                }
            }
            // Try parsing reseller_X format
            if (!$name && is_string($order->vendor_service_id) && str_starts_with($order->vendor_service_id, 'reseller_')) {
                $resellerProductId = (int) str_replace('reseller_', '', $order->vendor_service_id);
                if ($resellerProductId > 0) {
                    $resellerProduct = ResellerProduct::with('product')->find($resellerProductId);
                    if ($resellerProduct && $resellerProduct->product) {
                        $name = $resellerProduct->product->name;
                    }
                }
            }
            if ($name) {
                $order->service_purchased = $name;
                $order->save();
                $count++;
                $this->info("Order #{$order->id} updated to '{$name}'");
            }
        }
        $this->info("Done. Updated {$count} orders.");
    }
}
