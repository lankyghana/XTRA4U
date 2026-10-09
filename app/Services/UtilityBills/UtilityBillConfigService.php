<?php

namespace App\Services\UtilityBills;

use App\Models\UtilityBillConfigAudit;
use App\Models\UtilityBillerConfig;
use Illuminate\Support\Facades\DB;

/**
 * Admin writes to Utility Bills configuration. Every change that actually
 * alters a value is audited (who, what, old -> new). Orders already created are
 * unaffected by design: they carry frozen copies of the terms.
 */
class UtilityBillConfigService
{
    /**
     * @param  array{id:?int,email:?string,ip:?string}  $actor
     */
    public function saveGlobal(bool $enabled, ?string $message, array $actor): bool
    {
        $old = [
            'enabled' => UtilityBillSettings::enabled(),
            'maintenance_message' => UtilityBillSettings::rawMaintenanceMessage(),
        ];
        $new = ['enabled' => $enabled, 'maintenance_message' => trim((string) $message)];

        if ($old === $new) {
            return false;
        }

        DB::transaction(function () use ($enabled, $message, $old, $new, $actor) {
            UtilityBillSettings::save($enabled, $message);
            $this->audit('global', null, $old, $new, $actor);
        });

        return true;
    }

    /**
     * @param  array{is_enabled:bool,commission_type:string,commission_value:string}  $values
     * @param  array{id:?int,email:?string,ip:?string}  $actor
     */
    public function saveBiller(string $billerKey, array $values, array $actor): bool
    {
        return DB::transaction(function () use ($billerKey, $values, $actor) {
            $config = UtilityBillerConfig::query()->where('biller_key', $billerKey)->lockForUpdate()->first();

            $old = $config
                ? $this->snapshot($config)
                : ['is_enabled' => false, 'commission_type' => VendorCommission::TYPE_PERCENTAGE, 'commission_value' => '0.0000'];

            $new = [
                'is_enabled' => (bool) $values['is_enabled'],
                'commission_type' => $values['commission_type'],
                'commission_value' => number_format((float) $values['commission_value'], 4, '.', ''),
            ];

            if ($old === $new) {
                return false;
            }

            $config ??= new UtilityBillerConfig(['biller_key' => $billerKey]);
            $config->fill($new + ['updated_by_admin_id' => $actor['id'] ?? null])->save();

            $this->audit('biller', $billerKey, $old, $new, $actor);

            return true;
        });
    }

    private function snapshot(UtilityBillerConfig $config): array
    {
        return [
            'is_enabled' => (bool) $config->is_enabled,
            'commission_type' => $config->commission_type,
            'commission_value' => number_format((float) $config->commission_value, 4, '.', ''),
        ];
    }

    private function audit(string $scope, ?string $billerKey, array $old, array $new, array $actor): void
    {
        UtilityBillConfigAudit::create([
            'admin_id' => $actor['id'] ?? null,
            'admin_email' => $actor['email'] ?? null,
            'scope' => $scope,
            'biller_key' => $billerKey,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $actor['ip'] ?? null,
        ]);
    }
}
