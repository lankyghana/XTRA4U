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
        unset($settings['ussd_gateway_secret'], $settings['ussd_gwosevo_secret']);
        $hasGatewaySecret = $this->config->hasGatewaySecret();
        $hasGwoSevoSecret = $this->config->hasGwoSevoSecret();

        $status = $this->gatewayStatus($vendors);

        return view('admin.settings.ussd', compact('settings', 'vendors', 'hasGatewaySecret', 'hasGwoSevoSecret', 'status'));
    }

    /**
     * Read-only summary for the status panel. Booleans and labels only: no
     * secret or allowlist value leaves this method.
     *
     * @param  \Illuminate\Support\Collection<int, Vendor>  $approvedVendors
     * @return array<string, mixed>
     */
    private function gatewayStatus($approvedVendors): array
    {
        $gwoVendorId = $this->config->gwoSevoVendorId();
        $gwoHasAuth = $this->config->gwoSevoIpAllowlist() !== [] || $this->config->hasGwoSevoSecret();
        $gwoHasVendor = $gwoVendorId !== null && $approvedVendors->contains('id', $gwoVendorId);

        $missing = [];
        if (! $gwoHasAuth) {
            $missing[] = 'an IP allowlist or a shared secret';
        }
        if (! $gwoHasVendor) {
            $missing[] = $gwoVendorId === null ? 'a vendor to serve' : 'an approved vendor (the selected one is not approved)';
        }

        return [
            'master' => $this->config->isEnabled(),
            'legacy_protected' => $this->config->gatewayIpAllowlist() !== [] || $this->config->hasGatewaySecret(),
            'gwosevo_enabled' => $this->config->gwoSevoEnabled(),
            'gwosevo_ready' => $missing === [],
            'gwosevo_missing' => $missing,
        ];
    }

    public function update(UpdateUssdSettingsRequest $request)
    {
        $validated = $request->validated();

        // Preserved behaviour: an absent checkbox disables the channel.
        $map = [
            'ussd_enabled' => $request->has('ussd_enabled') ? '1' : '0',
            'ussd_welcome_message' => $request->input('ussd_welcome_message'),
        ];

        // Moolre routing and display keys are likewise written only when
        // submitted, so a partial save (e.g. one touching only GWO SEVO) never
        // clears the Moolre default vendor. The admin form always submits them.
        foreach (['ussd_service_code', 'ussd_support_number', 'ussd_default_vendor_id'] as $key) {
            if ($request->has($key)) {
                $map[$key] = (string) $request->input($key, '');
            }
        }

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
        $this->persistGwoSevoSettings($request, $validated);

        Setting::clearCache();

        UssdSubscriptionEvent::create([
            'event' => UssdSubscriptionEvent::ADMIN_SETTINGS_UPDATED,
            'description' => 'USSD settings updated.',
            'context' => ['keys' => array_keys($map)],
            'actor_type' => 'admin',
            'actor_id' => \App\Support\AdminAccess::resolve()?->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.settings.ussd')
            ->with('success', 'USSD settings updated successfully.');
    }

    /**
     * GWO SEVO keys are written only when submitted, so a partial update from
     * an older form never disables the gateway or wipes its credentials.
     *
     * @param  array<string, mixed>  $validated
     */
    private function persistGwoSevoSettings(UpdateUssdSettingsRequest $request, array $validated): void
    {
        if (array_key_exists('ussd_gwosevo_enabled', $validated)) {
            Setting::set('ussd_gwosevo_enabled', $request->boolean('ussd_gwosevo_enabled') ? '1' : '0', 'ussd');
        }

        foreach (['ussd_gwosevo_vendor_id', 'ussd_gwosevo_ip_allowlist'] as $key) {
            if (array_key_exists($key, $validated)) {
                Setting::set($key, (string) ($validated[$key] ?? ''), 'ussd');
            }
        }

        if ($request->boolean('ussd_gwosevo_secret_clear')) {
            Setting::set('ussd_gwosevo_secret', '', 'ussd');

            return;
        }

        $secret = $request->input('ussd_gwosevo_secret');
        if (is_string($secret) && trim($secret) !== '') {
            Setting::set('ussd_gwosevo_secret', Crypt::encryptString(trim($secret)), 'ussd');
        }
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
