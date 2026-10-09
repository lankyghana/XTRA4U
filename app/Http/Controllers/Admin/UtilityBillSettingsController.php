<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUtilityBillSettingsRequest;
use App\Http\Traits\SecureFileUpload;
use App\Models\UtilityBillConfigAudit;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\UtilityBillConfigService;
use App\Services\UtilityBills\UtilityBillCredentials;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSettings;
use App\Support\AdminAccess;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin-owned Utility Bills configuration: the global switch, per-biller
 * enablement and vendor commission, plus the provider API key (write-only: stored encrypted,
 * never displayed; the page shows only its source and last four characters).
 */
class UtilityBillSettingsController extends Controller
{
    use SecureFileUpload;

    public function index(KingFlexyUtilityProvider $provider)
    {
        [$catalog, $stale] = $provider->catalogForDisplay();
        $configs = UtilityBillerConfig::query()->get()->keyBy('biller_key');

        $rows = [];
        foreach ($catalog?->billers ?? [] as $biller) {
            $rows[$biller->key] = ['key' => $biller->key, 'label' => $biller->label, 'account_label' => $biller->accountLabel, 'provider_enabled' => $biller->enabled, 'listed' => true];
        }
        // Keep configured billers visible even if the provider stopped listing them.
        foreach ($configs as $key => $config) {
            $rows[$key] ??= ['key' => $key, 'label' => $key, 'account_label' => null, 'provider_enabled' => false, 'listed' => false];
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

        $labels = collect($rows)->mapWithKeys(fn ($r) => [$r['key'] => $r['label']])->all();
        $configured = $provider->isConfigured();
        $catalogAvailable = $catalog !== null && ! $stale;

        return view('admin.utility_bills.settings', [
            // Display-only classification of the provider connection.
            'connection' => match (true) {
                ! $configured => 'not_configured',
                $provider->lastCatalogFailure === 'rejected' => 'rejected',
                $catalogAvailable => 'connected',
                default => 'unavailable',
            },
            'credentials' => UtilityBillCredentials::describe(),
            'enabled' => UtilityBillSettings::enabled(),
            'message' => UtilityBillSettings::rawMaintenanceMessage(),
            'imageUrl' => UtilityBillSettings::imageUrl(),
            'walletPausedAt' => UtilityBillSettings::providerWalletPausedAt(),
            'rows' => array_values($rows),
            'limits' => ['min' => $catalog?->minAmount, 'max' => $catalog?->maxAmount],
            'health' => [
                'configured' => $configured,
                'catalog_available' => $catalogAvailable,
                'catalog_stale' => $stale,
                'last_catalog_success' => ($ts = Cache::get('utility_bills.catalog.last_success_at')) ? now()->setTimestamp((int) $ts) : null,
                'attention' => UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::ATTENTION)->count(),
                'in_flight' => UtilityBillOrder::query()->whereIn('fulfillment_status', FulfillmentStatus::IN_FLIGHT)->count(),
                'recent_errors' => UtilityBillEvent::query()
                    ->whereIn('kind', ['needs_attention', 'submit_retry_scheduled', 'status_not_found', 'status_contradiction'])
                    ->latest('id')->limit(8)->with('order:id,public_ref')->get(),
            ],
            'audits' => UtilityBillConfigAudit::query()->latest('id')->limit(30)->get()
                ->map(fn (UtilityBillConfigAudit $a) => $this->presentAudit($a, $labels))->all(),
        ]);
    }

    /**
     * Turns one audit row into readable lines. Display only; the raw values stay available
     * to the view for an optional details disclosure.
     *
     * @param  array<string,string>  $labels  biller key => label
     * @return array{when:?Carbon, who:string, title:string, lines:list<string>, raw:string}
     */
    private function presentAudit(UtilityBillConfigAudit $a, array $labels): array
    {
        $old = (array) $a->old_values;
        $new = (array) $a->new_values;
        $lines = [];
        $onOff = fn ($v) => $v ? 'Enabled' : 'Disabled';
        $commission = function (array $v): string {
            $n = rtrim(rtrim(number_format((float) ($v['commission_value'] ?? 0), 4, '.', ''), '0'), '.') ?: '0';

            return ($v['commission_type'] ?? 'percentage') === 'fixed'
                ? 'GHS '.number_format((float) $n, 2, '.', '')
                : $n.'%';
        };

        if ($a->scope === 'global') {
            if (($old['enabled'] ?? null) !== ($new['enabled'] ?? null)) {
                $title = ! empty($new['enabled']) ? 'Utility Bills enabled' : 'Utility Bills disabled';
            } else {
                $title = 'Customer message updated';
            }
            if (($old['maintenance_message'] ?? '') !== ($new['maintenance_message'] ?? '')) {
                $lines[] = ($new['maintenance_message'] ?? '') === ''
                    ? 'Customer message cleared'
                    : 'Customer message: “'.Str::limit((string) $new['maintenance_message'], 80).'”';
            }
        } elseif ($a->scope === 'wallet') {
            $title = 'Sales resumed after provider wallet pause';
            $lines[] = ($new['orders_due_now'] ?? 0).' waiting order(s) retried now';
        } elseif ($a->scope === 'image') {
            $title = ($new['image_path'] ?? '') === '' ? 'Storefront image removed' : 'Storefront image updated';
        } elseif ($a->scope === 'credentials') {
            $title = ($new['source'] ?? 'none') === 'admin' ? 'Provider API key changed' : 'Saved API key removed';
            $lines[] = ($new['source'] ?? 'none') === 'admin' ? 'Now using the key saved in Admin' : 'Now using the environment key';
        } else {
            $name = $labels[$a->biller_key] ?? strtoupper((string) $a->biller_key);
            $title = $name.' updated';
            if (($old['is_enabled'] ?? null) !== ($new['is_enabled'] ?? null)) {
                $lines[] = 'Status: '.$onOff($old['is_enabled'] ?? false).' → '.$onOff($new['is_enabled'] ?? false);
            }
            if ($commission($old) !== $commission($new)) {
                $lines[] = 'Vendor commission: '.$commission($old).' → '.$commission($new);
            }
        }

        return [
            'when' => $a->created_at,
            'who' => $a->admin_email ?? ($a->admin_id ? 'admin #'.$a->admin_id : 'system'),
            'title' => $title,
            'lines' => $lines,
            'raw' => json_encode(['before' => $old, 'after' => $new], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
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
        $providerMinor = Money::minor($observed->provider_total);
        $billMinor = Money::minor($observed->bill_total);

        $pctHundredths = $billMinor > 0 ? intdiv($providerMinor * 10000, $billMinor) : 0;   // percent x 100
        $avgMinor = intdiv($providerMinor, $n);

        $vendorPctHundredths = (int) round(((float) $value) * 100);
        $vendorFixedMinor = Money::minor($value);

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

    public function updateCredentials(Request $request)
    {
        $user = AdminAccess::resolve();
        $actor = ['id' => $user?->id, 'email' => $user?->email, 'ip' => $request->ip()];

        if ($request->input('action') === 'clear') {
            UtilityBillCredentials::save(null, $actor);
            app(KingFlexyUtilityProvider::class)->forgetCatalog();

            return redirect()->route('admin.utility-bills.settings')
                ->with('success', 'Saved key removed. The server .env key (if any) is used again.');
        }

        $data = $request->validate(['api_key' => ['required', 'string', 'max:255']]);
        $key = trim($data['api_key']);

        if (! UtilityBillCredentials::isValidFormat($key)) {
            return back()->withErrors(['api_key' => 'Enter a KiNG FLEXY Commission key (it starts with kf_cs_). Normal data keys do not work for utilities.']);
        }

        UtilityBillCredentials::save($key, $actor);
        app(KingFlexyUtilityProvider::class)->forgetCatalog();

        return redirect()->route('admin.utility-bills.settings')->with('success', 'API key saved (encrypted).');
    }

    /** Storefront card image: upload (replaces the old file) or remove. */
    /** Admin ends a provider-wallet pause (after topping up KiNG FLEXY): reopen sales and retry waiting orders now. */
    public function resumeAfterWallet(Request $request, UtilityBillFulfillmentService $fulfillment)
    {
        $user = AdminAccess::resolve();

        if (UtilityBillSettings::providerWalletPausedAt() === null) {
            return redirect()->route('admin.utility-bills.settings')->with('success', 'Sales are not paused.');
        }

        $woken = $fulfillment->resumeAfterProviderWallet('admin:'.($user?->id ?? '?'));

        UtilityBillConfigAudit::create([
            'admin_id' => $user?->id,
            'admin_email' => $user?->email,
            'scope' => 'wallet',
            'biller_key' => null,
            'old_values' => ['paused' => true],
            'new_values' => ['paused' => false, 'orders_due_now' => $woken],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.utility-bills.settings')
            ->with('success', 'Utility Bills sales resumed. '.$woken.' waiting '.Str::plural('order', $woken).' will retry now.');
    }

    public function updateImage(Request $request)
    {
        $user = AdminAccess::resolve();
        $old = UtilityBillSettings::imagePath();

        if ($request->input('action') === 'remove') {
            $new = '';
        } else {
            $request->validate(['image' => $this->imageValidationRules(true)]);
            $new = $this->secureImageUpload($request->file('image'), 'utility-bills', 'public');

            if ($new === null) {
                return back()->withErrors(['image' => 'That file is not a valid JPEG, PNG, GIF or WebP image (max 2MB).']);
            }
        }

        DB::transaction(function () use ($old, $new, $user, $request) {
            UtilityBillSettings::saveImagePath($new);
            UtilityBillConfigAudit::create([
                'admin_id' => $user?->id,
                'admin_email' => $user?->email,
                'scope' => 'image',
                'biller_key' => null,
                'old_values' => ['image_path' => $old],
                'new_values' => ['image_path' => $new],
                'ip_address' => $request->ip(),
            ]);
        });

        // Only ever delete a file this feature stored.
        if ($old !== '' && $old !== $new && str_starts_with($old, 'utility-bills/')) {
            Storage::disk('public')->delete($old);
        }

        return redirect()->route('admin.utility-bills.settings')
            ->with('success', $new === '' ? 'Storefront image removed.' : 'Storefront image updated.');
    }

    public function update(UpdateUtilityBillSettingsRequest $request, UtilityBillConfigService $config, KingFlexyUtilityProvider $provider)
    {
        $data = $request->validated();
        $user = AdminAccess::resolve();
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

        $changes = DB::transaction(function () use ($data, $config, $actor) {
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
