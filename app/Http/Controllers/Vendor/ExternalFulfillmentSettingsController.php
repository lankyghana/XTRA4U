<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorSetting;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class ExternalFulfillmentSettingsController extends Controller
{
    public function index()
    {
        $vendor = $this->resolveVendor();

        abort_if(! is_null($vendor->affiliate_vendor_id), 403, 'External fulfillment settings are only available for root vendors.');

        $settings = VendorSetting::getGroupForVendor($vendor->id, 'external_fulfillment');

        if (! empty($settings['external_fulfillment_token'])) {
            $settings['external_fulfillment_token_masked'] = '••••••••';
        }

        if (! empty($settings['external_fulfillment.xpres.api_key'])) {
            $settings['external_fulfillment_xpres_api_key_masked'] = '••••••••';
        }

        if (! empty($settings['external_fulfillment.xpres.api_secret'])) {
            $settings['external_fulfillment_xpres_api_secret_masked'] = '••••••••';
        }

        return view('vendor.settings.external-fulfillment', [
            'vendor' => $vendor,
            'settings' => $settings,
            'providers' => [
                'datafyhub' => 'Datafyhub',
                'xpresportal' => 'XpresPortal',
                'gigshub' => 'GigsHub',
            ],
        ]);
    }

    public function update(Request $request)
    {
        $vendor = $this->resolveVendor();

        abort_if(! is_null($vendor->affiliate_vendor_id), 403, 'External fulfillment settings are only available for root vendors.');

        $validated = $request->validate([
            'external_fulfillment_enabled' => 'nullable|boolean',
            'external_fulfillment_token' => 'nullable|string|max:255',
            'external_fulfillment_timeout_seconds' => 'nullable|integer|min:1|max:120',
            'external_fulfillment_provider' => 'nullable|string|in:datafyhub,xpresportal,gigshub',
            'external_fulfillment_xpres_base_url' => 'nullable|string|max:255',
            'external_fulfillment_xpres_api_key' => 'nullable|string|max:255',
            'external_fulfillment_xpres_api_secret' => 'nullable|string|max:255',
            'external_fulfillment_xpres_environment' => 'nullable|string|in:sandbox,production',
        ]);

        $xpresBaseUrl = trim((string) ($request->input('external_fulfillment_xpres_base_url') ?? ''));
        if ($xpresBaseUrl !== '' && ! filter_var($xpresBaseUrl, FILTER_VALIDATE_URL)) {
            return back()
                ->withErrors(['external_fulfillment_xpres_base_url' => 'Xpres base URL must be a valid URL.'])
                ->withInput();
        }

        $enabled = (bool) $request->boolean('external_fulfillment_enabled');
        $provider = (string) ($request->input('external_fulfillment_provider') ?? 'datafyhub');

        if ($enabled && $provider === 'datafyhub') {
            $existingToken = trim((string) VendorSetting::getForVendor($vendor->id, 'external_fulfillment_token', ''));
            if (! $request->filled('external_fulfillment_token') && $existingToken === '') {
                return back()
                    ->withErrors(['external_fulfillment_token' => 'API token is required when enabling external fulfillment.'])
                    ->withInput();
            }
        }

        if ($enabled && $provider === 'xpresportal') {
            if (! $request->filled('external_fulfillment_xpres_api_key')) {
                return back()
                    ->withErrors(['external_fulfillment_xpres_api_key' => 'XpresPortal API Key is required. Each vendor must use their own credentials.'])
                    ->withInput();
            }
            if (! $request->filled('external_fulfillment_xpres_base_url')) {
                return back()
                    ->withErrors(['external_fulfillment_xpres_base_url' => 'XpresPortal Base URL is required.'])
                    ->withInput();
            }
        }

        VendorSetting::setForVendor($vendor->id, 'external_fulfillment_enabled', $enabled ? '1' : '0', 'external_fulfillment');
        VendorSetting::setForVendor($vendor->id, 'external_fulfillment_provider', $provider, 'external_fulfillment');
        VendorSetting::setForVendor(
            $vendor->id,
            'external_fulfillment_timeout_seconds',
            (string) ((int) ($request->input('external_fulfillment_timeout_seconds') ?? 10)),
            'external_fulfillment'
        );

        if ($request->filled('external_fulfillment_token')) {
            VendorSetting::setForVendor(
                $vendor->id,
                'external_fulfillment_token',
                $request->input('external_fulfillment_token'),
                'external_fulfillment'
            );
        }

        VendorSetting::setForVendor(
            $vendor->id,
            'external_fulfillment.xpres.base_url',
            $xpresBaseUrl,
            'external_fulfillment'
        );

        VendorSetting::setForVendor(
            $vendor->id,
            'external_fulfillment.xpres.environment',
            trim((string) ($request->input('external_fulfillment_xpres_environment') ?? '')),
            'external_fulfillment'
        );

        if ($request->filled('external_fulfillment_xpres_api_key')) {
            VendorSetting::setForVendor(
                $vendor->id,
                'external_fulfillment.xpres.api_key',
                Crypt::encryptString((string) $request->input('external_fulfillment_xpres_api_key')),
                'external_fulfillment'
            );
        }

        if ($request->filled('external_fulfillment_xpres_api_secret')) {
            VendorSetting::setForVendor(
                $vendor->id,
                'external_fulfillment.xpres.api_secret',
                Crypt::encryptString((string) $request->input('external_fulfillment_xpres_api_secret')),
                'external_fulfillment'
            );
        }

        return redirect()->route('vendor.settings.external-fulfillment')
            ->with('success', 'External fulfillment settings updated successfully.');
    }

    public function testXpresConnection(Request $request)
    {
        $vendor = $this->resolveVendor();

        abort_if(! is_null($vendor->affiliate_vendor_id), 403, 'External fulfillment settings are only available for root vendors.');

        $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);
        $providerConfig = $config->providerConfig('xpresportal');

        $baseUrl = trim((string) ($providerConfig['base_url'] ?? ''));
        $apiKey = (string) ($providerConfig['api_key'] ?? '');
        $apiSecret = (string) ($providerConfig['api_secret'] ?? '');
        $environment = (string) ($providerConfig['environment'] ?? 'sandbox');
        $timeout = (int) ($providerConfig['timeout_seconds'] ?? 10);

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Xpres credentials are incomplete.',
            ]);
        }

        $headers = [
            'x-api-key' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($apiSecret !== '') {
            $headers['x-api-secret'] = $apiSecret;
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($headers)
                ->get(rtrim($baseUrl, '/') . '/api/v1/offers');

            return response()->json([
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Connection successful.'
                    : 'Connection failed. Check URL or credentials.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed. Check URL or credentials.',
            ]);
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
