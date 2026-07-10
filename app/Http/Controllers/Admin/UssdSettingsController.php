<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUssdSettingsRequest;
use App\Models\Setting;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use App\Services\Ussd\UssdConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class UssdSettingsController extends Controller
{
    public function __construct(private readonly UssdConfig $config) {}

    public function index()
    {
        $settings = Setting::getGroup('ussd');
        $vendors = Vendor::where('is_approved', true)->orderBy('name')->get(['id', 'name', 'vendor_code']);

        // The stored secret is encrypted and must never round-trip to the
        // browser. Expose only whether one is configured.
        unset($settings['ussd_gateway_secret']);
        $hasGatewaySecret = $this->config->hasGatewaySecret();

        return view('admin.settings.ussd', compact('settings', 'vendors', 'hasGatewaySecret'));
    }

    public function update(UpdateUssdSettingsRequest $request)
    {
        $validated = $request->validated();

        // Preserved behaviour: an absent checkbox disables the channel.
        $map = [
            'ussd_enabled' => $request->has('ussd_enabled') ? '1' : '0',
            'ussd_service_code' => $request->input('ussd_service_code', ''),
            'ussd_welcome_message' => $request->input('ussd_welcome_message'),
            'ussd_support_number' => $request->input('ussd_support_number', ''),
            'ussd_default_vendor_id' => $request->input('ussd_default_vendor_id', ''),
        ];

        // New keys are only written when submitted, so a partial update from an
        // older form never clobbers them with defaults.
        $optional = [
            'ussd_base_code',
            'ussd_provider',
            'ussd_session_timeout_seconds',
            'ussd_max_requests_per_session',
            'ussd_max_retry_attempts',
            'ussd_gateway_ip_allowlist',
        ];

        foreach ($optional as $key) {
            if (array_key_exists($key, $validated)) {
                $map[$key] = (string) ($validated[$key] ?? '');
            }
        }

        foreach ($map as $key => $value) {
            Setting::set($key, $value, 'ussd');
        }

        $this->persistGatewaySecret($request);

        Setting::clearCache();

        UssdSubscriptionEvent::create([
            'event' => UssdSubscriptionEvent::ADMIN_SETTINGS_UPDATED,
            'description' => 'USSD settings updated.',
            'context' => ['keys' => array_keys($map)],
            'actor_type' => 'admin',
            'actor_id' => (Auth::guard('admin')->user() ?: Auth::user())?->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.settings.ussd')
            ->with('success', 'USSD settings updated successfully.');
    }

    /**
     * The secret is write-only: a blank field leaves the existing value alone,
     * so re-saving the form without retyping it does not wipe the credential.
     */
    private function persistGatewaySecret(UpdateUssdSettingsRequest $request): void
    {
        if ($request->boolean('ussd_gateway_secret_clear')) {
            Setting::set('ussd_gateway_secret', '', 'ussd');

            return;
        }

        $secret = $request->input('ussd_gateway_secret');
        if (is_string($secret) && trim($secret) !== '') {
            Setting::set('ussd_gateway_secret', Crypt::encryptString(trim($secret)), 'ussd');
        }
    }
}
