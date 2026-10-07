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
 * enablement and vendor commission, plus the provider API key (write-only: stored encrypted,
 * never displayed; the page shows only its source and last four characters).
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

        // What the PROVIDER has actually paid XTRA4U per biller on completed orders (observed, not a promise).
        $observed = UtilityBillOrder::query()
            ->where('fulfillment_status', FulfillmentStatus::COMPLETED)
            ->whereNotNull('provider_commission_earned')
            ->selectRaw('biller_key, COUNT(*) as n, SUM(provider_commission_earned) as provider_total, SUM(bill_amount) as bill_total')
            ->groupBy('biller_key')
            ->get()
            ->keyBy('biller_key');

        foreach ($rows as $key => &$row) {
            $config = $configs->get($key);
            $row['economics'] = $this->economics($observed->get($key), $config?->commission_type, $config?->commission_value);
            $row['is_enabled'] = (bool) ($config?->is_enabled);
            $row['commission_type'] = $config?->commission_type ?? 'percentage';
            $row['commission_value'] = $config ? rtrim(rtrim(number_format((float) $config->commission_value, 4, '.', ''), '0'), '.') : '0';
            if ($row['commission_value'] === '') {
                $row['commission_value'] = '0';
            }
        }
        unset($row);

        return view('admin.utility_bills.settings', [
            'credentials' => \App\Services\UtilityBills\UtilityBillCredentials::describe(),
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

    /**
     * Display-only comparison of the configured vendor commission with what the provider has
     * actually paid XTRA4U on completed orders. Never blocks anything and never implies that
     * provider commission is guaranteed profit.
     *
     * @return array{n:int, observed_pct:?string, observed_avg:?string, warn:bool}
     */
    private function economics(mixed $observed, ?string $type, mixed $value): array
    {
        if (! $observed || (int) $observed->n === 0) {
            return ['n' => 0, 'observed_pct' => null, 'observed_avg' => null, 'warn' => false];
        }

        $n = (int) $observed->n;
        $providerMinor = \App\Support\Money::minor($observed->provider_total);
        $billMinor = \App\Support\Money::minor($observed->bill_total);

        $pctHundredths = $billMinor > 0 ? intdiv($providerMinor * 10000, $billMinor) : 0;   // percent x 100
        $avgMinor = intdiv($providerMinor, $n);

        $vendorPctHundredths = (int) round(((float) $value) * 100);
        $vendorFixedMinor = \App\Support\Money::minor($value);

        $warn = $type === 'fixed'
            ? $vendorFixedMinor > $avgMinor
            : $vendorPctHundredths > $pctHundredths;

        return [
            'n' => $n,
            'observed_pct' => number_format($pctHundredths / 100, 2, '.', ''),
            'observed_avg' => number_format($avgMinor / 100, 2, '.', ''),
            'warn' => (float) $value > 0 && $warn,
        ];
    }

    public function updateCredentials(\Illuminate\Http\Request $request)
    {
        $user = \App\Support\AdminAccess::resolve();
        $actor = ['id' => $user?->id, 'email' => $user?->email, 'ip' => $request->ip()];

        if ($request->input('action') === 'clear') {
            \App\Services\UtilityBills\UtilityBillCredentials::save(null, $actor);
            Cache::forget('utility_bills.catalog');

            return redirect()->route('admin.utility-bills.settings')
                ->with('success', 'Saved key removed. The server .env key (if any) is used again.');
        }

        $data = $request->validate(['api_key' => ['required', 'string', 'max:255']]);
        $key = trim($data['api_key']);

        if (! \App\Services\UtilityBills\UtilityBillCredentials::isValidFormat($key)) {
            return back()->withErrors(['api_key' => 'Enter a KiNG FLEXY Commission key (it starts with kf_cs_). Normal data keys do not work for utilities.']);
        }

        \App\Services\UtilityBills\UtilityBillCredentials::save($key, $actor);
        Cache::forget('utility_bills.catalog');

        return redirect()->route('admin.utility-bills.settings')->with('success', 'API key saved (encrypted).');
    }

    public function update(UpdateUtilityBillSettingsRequest $request, UtilityBillConfigService $config, KingFlexyUtilityProvider $provider)
    {
        $data = $request->validated();
        $user = \App\Support\AdminAccess::resolve();
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
