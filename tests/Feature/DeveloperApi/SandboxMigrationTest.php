<?php

namespace Tests\Feature\DeveloperApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_11_19_000001_create_developer_sandbox_tables: additive, data-preserving, reversible.
 * RefreshDatabase has already run it once. (The engine-level proof — MySQL 8.4 / MariaDB — is done by hand and
 * recorded in the milestone report; these tests pin the schema contract on every developer machine and in CI.)
 */
class SandboxMigrationTest extends DeveloperApiTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_19_000001_create_developer_sandbox_tables.php');
    }

    private function indexes(string $table): array
    {
        return collect(Schema::getIndexes($table))->mapWithKeys(fn ($i) => [$i['name'] => ['columns' => $i['columns'], 'unique' => $i['unique']]])->all();
    }

    private function hasUnique(string $table, array $columns): bool
    {
        return collect($this->indexes($table))->contains(fn ($i) => $i['unique'] && $i['columns'] === $columns);
    }

    public function test_the_sandbox_tables_exist_and_are_independent_of_production_tables(): void
    {
        foreach (['developer_sandbox_orders', 'developer_sandbox_payments', 'developer_sandbox_order_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        // Foreign keys exist ONLY between sandbox tables: never to orders, vendors, transactions or applications.
        foreach (['developer_sandbox_orders', 'developer_sandbox_payments', 'developer_sandbox_order_events'] as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertStringStartsWith('developer_sandbox_', $fk['foreign_table'], "{$table} has a foreign key to {$fk['foreign_table']}");
            }
        }
    }

    public function test_the_unique_constraints_that_protect_the_sandbox_exist(): void
    {
        $this->assertTrue($this->hasUnique('developer_sandbox_orders', ['order_ref']));
        $this->assertTrue($this->hasUnique('developer_sandbox_orders', ['quote_reference']));
        $this->assertTrue($this->hasUnique('developer_sandbox_orders', ['payment_reference']));
        $this->assertTrue($this->hasUnique('developer_sandbox_orders', ['application_id', 'idempotency_key']));
        $this->assertTrue($this->hasUnique('developer_sandbox_payments', ['payment_ref']));
        $this->assertTrue($this->hasUnique('developer_sandbox_payments', ['sandbox_order_id']));
        $this->assertTrue($this->hasUnique('developer_sandbox_order_events', ['event_ref']));
        $this->assertTrue($this->hasUnique('developer_events', ['environment', 'order_id', 'type']));
        $this->assertFalse($this->hasUnique('developer_events', ['order_id', 'type']), 'the environment-blind key must be gone');
    }

    public function test_every_existing_webhook_endpoint_becomes_a_production_endpoint(): void
    {
        $column = collect(Schema::getColumns('developer_webhooks'))->firstWhere('name', 'environment');

        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
        $this->assertStringContainsString('production', (string) $column['default']);
    }

    public function test_rolling_back_and_forward_preserves_production_data_and_removes_only_sandbox_data(): void
    {
        $vendor = $this->vendor();
        $app = $this->makeApp($vendor);
        $hook = DB::table('developer_webhooks')->insertGetId([
            'application_id' => $app->id, 'environment' => 'production', 'url' => 'https://hooks.example.com/p', 'events' => json_encode(['order.created']),
            'secret' => 'enc', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sandboxHook = DB::table('developer_webhooks')->insertGetId([
            'application_id' => $app->id, 'environment' => 'sandbox', 'url' => 'https://hooks.example.com/s', 'events' => json_encode(['order.created']),
            'secret' => 'enc', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = fn (string $env, int $orderId) => DB::table('developer_events')->insertGetId([
            'event_id' => "evt_{$env}_{$orderId}", 'application_id' => $app->id, 'order_id' => $orderId, 'type' => 'order.created',
            'environment' => $env, 'payload' => '{}', 'occurred_at' => now(),
        ]);
        $prodEvent = $event('production', 1);
        $sandboxEvent = $event('sandbox', 1);   // same order number, other environment
        DB::table('developer_webhook_deliveries')->insert([
            ['delivery_uid' => 'dlv_p', 'webhook_id' => $hook, 'event_id' => $prodEvent, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()],
            ['delivery_uid' => 'dlv_s', 'webhook_id' => $sandboxHook, 'event_id' => $sandboxEvent, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = $this->migration();
        $migration->down();

        // Sandbox schema and data are gone; production rows, with their deliveries, are exactly as they were.
        $this->assertFalse(Schema::hasTable('developer_sandbox_orders'));
        $this->assertFalse(Schema::hasColumn('developer_webhooks', 'environment'));
        $this->assertSame(1, DB::table('developer_events')->count());
        $this->assertSame('evt_production_1', DB::table('developer_events')->value('event_id'));
        $this->assertSame(1, DB::table('developer_webhooks')->count());
        $this->assertSame(['dlv_p'], DB::table('developer_webhook_deliveries')->pluck('delivery_uid')->all());
        $this->assertTrue($this->hasUnique('developer_events', ['order_id', 'type']));

        $migration->up();

        $this->assertTrue(Schema::hasTable('developer_sandbox_orders'));
        $this->assertSame('production', DB::table('developer_webhooks')->value('environment'), 'a surviving endpoint comes back as production');
        $this->assertSame('production', DB::table('developer_events')->value('environment'));
        $this->assertTrue($this->hasUnique('developer_events', ['environment', 'order_id', 'type']));
        $this->assertSame(['dlv_p'], DB::table('developer_webhook_deliveries')->pluck('delivery_uid')->all());
    }
}
