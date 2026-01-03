<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class ExternalFulfillmentSettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::getGroup('external_fulfillment');

        if (! empty($settings['external_fulfillment_token'])) {
            $settings['external_fulfillment_token_masked'] = '••••••••';
        }

        return view('admin.settings.external-fulfillment', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'external_fulfillment_enabled' => 'nullable|boolean',
            'external_fulfillment_token' => 'nullable|string|max:255',
            'external_fulfillment_timeout_seconds' => 'nullable|integer|min:1|max:120',
        ]);

        $enabled = (bool) $request->boolean('external_fulfillment_enabled');

        if ($enabled) {
            $existingToken = trim((string) Setting::get('external_fulfillment_token', ''));
            if (! $request->filled('external_fulfillment_token') && $existingToken === '') {
                return back()
                    ->withErrors(['external_fulfillment_token' => 'API token is required when enabling external fulfillment.'])
                    ->withInput();
            }
        }

        $payload = [
            'external_fulfillment_enabled' => $enabled ? '1' : '0',
            'external_fulfillment_timeout_seconds' => (string) ((int) ($request->input('external_fulfillment_timeout_seconds') ?? 10)),
        ];

        foreach ($payload as $key => $value) {
            Setting::set($key, $value, 'external_fulfillment');
        }

        if ($request->filled('external_fulfillment_token')) {
            Setting::set('external_fulfillment_token', $request->input('external_fulfillment_token'), 'external_fulfillment');
        }

        Setting::clearCache();

        return redirect()->route('admin.settings.external-fulfillment')
            ->with('success', 'External fulfillment settings updated successfully.');
    }
}
