<?php

namespace Tests\Feature\DeveloperApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_11_18_000001_make_developer_applications_vendor_owned: additive, data-preserving, idempotent.
 * RefreshDatabase has already run it once; these tests seed LEGACY data and run it again.
 */
class VendorOwnershipMigrationTest extends DeveloperApiTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_18_000001_make_developer_applications_vendor_owned.php');
    }

    private function legacyDeveloper(): int
    {
        return DB::table('developers')->insertGetId([
            'name' => 'Legacy Dev', 'email' => uniqid().'@legacy.test', 'password' => 'x', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacyApp(array $o = []): int
    {
        return DB::table('developer_applications')->insertGetId(array_merge([
            'developer_id' => $this->legacyDeveloper(), 'name' => 'Legacy App', 'environment' => 'production',
            'status' => 'active', 'vendor_id' => null, 'vendor_link_status' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    public function test_developer_id_is_nullable_and_no_table_or_column_was_dropped(): void
    {
        $columns = Schema::getColumns('developer_applications');
        $developerId = collect($columns)->firstWhere('name', 'developer_id');

        $this->assertTrue($developerId['nullable']);
        foreach (['vendor_id', 'status', 'environment', 'production_approved_at', 'vendor_link_status', 'suspended_at', 'plan_id'] as $kept) {
            $this->assertTrue(Schema::hasColumn('developer_applications', $kept), "{$kept} must still exist");
        }
        $this->assertTrue(Schema::hasTable('developers'));
    }

    public function test_legacy_developer_owned_applications_are_suspended_with_everything_preserved(): void
    {
        $id = $this->legacyApp();
        $keyId = DB::table('developer_api_keys')->insertGetId([
            'application_id' => $id, 'name' => 'old', 'prefix' => 'legacy01', 'key_hash' => str_repeat('a', 64),
            'environment' => 'production', 'scopes' => json_encode(['services:read']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $hookId = DB::table('developer_webhooks')->insertGetId([
            'application_id' => $id, 'url' => 'https://hooks.example.com/old', 'events' => json_encode(['order.created']),
            'secret' => 'enc', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();

        $row = DB::table('developer_applications')->find($id);
        $this->assertSame('suspended', $row->status);
        $this->assertNotNull($row->suspended_at);
        $this->assertNotNull($row->developer_id);                            // history preserved
        $this->assertSame('Legacy App', $row->name);
        $this->assertNotNull(DB::table('developer_api_keys')->find($keyId)); // key row kept
        $this->assertNotNull(DB::table('developer_webhooks')->find($hookId));

        $audit = DB::table('developer_audit_events')->where('application_id', $id)
            ->where('action', 'application.suspended_legacy_developer_model')->first();
        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor);
        $this->assertSame('active', json_decode($audit->meta, true)['previous_status']);
    }

    public function test_a_suspended_legacy_application_cannot_authenticate(): void
    {
        $vendor = $this->vendor();
        $id = $this->legacyApp(['vendor_id' => $vendor->id, 'vendor_link_status' => 'approved']);
        $app = \App\Models\Developer\DeveloperApplication::findOrFail($id);
        [, $plain] = $this->makeKey($app);
        $this->getJson('/api/v1/services', $this->auth($plain))->assertOk();   // before: legacy apps worked

        $this->migration()->up();

        // Even though its vendor is approved, a legacy app does not inherit that authority.
        $this->getJson('/api/v1/services', $this->auth($plain))
            ->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_INACTIVE');
    }

    public function test_vendor_owned_applications_and_closed_legacy_ones_are_left_alone(): void
    {
        $vendorOwned = $this->makeApp()->id;                                    // developer_id NULL: new model
        $revoked = $this->legacyApp(['status' => 'revoked']);
        $alreadySuspended = $this->legacyApp(['status' => 'suspended']);

        $this->migration()->up();

        $this->assertSame('active', DB::table('developer_applications')->find($vendorOwned)->status);
        $this->assertSame('revoked', DB::table('developer_applications')->find($revoked)->status);
        $this->assertSame('suspended', DB::table('developer_applications')->find($alreadySuspended)->status);
        $this->assertSame(0, DB::table('developer_audit_events')->where('action', 'application.suspended_legacy_developer_model')->count());
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        $this->legacyApp();

        $this->migration()->up();
        $first = DB::table('developer_audit_events')->where('action', 'application.suspended_legacy_developer_model')->count();
        $this->migration()->up();

        $this->assertSame(1, $first);
        $this->assertSame(1, DB::table('developer_audit_events')->where('action', 'application.suspended_legacy_developer_model')->count());
    }

    public function test_rollback_never_invents_or_loses_data(): void
    {
        $vendorOwned = $this->makeApp()->id;   // developer_id NULL

        $this->migration()->down();            // cannot restore NOT NULL: it must refuse, keeping data

        $this->assertNotNull(DB::table('developer_applications')->find($vendorOwned));
        $this->assertTrue(collect(Schema::getColumns('developer_applications'))->firstWhere('name', 'developer_id')['nullable']);
    }

    public function test_the_sqlite_table_rebuild_refuses_to_run_in_a_transaction_instead_of_cascade_deleting_children(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MySQL/MariaDB alter the column in place; only SQLite rebuilds the table.');
        }

        $id = $this->legacyApp();               // developer_id set => down() would have to rebuild
        $keyId = DB::table('developer_api_keys')->insertGetId([
            'application_id' => $id, 'name' => 'k', 'prefix' => 'guard001', 'key_hash' => str_repeat('b', 64),
            'environment' => 'production', 'scopes' => json_encode(['services:read']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, DB::transactionLevel());   // RefreshDatabase wraps every test

        try {
            $this->migration()->down();
            $this->fail('The rebuild ran inside a transaction with live rows.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to rebuild', $e->getMessage());
        }

        $this->assertNotNull(DB::table('developer_applications')->find($id));
        $this->assertNotNull(DB::table('developer_api_keys')->find($keyId));   // nothing cascaded
    }

    public function test_the_migration_does_not_run_inside_a_transaction(): void
    {
        $this->assertFalse($this->migration()->withinTransaction);
    }
}
