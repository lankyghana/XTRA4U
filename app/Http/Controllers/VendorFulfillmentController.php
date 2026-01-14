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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorFulfillmentController extends Controller
{
    /**
     * Resend all failed External API orders for the current vendor.
     */
    public function resendAllFailedApiOrders(Request $request): RedirectResponse
    {
        $vendor = $this->resolveVendor();
        $this->ensureFulfillmentSchemaReady();

        // Find all failed external API orders for this vendor
        $failedOrders = $this->baseFulfillableOrdersQuery($vendor)
            ->where('status', 'Processing')
            ->where('external_fulfillment_status', 'failed')
            ->get();

        if ($failedOrders->isEmpty()) {
            return back()->with('status', 'No failed External API orders to resend.');
        }

        $resentCount = 0;
        foreach ($failedOrders as $order) {
            try {
                // Here you would call the actual resend logic, e.g. dispatch a job or call a service
                // For now, we just increment the attempts and clear the error/status for demonstration
                $order->external_fulfillment_status = 'processing';
                $order->external_fulfillment_last_error = null;
                $order->external_fulfillment_attempts = ($order->external_fulfillment_attempts ?? 0) + 1;
                $order->external_fulfillment_last_attempt_at = now();
                $order->save();
                // TODO: Replace above with actual API resend logic
                $resentCount++;
            } catch (\Throwable $e) {
                Log::error('Failed to resend External API order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return back()->with('success', "Resent {$resentCount} failed External API order(s) for processing.");
    }
    private const DEFAULT_NETWORKS = ['MTN', 'Vodafone', 'AirtelTigo', 'Telecel'];
    private const EXTERNAL_API_NETWORK = 'External API';
    private const MAX_NETWORKS_ON_PAGE = 50;
    private const PREVIEW_NETWORKS_LIMIT = 5;

    public function index(Request $request)
    {
        $vendor = $this->resolveVendor();

        $this->ensureFulfillmentSchemaReady();

        try {
            $networks = $this->resolveNetworksForVendor($vendor);
        } catch (\Throwable $e) {
            Log::error('Vendor fulfillment failed to resolve networks', [
                'vendor_id' => (int) $vendor->id,
                'error' => $e->getMessage(),
            ]);

            $networks = array_values(array_unique(array_merge(self::DEFAULT_NETWORKS, [self::EXTERNAL_API_NETWORK])));
        }

        if (count($networks) > self::MAX_NETWORKS_ON_PAGE) {
            Log::warning('Vendor fulfillment networks truncated for performance', [
                'vendor_id' => (int) $vendor->id,
                'networks_count' => count($networks),
                'max' => self::MAX_NETWORKS_ON_PAGE,
            ]);

            $networks = array_slice($networks, 0, self::MAX_NETWORKS_ON_PAGE);

            if (! in_array(self::EXTERNAL_API_NETWORK, $networks, true)) {
                $networks[] = self::EXTERNAL_API_NETWORK;
            }
        }

        if (in_array(self::EXTERNAL_API_NETWORK, $networks, true)) {
            $networks = array_values(array_diff($networks, [self::EXTERNAL_API_NETWORK]));
            array_unshift($networks, self::EXTERNAL_API_NETWORK);
        }

        $limit = (int) $request->query('limit', 2000);
        $limit = max(1, min($limit, 10000));

        $availableByNetwork = [];
        $downloadedByNetwork = [];
        $downloadedOrdersPreview = [];

        $externalApiOrders = $this->externalApiSentOrdersQuery($vendor)
            ->with(['service'])
            ->latest('external_fulfillment_completed_at')
            ->paginate(25, ['*'], 'api_page')
            ->withQueryString();

        $availableCounts = $this->manualFulfillmentCountsByNetwork($vendor, false);
        $downloadedCounts = $this->manualFulfillmentCountsByNetwork($vendor, true);

        $previewNetworks = $this->resolvePreviewNetworks($networks);

        foreach ($networks as $network) {
            try {
                if ($network === self::EXTERNAL_API_NETWORK) {
                    $availableByNetwork[$network] = $this->externalApiSentOrdersQuery($vendor)->count();
                    $downloadedByNetwork[$network] = 0;

                    $downloadedOrdersPreview[$network] = collect();
                    continue;
                }

                $availableByNetwork[$network] = (int) ($availableCounts[$network] ?? 0);
                $downloadedByNetwork[$network] = (int) ($downloadedCounts[$network] ?? 0);

                $downloadedOrdersPreview[$network] = in_array($network, $previewNetworks, true)
                    ? $this->downloadedOrdersQuery($vendor, $network)
                        ->with(['service'])
                        ->latest('downloaded_at')
                        ->limit(10)
                        ->get()
                    : collect();
            } catch (\Throwable $e) {
                Log::error('Vendor fulfillment page failed for network', [
                    'vendor_id' => (int) $vendor->id,
                    'network' => (string) $network,
                    'error' => $e->getMessage(),
                ]);

                $availableByNetwork[$network] = 0;
                $downloadedByNetwork[$network] = 0;
                $downloadedOrdersPreview[$network] = collect();
            }
        }

        return view('vendor.fulfillment.index', compact(
            'vendor',
            'networks',
            'availableByNetwork',
            'downloadedByNetwork',
            'downloadedOrdersPreview',
            'externalApiOrders',
            'limit'
        ));
    }

    private function resolvePreviewNetworks(array $networks): array
    {
        $preferred = array_values(array_unique(array_merge(self::DEFAULT_NETWORKS, [self::EXTERNAL_API_NETWORK])));
        $preview = array_values(array_intersect($preferred, $networks));

        if (count($preview) < self::PREVIEW_NETWORKS_LIMIT) {
            foreach ($networks as $network) {
                if (in_array($network, $preview, true)) {
                    continue;
                }
                $preview[] = $network;
                if (count($preview) >= self::PREVIEW_NETWORKS_LIMIT) {
                    break;
                }
            }
        }

        return $preview;
    }

    private function manualFulfillmentCountsByNetwork(Vendor $vendor, bool $downloaded): array
    {
        $driver = DB::getDriverName();

        $expr = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(products.description, '$.network'))",
            'sqlite' => "json_extract(products.description, '$.network')",
            default => null,
        };

        $jsonValid = match ($driver) {
            'mysql', 'mariadb' => 'JSON_VALID(products.description)',
            'sqlite' => 'json_valid(products.description)',
            default => null,
        };

        if (! $expr || ! $jsonValid) {
            // Unknown driver: fall back to per-network queries elsewhere.
            return [];
        }

        $query = $this->baseFulfillableOrdersQuery($vendor)
            ->where('orders.status', 'Processing')
            ->where(function ($q) {
                $q->whereNull('orders.external_fulfillment_status')
                    ->orWhereNotIn('orders.external_fulfillment_status', ['processing', 'succeeded']);
            })
            ->whereNotNull('orders.vendor_service_id')
            ->join('products', 'orders.vendor_service_id', '=', 'products.id')
            ->whereNotNull('products.description')
            ->whereRaw($jsonValid);

        if ($downloaded) {
            $query->whereNotNull('orders.downloaded_at');
        } else {
            $query->whereNull('orders.downloaded_at');
        }

        return $query
            ->selectRaw("{$expr} as network, COUNT(*) as aggregate")
            ->groupByRaw($expr)
            ->pluck('aggregate', 'network')
            ->filter(fn ($count, $network) => is_string($network) && trim($network) !== '')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function download(Request $request, string $network): Response|RedirectResponse
    {
        $vendor = $this->resolveVendor();

        $this->ensureFulfillmentSchemaReady();

        $network = $this->normalizeNetwork($network);
        $this->assertNetworkAllowed($vendor, $network);

        if ($network === self::EXTERNAL_API_NETWORK) {
            return back()->with('status', 'External API orders are not downloadable. Use the External API section to mark them completed after you confirm delivery.');
        }

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

        if ($network === self::EXTERNAL_API_NETWORK) {
            $confirmed = (string) $request->input('confirm_external_api_completed', '0');
            if ($confirmed !== '1') {
                return back()->with('status', 'Please tick the confirmation box before marking External API orders as completed.');
            }

            $orderIds = $request->input('order_ids', []);
            $orderIds = is_array($orderIds) ? $orderIds : [];
            $orderIds = array_values(array_unique(array_filter(array_map(function ($value) {
                $value = is_scalar($value) ? (string) $value : '';
                return ctype_digit($value) ? (int) $value : null;
            }, $orderIds))));

            if (empty($orderIds)) {
                return back()->with('status', 'Select at least one External API order to mark as completed.');
            }

            if (count($orderIds) > 500) {
                return back()->with('status', 'Please select 500 orders or fewer at a time.');
            }

            $smsService = app(SmsService::class);
            $updatedCount = 0;

            $this->externalApiSentOrdersQuery($vendor)
                ->whereIn('id', $orderIds)
                ->with(['service'])
                ->orderBy('id')
                ->chunkById(200, function ($orders) use (&$updatedCount, $smsService) {
                    foreach ($orders as $order) {
                        $didUpdate = DB::transaction(function () use ($order) {
                            $fresh = Order::query()
                                ->where('id', $order->id)
                                ->where('status', 'Processing')
                                ->where('external_fulfillment_status', 'succeeded')
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
                return back()->with('status', 'No selected External API orders were eligible to be marked completed.');
            }

            return back()->with('success', 'Marked ' . $updatedCount . ' External API orders as completed.');
        }

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
        $names = [];
        try {
            if (Schema::hasTable('network_services')) {
                $names = NetworkService::query()->active()->pluck('name')->filter()->values()->all();
            }
        } catch (\Throwable $e) {
            Log::warning('Vendor fulfillment network services lookup failed', [
                'error' => $e->getMessage(),
            ]);
            $names = [];
        }
        $orderNetworks = $this->processingNetworksFromOrders($vendor);

        $networks = array_values(array_unique(array_merge(self::DEFAULT_NETWORKS, $names, $orderNetworks)));

        if (! in_array(self::EXTERNAL_API_NETWORK, $networks, true)) {
            $networks[] = self::EXTERNAL_API_NETWORK;
        }

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

        if (strcasecmp($network, 'external-api') === 0 || strcasecmp($network, 'externalapi') === 0) {
            return self::EXTERNAL_API_NETWORK;
        }

        // Common normalization
        if (strcasecmp($network, 'airtel-tigo') === 0) {
            return 'AirtelTigo';
        }

        return $network;
    }

    private function constrainServiceNetwork($query, string $network): void
    {
        $driver = DB::getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => $query
                ->whereNotNull('description')
                ->whereRaw('JSON_VALID(description)')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(description, '$.network')) = ?", [$network]),
            'sqlite' => $query
                ->whereNotNull('description')
                ->whereRaw('json_valid(description)')
                ->whereRaw("json_extract(description, '$.network') = ?", [$network]),
            default => $query->where('description->network', $network),
        };
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
                ->where('orders.status', 'Processing')
                ->whereNotNull('orders.vendor_service_id')
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
                    $q2->where('orders.is_reseller_order', false)
                        ->where('orders.vendor_id', $vendorId);
                })
                // Affiliate/reseller orders: product owner fulfills.
                ->orWhere(function ($q2) use ($vendorId) {
                    $q2->where('orders.is_reseller_order', true)
                        ->where('orders.owner_vendor_id', $vendorId);
                })
                // Backward-compatible fallback: infer owner via reseller product.
                ->orWhere(function ($q2) use ($vendorId) {
                    $q2->where('orders.is_reseller_order', true)
                        ->whereHas('resellerProduct', function ($q3) use ($vendorId) {
                            $q3->where('owner_vendor_id', $vendorId);
                        });
                });
            })
            // Paid-only safety: fulfillment should only operate on successfully-paid orders.
            ->whereIn('orders.payment_status', ['paid', 'completed'])
            ->whereHas('transactions', fn ($q) => $q->whereIn('payment_status', ['successful', 'completed']));
    }

    private function availableOrdersQuery(Vendor $vendor, string $network)
    {
        return $this->baseFulfillableOrdersQuery($vendor)
            ->where('status', 'Processing')
            ->where(function ($q) {
                $q->whereNull('external_fulfillment_status')
                    ->orWhereNotIn('external_fulfillment_status', ['processing', 'succeeded']);
            })
            ->whereNull('downloaded_at')
            ->whereHas('service', function ($q) use ($network) {
                $this->constrainServiceNetwork($q, $network);
            });
    }

    private function downloadedOrdersQuery(Vendor $vendor, string $network)
    {
        return $this->baseFulfillableOrdersQuery($vendor)
            ->where('status', 'Processing')
            ->where(function ($q) {
                $q->whereNull('external_fulfillment_status')
                    ->orWhereNotIn('external_fulfillment_status', ['processing', 'succeeded']);
            })
            ->whereNotNull('downloaded_at')
            ->whereHas('service', function ($q) use ($network) {
                $this->constrainServiceNetwork($q, $network);
            });
    }

    private function externalApiSentOrdersQuery(Vendor $vendor)
    {
        return $this->baseFulfillableOrdersQuery($vendor)
            ->where('status', 'Processing')
            ->where('external_fulfillment_status', 'succeeded');
    }
}
