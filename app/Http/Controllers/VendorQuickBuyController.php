<?php

namespace App\Http\Controllers;

use App\Http\Controllers\PurchaseController;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\ResellerProduct;
use App\Models\WalletTopup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorQuickBuyController extends Controller
{
    private function resolveVendor(): Vendor
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();

        $vendor = $vendorGuardUser instanceof Vendor
            ? $vendorGuardUser
            : ($user instanceof Vendor ? $user : null);

        abort_unless($vendor && $vendor->is_approved, 403, 'Approved vendor account required.');

        return $vendor;
    }

    public function show()
    {
        $vendor = $this->resolveVendor();

        $products = Product::where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($p) {
                $p->base_price = (float) ($p->min_base_price ?? $p->price);

                // Prefer decoded JSON description when available (keeps UI readable)
                $decoded = $p->decoded_description ?? [];
                if (is_array($decoded) && ! empty($decoded['description'])) {
                    $p->display_description = (string) $decoded['description'];
                } elseif (is_array($decoded) && ! empty($decoded['notes'])) {
                    $p->display_description = (string) $decoded['notes'];
                } else {
                    $p->display_description = $p->description ?? 'Reliable top-up curated for your customers.';
                }

                return $p;
            });

        // Include reseller listings that this vendor is reselling (affiliate/reseller products)
        $resellerListings = ResellerProduct::where('reseller_vendor_id', $vendor->id)
            ->where('is_active', true)
            ->with('product')
            ->get()
            ->map(function ($rp) {
                $prod = $rp->product;
                if (! $prod) return null;

                // Create a lightweight display object for the reseller listing.
                return (object) [
                    'id' => 'reseller_' . $rp->id,
                    'name' => $prod->name,
                    'description' => $prod->description ?? null,
                    'base_price' => (float) $rp->base_price,
                    'selling_price' => (float) $rp->selling_price,
                    'category' => $prod->category ?? null,
                    'is_reseller' => true,
                    'reseller_product_id' => $rp->id,
                    'original_product_id' => $prod->id,
                ];
            })->filter();

        $products = $products->concat($resellerListings);

        // Calculate available (unconsumed) top-ups for display on quick-buy (cached briefly)
        $totalTopups = (float) \Illuminate\Support\Facades\Cache::remember("vendor:{$vendor->id}:topups_available", 10, function () use ($vendor) {
            $available = WalletTopup::where('vendor_id', $vendor->id)
                ->where('status', 'completed')
                ->selectRaw('SUM(amount - COALESCE(consumed, 0)) as available')
                ->value('available');

            return max(0.0, (float) $available);
        });

        return view('vendor.quick_buy', compact('vendor', 'products', 'totalTopups'));
    }

    public function store(Request $request)
    {
        $vendor = $this->resolveVendor();

        $validated = $request->validate([
            'product_id' => 'required|string',
            'recipient_phone_number' => 'required|string',
            // For quick-buy (vendor wallet) the payer MoMo is optional; keep it
            // nullable here and only include it in the internal payload if present.
            'mobile_money_number' => 'nullable|string',
            'payment_method' => 'required|in:wallet,gateway',
        ]);

        $productId = $validated['product_id'];
        $isReseller = false;
        $resellerProduct = null;

        if (str_starts_with($productId, 'reseller_')) {
            $rpId = (int) substr($productId, strlen('reseller_'));
            $resellerProduct = ResellerProduct::with('product')
                ->where('id', $rpId)
                ->where('reseller_vendor_id', $vendor->id)
                ->where('is_active', true)
                ->firstOrFail();

            $product = $resellerProduct->product;
            $isReseller = true;
            // For wallet purchases we charge against the base price (owner/vendor base),
            // not the reseller markup. Use the reseller listing base_price as canonical.
            $basePrice = (float) $resellerProduct->base_price;
        } else {
            $product = Product::where('id', $productId)
                ->where('vendor_id', $vendor->id)
                ->where('is_active', true)
                ->firstOrFail();
            $basePrice = (float) ($product->min_base_price ?? $product->price);
        }

        // Calculate platform fee and amount to charge for quick-buy (base + 2% fee)
        $platformFee = round($basePrice * 0.02, 2);
        $amountToCharge = round($basePrice + $platformFee, 2);

        // Build an internal request and reuse PurchaseController@store to avoid duplicating order logic
        $internalPayload = [
            'recipient_phone_number' => $validated['recipient_phone_number'],
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            // Use calculated amount (base + platform fee) for quick-buy orders
            'amount_paid' => $amountToCharge,
            'pay_with_wallet' => $validated['payment_method'] === 'wallet' ? 1 : 0,
        ];

        if (!empty($validated['mobile_money_number'])) {
            $internalPayload['mobile_money_number'] = $validated['mobile_money_number'];
        }

        if ($isReseller && $resellerProduct) {
            $internalPayload['is_reseller_product'] = true;
            $internalPayload['reseller_product_id'] = $resellerProduct->id;
        }

        $internal = Request::create(route('purchase'), 'POST', $internalPayload);

        // Call PurchaseController->store internally
        $purchaseController = app(PurchaseController::class);

        return app()->call([$purchaseController, 'store'], ['request' => $internal]);
    }
}
