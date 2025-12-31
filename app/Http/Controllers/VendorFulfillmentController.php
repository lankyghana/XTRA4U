<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\SmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VendorFulfillmentController extends Controller
{
    private const DEFAULT_NETWORKS = ['MTN', 'Vodafone', 'AirtelTigo', 'Telecel'];

    public function index(Request $request)
    {
        $vendor = $this->resolveVendor();

        $this->ensureFulfillmentSchemaReady();

        $networks = $this->resolveNetworksForVendor($vendor);

        $limit = (int) $request->query('limit', 2000);
        $limit = max(1, min($limit, 10000));

        $availableByNetwork = [];
        $downloadedByNetwork = [];
        $downloadedOrdersPreview = [];

        foreach ($networks as $network) {
            $availableByNetwork[$network] = $this->availableOrdersQuery($vendor, $network)->count();
            $downloadedByNetwork[$network] = $this->downloadedOrdersQuery($vendor, $network)->count();

            $downloadedOrdersPreview[$network] = $this->downloadedOrdersQuery($vendor, $network)
                ->with(['service'])
                ->latest('downloaded_at')
                ->limit(20)
                ->get();
        }

        return view('vendor.fulfillment.index', compact(
            'vendor',
            'networks',
            'availableByNetwork',
            'downloadedByNetwork',
            'downloadedOrdersPreview',
            'limit'
        ));
    }

    public function download(Request $request, string $network): Response|RedirectResponse
    {
        $vendor = $this->resolveVendor();

        $this->ensureFulfillmentSchemaReady();

        $network = $this->normalizeNetwork($network);
        $this->assertNetworkAllowed($vendor, $network);

        $batchSize = (int) $request->query('limit', 2000);
        $batchSize = max(1, min($batchSize, 10000));

        $orders = DB::transaction(function () use ($vendor, $network, $batchSize) {
            $orders = $this->availableOrdersQuery($vendor, $network)
                ->with(['service'])
                ->lockForUpdate()
                ->limit($batchSize)
                ->get(['id', 'recipient_phone_number', 'service_purchased', 'vendor_service_id', 'downloaded_at']);

            if ($orders->isEmpty()) {
                return $orders;
            }

            Order::whereIn('id', $orders->pluck('id'))
                ->whereNull('downloaded_at')
                ->update(['downloaded_at' => now()]);

            return $orders;
        });

        if ($orders->isEmpty()) {
            return back()->with('status', 'No new orders available for download for ' . $network . '.');
        }

        $lines = [];
        $lines[] = "Number\tPackage";

        foreach ($orders as $order) {
            $number = (string) $order->recipient_phone_number;
            $package = (string) $order->display_product_label;
            $lines[] = $number . "\t" . str_replace(["\r", "\n", "\t"], ' ', $package);
        }

        $content = implode("\n", $lines) . "\n";

        $filename = 'fulfillment_' . Str::slug($network) . '_' . now()->format('Ymd_His') . '.txt';

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function complete(Request $request, string $network): RedirectResponse
    {
        $vendor = $this->resolveVendor();

        $this->ensureFulfillmentSchemaReady();

        $network = $this->normalizeNetwork($network);
        $this->assertNetworkAllowed($vendor, $network);

        $smsService = app(SmsService::class);

        $updatedCount = 0;

        $this->downloadedOrdersQuery($vendor, $network)
            ->with(['service'])
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$updatedCount, $smsService) {
                foreach ($orders as $order) {
                    $didUpdate = DB::transaction(function () use ($order) {
                        $fresh = Order::query()
                            ->where('id', $order->id)
                            ->where('status', 'Processing')
                            ->whereNotNull('downloaded_at')
                            ->lockForUpdate()
                            ->first();

                        if (! $fresh) {
                            return false;
                        }

                        $fresh->forceFill(['status' => 'Completed'])->save();

                        Transaction::where('order_id', $fresh->id)->update([
                            'payment_status' => 'completed',
                        ]);

                        return true;
                    });

                    if (! $didUpdate) {
                        continue;
                    }

                    $updatedCount++;

                    try {
                        $smsService->sendOrderCompletedSms(
                            $order->recipient_phone_number,
                            $order->service_purchased,
                            $order->id
                        );
                    } catch (\Throwable $e) {
                        // Completion should still succeed even if SMS fails.
                    }
                }
            });

        if ($updatedCount === 0) {
            return back()->with('status', 'No downloaded orders found to mark as completed for ' . $network . '.');
        }

        return back()->with('success', 'Marked ' . $updatedCount . ' downloaded orders as completed for ' . $network . '.');
    }

    private function resolveVendor(): Vendor
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();

        $vendor = $vendorGuardUser instanceof Vendor
            ? $vendorGuardUser
            : ($user instanceof Vendor ? $user : null);

        abort_unless($vendor, 403, 'Vendor account required.');

        return $vendor;
    }

    private function ensureFulfillmentSchemaReady(): void
    {
        abort_unless(
            Schema::hasColumn('orders', 'downloaded_at'),
            503,
            'Order Fulfillment is not enabled yet. Please run database migrations.'
        );
    }

    private function resolveNetworksForVendor(Vendor $vendor): array
    {
        $names = NetworkService::query()->active()->pluck('name')->filter()->values()->all();
        $orderNetworks = $this->processingNetworksFromOrders($vendor);

        $networks = array_values(array_unique(array_merge(self::DEFAULT_NETWORKS, $names, $orderNetworks)));

        // Keep stable ordering: defaults first, then the rest alphabetically.
        usort($networks, function ($a, $b) {
            $aIndex = array_search($a, self::DEFAULT_NETWORKS, true);
            $bIndex = array_search($b, self::DEFAULT_NETWORKS, true);

            if ($aIndex !== false && $bIndex !== false) {
                return $aIndex <=> $bIndex;
            }

            if ($aIndex !== false) {
                return -1;
            }

            if ($bIndex !== false) {
                return 1;
            }

            return strcasecmp((string) $a, (string) $b);
        });

        return $networks;
    }

    private function normalizeNetwork(string $network): string
    {
        $network = trim($network);
        $network = str_replace(['%20', '+'], ' ', $network);

        // Common normalization
        if (strcasecmp($network, 'airtel-tigo') === 0) {
            return 'AirtelTigo';
        }

        return $network;
    }

    private function assertNetworkAllowed(Vendor $vendor, string $network): void
    {
        $allowed = $this->resolveNetworksForVendor($vendor);

        // If we have any known networks (defaults always exist), require membership.
        abort_unless(in_array($network, $allowed, true), 404);
    }

    private function processingNetworksFromOrders(Vendor $vendor): array
    {
        $driver = DB::getDriverName();

        $expr = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(products.description, '$.network'))",
            'sqlite' => "json_extract(products.description, '$.network')",
            default => null,
        };

        if (! $expr) {
            return [];
        }

        try {
            return $this->baseFulfillableOrdersQuery($vendor)
                ->where('status', 'Processing')
                ->whereNotNull('vendor_service_id')
                ->join('products', 'orders.vendor_service_id', '=', 'products.id')
                ->whereNotNull('products.description')
                ->selectRaw("DISTINCT {$expr} as network")
                ->pluck('network')
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function baseFulfillableOrdersQuery(Vendor $vendor)
    {
        $vendorId = (int) $vendor->id;

        return Order::query()
            ->where(function ($q) use ($vendorId) {
                // Regular orders: selling vendor fulfills.
                $q->where(function ($q2) use ($vendorId) {
                    $q2->where('is_reseller_order', false)
                        ->where('vendor_id', $vendorId);
                })
                // Affiliate/reseller orders: product owner fulfills.
                ->orWhere(function ($q2) use ($vendorId) {
                    $q2->where('is_reseller_order', true)
                        ->where('owner_vendor_id', $vendorId);
                })
                // Backward-compatible fallback: infer owner via reseller product.
                ->orWhere(function ($q2) use ($vendorId) {
                    $q2->where('is_reseller_order', true)
                        ->whereHas('resellerProduct', function ($q3) use ($vendorId) {
                            $q3->where('owner_vendor_id', $vendorId);
                        });
                });
            })
            // Paid-only safety: fulfillment should only operate on successfully-paid orders.
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereHas('transactions', fn ($q) => $q->whereIn('payment_status', ['successful', 'completed']));
    }

    private function availableOrdersQuery(Vendor $vendor, string $network)
    {
        return $this->baseFulfillableOrdersQuery($vendor)
            ->where('status', 'Processing')
            ->whereNull('downloaded_at')
            ->whereHas('service', function ($q) use ($network) {
                $q->where('description->network', $network);
            });
    }

    private function downloadedOrdersQuery(Vendor $vendor, string $network)
    {
        return $this->baseFulfillableOrdersQuery($vendor)
            ->where('status', 'Processing')
            ->whereNotNull('downloaded_at')
            ->whereHas('service', function ($q) use ($network) {
                $q->where('description->network', $network);
            });
    }
}
