<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperPlan;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every state change of an application goes through here.
 *
 *  1. An application belongs to the AUTHENTICATED vendor, taken from the caller's identity and never from
 *     request input; it cannot be created for, moved to, or edited into another vendor.
 *  2. Only an approved vendor may create or modify applications (checked here as well as in the portal
 *     middleware, so no other caller can bypass it).
 *  3. Nothing here touches pricing, commission or earnings: API sales are the vendor's own sales and
 *     settle through the existing order, payment and wallet pipeline unchanged.
 */
class DeveloperApplicationService
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly DeveloperAuditLogger $audit,
    ) {}

    /** @param array{name:string,description?:?string} $data */
    public function create(Vendor $vendor, array $data): DeveloperApplication
    {
        $this->assertApproved($vendor);

        $max = (int) config('developer_api.applications.max_per_vendor', 10);
        if (DeveloperApplication::query()->where('vendor_id', $vendor->id)->count() >= $max) {
            throw ValidationException::withMessages(['name' => "You can have at most {$max} applications."]);
        }

        return DB::transaction(function () use ($vendor, $data) {
            $app = new DeveloperApplication([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            // Not mass-assignable on purpose: set explicitly, from the authenticated vendor only.
            $app->forceFill([
                'vendor_id' => $vendor->id,
                'status' => DeveloperApplication::STATUS_ACTIVE,
                'plan_id' => DeveloperPlan::query()->where('is_default', true)->where('is_active', true)->value('id'),
            ])->save();

            $this->audit->log("vendor:{$vendor->id}", 'application.created', $app->id);

            return $app;
        });
    }

    public function update(DeveloperApplication $app, Vendor $vendor, array $data): DeveloperApplication
    {
        $this->assertApproved($vendor);

        // Descriptive fields only. The owner, status and limits are not editable here.
        $app->forceFill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
        $this->audit->log("vendor:{$vendor->id}", 'application.updated', $app->id);

        return $app;
    }

    /** Permanent. Revokes every key and silences every webhook of the application. */
    public function revoke(DeveloperApplication $app, string $actor): void
    {
        DB::transaction(function () use ($app, $actor) {
            $app->forceFill(['status' => DeveloperApplication::STATUS_REVOKED])->save();
            $this->keys->revokeAllForApplication($app);
            $app->webhooks()->update(['is_active' => false]);
            $this->audit->log($actor, 'application.revoked', $app->id);
        });
    }

    private function assertApproved(Vendor $vendor): void
    {
        if (! VendorApiAccess::isEligible($vendor)) {
            throw ValidationException::withMessages(['name' => 'Your vendor account is not approved for Developer API access.']);
        }
    }
}
