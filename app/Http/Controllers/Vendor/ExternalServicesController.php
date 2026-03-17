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
        $provider = $config->provider ?? 'datafyhub';

        if (! $config->isReady()) {
            return response()->json([
                'provider' => $provider,
                'services' => [],
            ]);
        }

        try {
            $client = ExternalFulfillmentClientFactory::make($config);
            $services = $client->getServices();

            return response()->json([
                'provider' => $provider,
                'services' => is_array($services) ? $services : [],
            ]);
        } catch (\Throwable $e) {
            Log::warning('External services discovery failed', [
                'vendor_id' => $vendor->id,
                'provider' => $provider,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return response()->json([
                'provider' => $provider,
                'services' => [],
                'message' => 'Unable to fetch provider services right now.',
            ], 200);
        }
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
