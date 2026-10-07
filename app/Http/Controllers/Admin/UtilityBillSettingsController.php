<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUtilityBillSettingsRequest;
use App\Models\UtilityBillConfigAudit;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\UtilityBillConfigService;
use App\Services\UtilityBills\UtilityBillSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-owned Utility Bills configuration: the global switch, per-biller
 * enablement and vendor commission. Provider credentials are never shown or
 * editable here (they live in server env/config only).
 */
class UtilityBillSettingsController extends Controller
{
    public function index(KingFlexyUtilityProvider $provider)
    {
        [$catalog, $stale] = $provider->catalogForDisplay();
        $configs = UtilityBillerConfig::query()->get()->keyBy('biller_key');

        $rows = [];
        foreach ($catalog?->billers ?? [] as $biller) {
            $rows[$biller->key] = ['key' => $biller->key, 'label' => $biller->label, 'provider_enabled' => $biller->enabled, 'listed' => true];
        }
        // Keep configured billers visible even if the provider stopped listing them.
        foreach ($configs as $key => $config) {
            $rows[$key] ??= ['key' => $key, 'label' => $key, 'provider_enabled' => false, 'listed' => false];
        }

        foreach ($rows as $key => &$row) {
            $config = $configs->get($key);
            $row['is_enabled'] = (bool) ($config?->is_enabled);
            $row['commission_type'] = $config?->commission_type ?? 'percentage';
            $row['commission_value'] = $config ? rtrim(rtrim(number_format((float) $config->commission_value, 4, '.', ''), '0'), '.') : '0';
            if ($row['commission_value'] === '') {
                $row['commission_value'] = '0';
            }
        }
        unset($row);

        return view('admin.utility_bills.settings', [
            'enabled' => UtilityBillSettings::enabled(),
            'message' => UtilityBillSettings::rawMaintenanceMessage(),
            'rows' => array_values($rows),
            'limits' => ['min' => $catalog?->minAmount, 'max' => $catalog?->maxAmount],
            'health' => [
                'configured' => $provider->isConfigured(),
                'catalog_available' => $catalog !== null && ! $stale,
                'catalog_stale' => $stale,
                'last_catalog_success' => ($ts = Cache::get('utility_bills.catalog.last_success_at')) ? now()->setTimestamp((int) $ts) : null,
                'attention' => UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::ATTENTION)->count(),
                'in_flight' => UtilityBillOrder::query()->whereIn('fulfillment_status', FulfillmentStatus::IN_FLIGHT)->count(),
                'recent_errors' => UtilityBillEvent::query()
                    ->whereIn('kind', ['needs_attention', 'submit_retry_scheduled', 'status_not_found', 'status_contradiction'])
                    ->latest('id')->limit(8)->with('order:id,public_ref')->get(),
            ],
            'audits' => UtilityBillConfigAudit::query()->latest('id')->limit(15)->get(),
        ]);
    }

    public function update(UpdateUtilityBillSettingsRequest $request, UtilityBillConfigService $config, KingFlexyUtilityProvider $provider)
    {
        $data = $request->validated();
        $user = Auth::guard('admin')->user() ?: Auth::user();
        $actor = ['id' => $user?->id, 'email' => $user?->email, 'ip' => $request->ip()];

        // Only billers the provider lists, or that are already configured, may be edited.
        [$catalog] = $provider->catalogForDisplay();
        $known = array_keys($catalog?->billers ?? []) + [];
        $known = array_merge($known, UtilityBillerConfig::query()->pluck('biller_key')->all());

        // Validate every key BEFORE saving anything: a rejected form must change nothing.
        foreach (array_keys($data['billers']) as $key) {
            if (! in_array($key, $known, true)) {
                return back()->withErrors(['billers' => "Unknown biller: {$key}."])->withInput();
            }
        }

        $changes = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $config, $actor) {
            $changes = 0;

            foreach ($data['billers'] as $key => $values) {
                $changes += $config->saveBiller($key, [
                    'is_enabled' => (bool) $values['is_enabled'],
                    'commission_type' => $values['commission_type'],
                    'commission_value' => $values['commission_value'],
                ], $actor) ? 1 : 0;
            }

            return $changes + ($config->saveGlobal((bool) $data['enabled'], $data['maintenance_message'] ?? null, $actor) ? 1 : 0);
        });

        return redirect()->route('admin.utility-bills.settings')
            ->with('success', $changes > 0 ? 'Utility Bills configuration saved.' : 'No changes to save.');
    }
}
