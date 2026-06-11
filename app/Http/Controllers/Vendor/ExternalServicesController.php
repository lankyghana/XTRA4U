<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\ExternalFulfillmentClientFactory;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ExternalServicesController extends Controller
{
    public function index(): JsonResponse
    {
        $vendor = $this->resolveVendor();

        $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);

        if (! $config->isServiceDiscoveryReady()) {
            return response()->json([
                'provider' => 'multiple',
                'services' => [],
                'message' => 'No external fulfillment providers are configured or ready.',
            ]);
        }

        $allServices = [];
        $errors = [];

        foreach (array_keys($config->providers) as $p) {
            if ($config->isProviderServiceDiscoveryReady($p)) {
                try {
                    $client = ExternalFulfillmentClientFactory::make($config, $p);
                    $services = $client->getServices();
                    if (is_array($services)) {
                        foreach ($services as &$svc) {
                            $originalId = $svc['id'] ?? $svc['service_id'] ?? $svc['code'] ?? $svc['key'] ?? null;
                            if ($originalId !== null) {
                                $svc['id'] = $p . '::' . $originalId;
                            }
                            $svc['name'] = '[' . strtoupper($p) . '] ' . ($svc['name'] ?? 'Unknown');
                        }
                        $allServices = array_merge($allServices, $services);
                    }
                } catch (\Throwable $e) {
                    Log::warning('External services discovery failed for provider', [
                        'vendor_id' => $vendor->id,
                        'provider' => $p,
                        'error' => mb_substr($e->getMessage(), 0, 500),
                    ]);
                    $errors[] = $p;
                }
            }
        }

        $message = null;
        if (!empty($errors)) {
            $message = 'Failed to load services for: ' . implode(', ', $errors) . '.';
        }

        return response()->json([
            'provider' => 'multiple',
            'services' => $allServices,
            'message' => $message,
        ]);
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
}
