<?php

namespace Tests\Feature\DeveloperApi;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperAuditEvent;
use App\Models\Developer\DeveloperEvent;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Vendor;
use App\Services\DeveloperApi\VendorApiAccess;
use App\Services\DeveloperApi\Webhooks\TransportResult;
use App\Services\DeveloperApi\Webhooks\ValidatedDestination;
use App\Services\DeveloperApi\Webhooks\WebhookDispatcher;
use App\Services\DeveloperApi\Webhooks\WebhookTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Vendor approval is THE gate. When it is withdrawn (rejected/suspended through the existing admin
 * actions, or the vendor is removed) API access stops at once and the credentials are retired.
 */
class VendorApprovalAccessTest extends PortalTestCase
{
    /** @return array{0:Vendor,1:DeveloperApplication,2:\App\Models\Developer\DeveloperApiKey,3:string,4:\App\Models\Developer\DeveloperWebhook} */
    private function liveVendor(): array
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        [$key, $plain] = $this->makeKey($app);
        [$hook] = $this->makeWebhook($app);

        return [$vendor, $app, $key, $plain, $hook];
    }

    private function delivery($hook, string $status = 'pending'): DeveloperWebhookDelivery
    {
        $event = DeveloperEvent::create([
            'event_id' => 'evt_'.uniqid(), 'application_id' => $hook->application_id, 'order_id' => random_int(1, 99999),
            'type' => 'order.created', 'environment' => 'production', 'payload' => ['order' => ['id' => 'ord_x']],
            'occurred_at' => now(), 'fanned_out_at' => now(),
        ]);

        return DeveloperWebhookDelivery::create([
            'delivery_uid' => 'dlv_'.uniqid(), 'webhook_id' => $hook->id, 'event_id' => $event->id,
            'status' => $status, 'next_attempt_at' => now()->subSecond(),
        ]);
    }

    // ── through the real admin actions ──────────────────────────────────────

    public function test_admin_rejecting_a_vendor_revokes_keys_disables_webhooks_and_audits(): void
    {
        [$vendor, $app, $key, $plain, $hook] = $this->liveVendor();
        $pending = $this->delivery($hook);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();

        $this->asAdmin()->post("/admin/vendors/{$vendor->id}/reject")->assertRedirect();

        $this->assertFalse((bool) $vendor->fresh()->is_approved);
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->assertSame('cancelled', $pending->fresh()->status);

        $audit = DeveloperAuditEvent::where('action', 'vendor.api_access_withdrawn')->firstOrFail();
        $this->assertSame($app->id, (int) $audit->application_id);
        $this->assertSame($vendor->id, (int) $audit->meta['vendor_id']);
        $this->assertSame(1, $audit->meta['credentials_revoked']);
        $this->assertSame(1, $audit->meta['webhooks_disabled']);
        $this->assertSame(1, $audit->meta['deliveries_cancelled']);
        $this->assertStringStartsWith('user:', $audit->actor);   // the admin who did it
        $this->assertStringNotContainsString($plain, json_encode($audit->toArray()));

        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }

    public function test_the_existing_account_suspension_path_withdraws_access_too(): void
    {
        [$vendor, , $key, $plain, $hook] = $this->liveVendor();

        // This is exactly what AdminController::suspendVendor does.
        $v = Vendor::findOrFail($vendor->id);
        $v->is_approved = false;
        $v->save();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }

    public function test_removing_a_vendor_withdraws_access(): void
    {
        [$vendor, , $key, $plain, $hook] = $this->liveVendor();

        $this->asAdmin()->delete("/admin/vendors/{$vendor->id}")->assertRedirect();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);
    }

    public function test_approving_a_vendor_needs_no_extra_step_to_start_using_the_portal(): void
    {
        $vendor = $this->unapprovedVendor();
        $this->actingAsVendor($vendor)->get('/developers/dashboard')->assertForbidden();

        $this->asAdmin()->post("/admin/vendors/{$vendor->id}/approve")->assertRedirect();

        $this->actingAsVendor($vendor->fresh())->get('/developers/dashboard')->assertOk();
        $this->post('/developers/applications', ['name' => 'First app'])->assertRedirect();
        $this->assertSame(1, DeveloperApplication::where('vendor_id', $vendor->id)->count());
    }

    // ── immediate denial, with no cleanup having run ────────────────────────

    public function test_api_requests_are_denied_immediately_even_when_no_cleanup_has_run(): void
    {
        [$vendor, , $key, $plain] = $this->liveVendor();

        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);   // no events fire

        $this->assertNull($key->fresh()->revoked_at);   // cleanup has NOT happened...
        $this->getJson('/api/v1/services', $this->auth($plain))                       // ...yet access is already denied
            ->assertStatus(403)->assertJsonPath('error.code', 'VENDOR_NOT_APPROVED');
    }

    public function test_webhooks_are_not_delivered_for_an_unapproved_vendor_even_before_cleanup(): void
    {
        [$vendor, , , , $hook] = $this->liveVendor();
        $d = $this->delivery($hook);
        $this->app->bind(WebhookTransport::class, fn () => new class implements WebhookTransport
        {
            public function send(ValidatedDestination $destination, array $headers, string $body): TransportResult
            {
                throw new \RuntimeException('A webhook request was made for an unapproved vendor.');
            }
        });
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        app(WebhookDispatcher::class)->deliver($d->id);

        $this->assertSame('cancelled', $d->fresh()->status);
    }

    // ── safety net for changes that bypass Eloquent ─────────────────────────

    public function test_reconciliation_retires_credentials_after_a_raw_unapproval(): void
    {
        [$vendor, $app, $key, , $hook] = $this->liveVendor();
        $d = $this->delivery($hook);
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        Queue::fake();
        $this->artisan('developer-webhooks:run')->assertSuccessful();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->assertSame('cancelled', $d->fresh()->status);
        $audit = DeveloperAuditEvent::where('action', 'vendor.api_access_withdrawn')->firstOrFail();
        $this->assertSame($app->id, (int) $audit->application_id);
        $this->assertSame('system', $audit->actor);
        Queue::assertNotPushed(DeliverDeveloperWebhook::class);
    }

    public function test_reconciliation_also_covers_a_vendor_deleted_without_events(): void
    {
        [$vendor, , $key, , $hook] = $this->liveVendor();
        DB::table('vendors')->where('id', $vendor->id)->delete();

        $this->assertSame(1, app(VendorApiAccess::class)->reconcile());

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
    }

    public function test_withdrawal_is_idempotent_and_does_not_spam_the_audit_trail(): void
    {
        [$vendor] = $this->liveVendor();
        $vendor->update(['is_approved' => false]);
        $rows = DeveloperAuditEvent::where('action', 'vendor.api_access_withdrawn')->count();

        $this->assertSame(['keys' => 0, 'webhooks' => 0, 'deliveries' => 0], app(VendorApiAccess::class)->withdraw($vendor->id, 'again'));
        $this->assertSame(0, app(VendorApiAccess::class)->reconcile());
        $this->assertSame($rows, DeveloperAuditEvent::where('action', 'vendor.api_access_withdrawn')->count());
    }

    // ── scope of the withdrawal ─────────────────────────────────────────────

    public function test_only_the_affected_vendors_credentials_are_touched(): void
    {
        [$gone, , $goneKey, , $goneHook] = $this->liveVendor();
        [, , $keepKey, $keepPlain, $keepHook] = $this->liveVendor();

        $gone->update(['is_approved' => false]);

        $this->assertNotNull($goneKey->fresh()->revoked_at);
        $this->assertFalse($goneHook->fresh()->is_active);
        $this->assertNull($keepKey->fresh()->revoked_at);
        $this->assertTrue($keepHook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($keepPlain))->assertOk();
    }

    public function test_unrelated_vendor_updates_do_not_withdraw_access(): void
    {
        [$vendor, , $key, $plain, $hook] = $this->liveVendor();

        $vendor->update(['name' => 'Renamed Vendor', 'phone_number' => '0244111222']);
        $vendor->forceFill(['is_approved' => true])->save();   // approved -> approved: not a change

        $this->assertNull($key->fresh()->revoked_at);
        $this->assertTrue($hook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();
        $this->assertSame(0, DeveloperAuditEvent::where('action', 'vendor.api_access_withdrawn')->count());
    }

    public function test_reapproval_neither_resurrects_credentials_nor_blocks_fresh_ones(): void
    {
        [$vendor, $app, $key, $plain, $hook] = $this->liveVendor();
        $vendor->update(['is_approved' => false]);
        $vendor->update(['is_approved' => true]);

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse($hook->fresh()->is_active);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(401);

        [, $fresh] = $this->makeKey($app->fresh());
        $this->getJson('/api/v1/services', $this->auth($fresh))->assertOk();
    }

    // ── protected portal actions & non-regression ───────────────────────────

    public function test_a_withdrawn_vendor_cannot_create_apps_keys_or_webhooks(): void
    {
        [$vendor, $app] = $this->liveVendor();
        $vendor->update(['is_approved' => false]);
        $this->actingAsVendor($vendor->fresh());

        $this->post('/developers/applications', ['name' => 'New'])->assertForbidden();
        $this->post("/developers/applications/{$app->id}/keys", ['name' => 'k', 'environment' => 'production', 'scopes' => ['services:read'], 'expires_in_days' => 30])->assertForbidden();
        $this->post("/developers/applications/{$app->id}/webhooks", ['url' => 'https://hooks.example.com/z', 'events' => ['order.created']])->assertForbidden();
    }

    public function test_a_failure_in_the_cleanup_never_blocks_the_vendor_suspension(): void
    {
        [$vendor, , , $plain] = $this->liveVendor();
        $this->app->instance(VendorApiAccess::class, new class($this->app->make(\App\Services\DeveloperApi\DeveloperAuditLogger::class)) extends VendorApiAccess
        {
            public function withdraw(int $vendorId, string $reason, string $actor = 'system'): array
            {
                throw new \RuntimeException('cleanup exploded');
            }
        });

        $vendor->update(['is_approved' => false]);   // must not throw

        $this->assertFalse((bool) $vendor->fresh()->is_approved);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertStatus(403);   // still denied live
    }

    public function test_vendor_registration_admin_pages_and_the_vendor_area_still_work(): void
    {
        $this->get('/vendor/request')->assertOk();
        $this->get('/vendor/login')->assertOk();

        $vendor = $this->approvedVendor();
        $this->actingAsVendor($vendor)->get('/vendor/dashboard')->assertOk();

        $this->asAdmin()->get('/admin')->assertOk();
        $this->get('/admin/vendors')->assertOk();
    }

    public function test_a_suspended_vendors_other_marketplace_data_is_untouched(): void
    {
        [$vendor] = $this->liveVendor();
        $product = $this->product($vendor, 12.5);
        $balance = Vendor::find($vendor->id)->balance;

        $vendor->update(['is_approved' => false]);

        $this->assertEquals(12.5, $product->fresh()->price);
        $this->assertEquals($balance, Vendor::find($vendor->id)->balance);
        $this->assertNull($product->fresh()->deleted_at);
    }
}
