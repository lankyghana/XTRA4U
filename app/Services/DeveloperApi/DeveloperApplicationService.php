<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\Developer;
use App\Models\Developer\DeveloperApplication;
use App\Models\Vendor;
use App\Models\VendorNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every state change of an application goes through here, so the business rules
 * live in one place:
 *
 *  1. Production access is a REQUEST that an admin approves. Nothing a developer
 *     (or any request input) can send flips an application to production.
 *  2. A vendor-bound application is bound to ONE vendor and works only after that
 *     vendor approves the link. The binding is fixed at creation.
 *  3. A vendor-bound application cannot request production until its vendor approved.
 *  4. No pricing, commission or earning is touched here.
 */
class DeveloperApplicationService
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly DeveloperAuditLogger $audit,
    ) {}

    /** @param array{name:string,description?:?string,type:string,vendor_code?:?string} $data */
    public function create(Developer $developer, array $data): DeveloperApplication
    {
        $max = (int) config('developer_api.applications.max_per_developer', 10);
        if ($developer->applications()->count() >= $max) {
            throw ValidationException::withMessages(['name' => "You can have at most {$max} applications."]);
        }

        $vendor = null;
        if ($data['type'] === 'vendor') {
            // Same message whether the code is unknown or the vendor is unapproved: no vendor enumeration.
            $vendor = Vendor::query()
                ->where('vendor_code', strtoupper(trim((string) ($data['vendor_code'] ?? ''))))
                ->where('is_approved', true)
                ->first();

            if (! $vendor) {
                throw ValidationException::withMessages(['vendor_code' => 'No approved vendor was found with that store code.']);
            }
        }

        return DB::transaction(function () use ($developer, $data, $vendor) {
            $app = new DeveloperApplication([
                'developer_id' => $developer->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            // Not mass-assignable on purpose: set explicitly, never from request input.
            $app->forceFill([
                'environment' => DeveloperApplication::ENV_SANDBOX,
                'status' => DeveloperApplication::STATUS_ACTIVE,
                'production_status' => DeveloperApplication::PRODUCTION_NONE,
                'vendor_id' => $vendor?->id,
                'vendor_link_status' => $vendor ? DeveloperApplication::VENDOR_PENDING : DeveloperApplication::VENDOR_NONE,
                'vendor_link_requested_at' => $vendor ? now() : null,
                'plan_id' => \App\Models\Developer\DeveloperPlan::query()->where('is_default', true)->where('is_active', true)->value('id'),
            ])->save();

            $this->audit->log("developer:{$developer->id}", 'application.created', $app->id, ['type' => $vendor ? 'vendor' : 'marketplace', 'vendor_id' => $vendor?->id]);

            if ($vendor) {
                VendorNotification::create([
                    'vendor_id' => $vendor->id,
                    'type' => 'developer_access_request',
                    'title' => 'Developer API access request',
                    'message' => "An application named \"{$app->name}\" has asked to access your store's catalog through the XTRA4U API. Review it under Developer Access.",
                    'data' => ['application_id' => $app->id],
                ]);
            }

            return $app;
        });
    }

    public function update(DeveloperApplication $app, Developer $developer, array $data): DeveloperApplication
    {
        // Only descriptive fields. Type, vendor binding, environment, status and limits are not editable here.
        $app->forceFill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
        $this->audit->log("developer:{$developer->id}", 'application.updated', $app->id);

        return $app;
    }

    public function requestProduction(DeveloperApplication $app, Developer $developer): DeveloperApplication
    {
        if ($app->status !== DeveloperApplication::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['production' => 'This application is not active.']);
        }
        if ($app->environment === DeveloperApplication::ENV_PRODUCTION || $app->production_status === DeveloperApplication::PRODUCTION_REQUESTED) {
            throw ValidationException::withMessages(['production' => 'Production access has already been requested or granted.']);
        }
        if ($app->isVendorBound() && $app->vendor_link_status !== DeveloperApplication::VENDOR_APPROVED) {
            throw ValidationException::withMessages(['production' => 'The vendor must approve this application before you can request production access.']);
        }

        $app->forceFill([
            'production_status' => DeveloperApplication::PRODUCTION_REQUESTED,
            'production_requested_at' => now(),
            'production_review_note' => null,
        ])->save();
        $this->audit->log("developer:{$developer->id}", 'production.requested', $app->id);

        return $app;
    }

    /** Admin decision on a production request. $actor is e.g. "admin:3". */
    public function reviewProduction(DeveloperApplication $app, bool $approve, string $actor, ?string $note = null): DeveloperApplication
    {
        return DB::transaction(function () use ($app, $approve, $actor, $note) {
            $app = DeveloperApplication::query()->lockForUpdate()->findOrFail($app->id);

            if ($app->production_status !== DeveloperApplication::PRODUCTION_REQUESTED) {
                throw ValidationException::withMessages(['production' => 'There is no pending production request for this application.']);
            }

            if ($approve) {
                if ($app->status !== DeveloperApplication::STATUS_ACTIVE || ! $app->developer?->isActive()) {
                    throw ValidationException::withMessages(['production' => 'The application or its developer is not active.']);
                }
                if ($app->isVendorBound() && $app->vendor_link_status !== DeveloperApplication::VENDOR_APPROVED) {
                    throw ValidationException::withMessages(['production' => 'The vendor has not approved this application.']);
                }

                $app->forceFill([
                    'environment' => DeveloperApplication::ENV_PRODUCTION,
                    'production_status' => DeveloperApplication::PRODUCTION_APPROVED,
                    'production_approved_at' => now(),
                ]);
            } else {
                $app->forceFill(['production_status' => DeveloperApplication::PRODUCTION_REJECTED]);
            }

            $app->forceFill([
                'production_reviewed_at' => now(),
                'production_reviewed_by' => $actor,
                'production_review_note' => $note !== null ? mb_substr($note, 0, 500) : null,
            ])->save();

            $this->audit->log($actor, $approve ? 'production.approved' : 'production.rejected', $app->id);

            return $app;
        });
    }

    /** Admin withdrawal of production access. Production keys stop working instantly (see AuthenticateApiKey). */
    public function withdrawProduction(DeveloperApplication $app, string $actor, ?string $note = null): DeveloperApplication
    {
        $app->forceFill([
            'environment' => DeveloperApplication::ENV_SANDBOX,
            'production_status' => DeveloperApplication::PRODUCTION_REJECTED,
            'production_reviewed_at' => now(),
            'production_reviewed_by' => $actor,
            'production_review_note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ])->save();
        $this->audit->log($actor, 'production.withdrawn', $app->id);

        return $app;
    }

    /** The VENDOR'S decision. Ownership is part of the check: a vendor can only decide for its own link. */
    public function reviewVendorLink(DeveloperApplication $app, Vendor $vendor, bool $approve): DeveloperApplication
    {
        return DB::transaction(function () use ($app, $vendor, $approve) {
            $app = DeveloperApplication::query()->lockForUpdate()->findOrFail($app->id);

            if ((int) $app->vendor_id !== (int) $vendor->id) {
                abort(404);
            }

            $app->forceFill([
                'vendor_link_status' => $approve ? DeveloperApplication::VENDOR_APPROVED : DeveloperApplication::VENDOR_REJECTED,
                'vendor_link_reviewed_at' => now(),
            ])->save();

            $this->audit->log("vendor:{$vendor->id}", $approve ? 'vendor_link.approved' : 'vendor_link.rejected', $app->id);

            return $app;
        });
    }

    /** Permanent. Revokes every key and silences every webhook of the application. */
    public function revoke(DeveloperApplication $app, string $actor): void
    {
        DB::transaction(function () use ($app, $actor) {
            $app->forceFill(['status' => DeveloperApplication::STATUS_REVOKED])->save();
            $this->keys->revokeAllForApplication($app);
            $app->hasMany(\App\Models\Developer\DeveloperWebhook::class, 'application_id')->update(['is_active' => false]);
            $this->audit->log($actor, 'application.revoked', $app->id);
        });
    }
}
