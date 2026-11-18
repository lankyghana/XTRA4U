<?php

namespace App\Observers;

use App\Models\Vendor;
use App\Services\DeveloperApi\VendorApiAccess;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\Log;

/**
 * Hooks the EXISTING vendor approval workflow into Developer API access without changing it:
 * whenever an approved vendor stops being approved (suspended/withdrawn) or is deleted, every API
 * key it owns is revoked and its webhooks are disabled, and the action is audited.
 *
 * Approving a vendor needs no hook: eligibility is read live from `is_approved`.
 */
class VendorApiAccessObserver
{
    public function __construct(private readonly VendorApiAccess $access) {}

    public function updated(Vendor $vendor): void
    {
        if ($vendor->wasChanged('is_approved') && $vendor->is_approved !== true) {
            $this->safely($vendor, 'vendor approval withdrawn');
        }
    }

    public function deleted(Vendor $vendor): void
    {
        $this->safely($vendor, 'vendor deleted');
    }

    /**
     * A failure here must NEVER block the platform's own vendor suspension/removal. Access is still
     * denied live on every request (the vendor is no longer approved), and the scheduled reconciliation
     * retries the cleanup within a minute.
     */
    private function safely(Vendor $vendor, string $reason): void
    {
        try {
            $this->access->withdraw($vendor->id, $reason, $this->actor());
        } catch (\Throwable $e) {
            Log::error('Developer API access withdrawal failed; reconciliation will retry', [
                'vendor_id' => $vendor->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Best-effort identity of whoever triggered it; "system" when it is not an admin request. */
    private function actor(): string
    {
        try {
            $admin = AdminAccess::resolve();

            return $admin ? (($admin instanceof \App\Models\Admin ? 'admin:' : 'user:').$admin->getKey()) : 'system';
        } catch (\Throwable) {
            return 'system';
        }
    }
}
