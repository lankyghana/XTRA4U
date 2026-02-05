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

        // Calculate total completed top-ups for display on quick-buy
        $totalTopups = (float) WalletTopup::where('vendor_id', $vendor->id)
            ->where('status', 'completed')
            ->sum('amount');

        return view('vendor.quick_buy', compact('vendor', 'products', 'totalTopups'));
    }

    public function store(Request $request)
    {
        $vendor = $this->resolveVendor();

        $validated = $request->validate([
            'product_id' => 'required|string',
            'recipient_phone_number' => 'required|string',
            'mobile_money_number' => 'required|string',
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
            $amount = (float) $resellerProduct->selling_price;
        } else {
            $product = Product::where('id', $productId)
                ->where('vendor_id', $vendor->id)
                ->where('is_active', true)
                ->firstOrFail();

            $amount = (float) ($product->min_base_price ?? $product->price);
        }

        // Build an internal request and reuse PurchaseController@store to avoid duplicating order logic
        $internalPayload = [
            'recipient_phone_number' => $validated['recipient_phone_number'],
            'mobile_money_number' => $validated['mobile_money_number'],
            'vendor_id' => $vendor->id,
            'vendor_service_id' => $product->id,
            // Use calculated amount
            'amount_paid' => $amount,
            'pay_with_wallet' => $validated['payment_method'] === 'wallet' ? 1 : 0,
        ];

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
