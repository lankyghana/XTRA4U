<?php

namespace App\Http\Controllers;

use App\Http\Controllers\PurchaseController;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\ResellerProduct;
use App\Models\WalletTopup;
use App\Models\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorQuickBuyController extends Controller
{
    private function cleanMetaValue($value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

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

        $networkServices = NetworkService::active()->get()->keyBy(fn ($svc) => strtolower($svc->name));

        $products = Product::where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->orderBy('created_at')
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

                $p->package_size = $this->cleanMetaValue(data_get($decoded, 'size'));
                $p->validity = $this->cleanMetaValue(data_get($decoded, 'validity'));
                $p->network = $this->cleanMetaValue(data_get($decoded, 'network'));
                $p->tag = $p->tag ?? $this->cleanMetaValue(data_get($decoded, 'tag'));
                $p->network_logo = null; // populated after map using lookup table
                $p->created_at = $p->created_at;

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

                $decoded = $prod->decoded_description ?? [];
                $description = data_get($decoded, 'description') ?? data_get($decoded, 'notes') ?? ($prod->description ?? null);
                $packageSize = $this->cleanMetaValue(data_get($decoded, 'size'));
                $validity = $this->cleanMetaValue(data_get($decoded, 'validity'));
                $network = $this->cleanMetaValue(data_get($decoded, 'network'));
                $tag = $this->cleanMetaValue(data_get($decoded, 'tag'));
                $category = $prod->category ?? $this->cleanMetaValue(data_get($decoded, 'category'));

                // Create a lightweight display object for the reseller listing.
                return (object) [
                    'id' => 'reseller_' . $rp->id,
                    'name' => $prod->name,
                    'description' => $description,
                    'display_description' => $description,
                    'base_price' => (float) $rp->base_price,
                    'selling_price' => (float) $rp->selling_price,
                    'category' => $category,
                    'network' => $network,
                    'package_size' => $packageSize,
                    'validity' => $validity,
                    'tag' => $tag,
                    'network_logo' => null, // populated after merge using lookup
                    'created_at' => $prod->created_at,
                    'is_reseller' => true,
                    'reseller_product_id' => $rp->id,
                    'original_product_id' => $prod->id,
                ];
            })->filter();

        // Preserve creation ordering across vendor and reseller listings
        $products = $products
            ->concat($resellerListings)
            ->sortBy('created_at')
            ->values();

        // Inject AFA Registration if enabled
        $afaEnabled = $vendor->afa_enabled && $vendor->afa_price > 0;
        $resellerAfaEnabled = $vendor->afa_reseller_enabled && $vendor->afa_selling_price > 0 && $vendor->afa_source_vendor_id;

        if ($afaEnabled || $resellerAfaEnabled) {
            $afaPrice = $afaEnabled ? $vendor->afa_price : $vendor->afa_selling_price;
            
            $afaProduct = (object) [
                'id' => 'afa_registration',
                'name' => 'AFA Registration',
                'description' => 'Complete AFA Registration with ID verification',
                'display_description' => 'Complete AFA Registration with ID verification',
                'base_price' => (float) $afaPrice,
                'selling_price' => (float) $afaPrice,
                'price' => (float) $afaPrice,
                'category' => 'afa',
                'network' => 'AFA',
                'package_size' => null,
                'validity' => null,
                'tag' => ($resellerAfaEnabled && !$afaEnabled) ? 'Reseller' : 'Official',
                'network_logo' => '/images/afa-logo.png',
                'created_at' => now(),
                'is_reseller' => false,
                'is_afa' => true,
                'afa_url' => route('afa.register', $vendor->vendor_code),
            ];
            
            $products->push($afaProduct);
        }

        // Attach network logos based on network service table
        $products = $products->map(function ($p) use ($networkServices) {
            $networkKey = strtolower($p->network ?? '');
            $logo = $networkKey && isset($networkServices[$networkKey]) ? $networkServices[$networkKey]->image_url : null;
            if ($logo) {
                $p->network_logo = $logo;
            } elseif (!isset($p->network_logo)) {
                $p->network_logo = null;
            }
            return $p;
        });

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
