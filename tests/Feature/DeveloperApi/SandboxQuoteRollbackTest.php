<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\SandboxOrder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * api_quotes is shared by production and sandbox (told apart by `environment`). Rolling back the M4 migration
 * must remove the SANDBOX quotes (they point at sandbox orders that no longer exist) and must never touch a
 * production quote, including one owned by the same vendor and application.
 */
class SandboxQuoteRollbackTest extends SandboxTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_19_000001_create_developer_sandbox_tables.php');
    }

    private function quoteRow(string $reference): ?object
    {
        return DB::table('api_quotes')->where('reference', $reference)->first();
    }

    /** @return array{w:array<string,mixed>, live:string, sbx:string, prod:string} one vendor, one app, one quote per environment */
    private function bothEnvironments(): array
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        $sbx = $this->quote($w['key'], $w['offer'])->assertCreated()->json('data.reference');
        $prod = $this->quote($live, $w['offer'])->assertCreated()->json('data.reference');

        return compact('w', 'live', 'sbx', 'prod');
    }

    public function test_quotes_of_both_environments_share_one_table_and_carry_their_environment(): void
    {
        $d = $this->bothEnvironments();

        $this->assertStringStartsWith('sbx_qt_', $d['sbx']);
        $this->assertStringStartsWith('qt_', $d['prod']);
        $this->assertSame('sandbox', $this->quoteRow($d['sbx'])->environment);
        $this->assertSame('production', $this->quoteRow($d['prod'])->environment);
        $this->assertSame($this->quoteRow($d['sbx'])->application_id, $this->quoteRow($d['prod'])->application_id, 'same application owns both');
        $this->assertSame($this->quoteRow($d['sbx'])->vendor_id, $this->quoteRow($d['prod'])->vendor_id, 'same vendor owns both');
    }

    public function test_rollback_removes_the_sandbox_quote_and_keeps_the_production_quote_of_the_same_vendor(): void
    {
        $d = $this->bothEnvironments();
        $prodBefore = (array) $this->quoteRow($d['prod']);

        $this->assertNotNull($this->quoteRow($d['sbx']), 'precondition: sandbox quote exists');
        $this->assertNotNull($this->quoteRow($d['prod']), 'precondition: production quote exists');

        $this->migration()->down();

        $this->assertNull($this->quoteRow($d['sbx']), 'the sandbox quote is removed by the rollback');
        $this->assertSame($prodBefore, (array) $this->quoteRow($d['prod']), 'the production quote is byte-for-byte unchanged');
        $this->assertSame(1, ApiQuote::count());
        $this->assertFalse(Schema::hasTable('developer_sandbox_orders'));
    }

    public function test_reapplying_the_migration_works_and_production_quotes_remain_usable(): void
    {
        $d = $this->bothEnvironments();
        $prodBefore = (array) $this->quoteRow($d['prod']);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('developer_sandbox_orders'));
        $this->assertNull($this->quoteRow($d['sbx']));
        $this->assertSame($prodBefore, (array) $this->quoteRow($d['prod']));

        // Production quote operations are unaffected: the surviving quote still becomes a production order…
        $this->order($d['live'], $d['prod'], 'after-rollback')->assertCreated();
        $this->assertSame(1, Order::count());
        $this->assertNotNull($this->quoteRow($d['prod'])->consumed_at);
        $this->assertSame(0, SandboxOrder::count());

        // …and a brand new quote can be issued in each environment.
        $newProd = $this->quote($d['live'], $d['w']['offer'])->assertCreated()->json('data.reference');
        $newSbx = $this->quote($d['w']['key'], $d['w']['offer'])->assertCreated()->json('data.reference');
        $this->assertSame('production', $this->quoteRow($newProd)->environment);
        $this->assertSame('sandbox', $this->quoteRow($newSbx)->environment);
        $this->order($d['w']['key'], $newSbx, 'sbx-after')->assertCreated();
        $this->assertSame(1, SandboxOrder::count());
        $this->assertSame(1, Order::count(), 'a sandbox order still creates no production order');
    }

    public function test_consumed_quotes_are_handled_by_environment_even_when_their_order_ids_overlap(): void
    {
        $d = $this->bothEnvironments();

        // Consume both: sandbox -> developer_sandbox_orders, production -> orders. The two id spaces are unrelated
        // (the first row of each table is id 1), which is exactly why the quote's order_id can't identify its owner.
        $this->order($d['w']['key'], $d['sbx'], 'c-sbx')->assertCreated();
        $this->order($d['live'], $d['prod'], 'c-prod')->assertCreated();
        $this->assertSame($this->quoteRow($d['sbx'])->order_id, $this->quoteRow($d['prod'])->order_id, 'fixture: the order ids overlap');
        $prodBefore = (array) $this->quoteRow($d['prod']);

        $this->migration()->down();

        $this->assertNull($this->quoteRow($d['sbx']));
        $this->assertSame($prodBefore, (array) $this->quoteRow($d['prod']));
        $this->assertSame(1, Order::count(), 'the production order is untouched');
    }

    public function test_a_row_is_only_deleted_when_environment_and_reference_format_both_say_sandbox(): void
    {
        $d = $this->bothEnvironments();
        $base = (array) $this->quoteRow($d['prod']);
        unset($base['id']);
        $make = fn (string $reference, string $environment) => DB::table('api_quotes')->insert(
            ['reference' => $reference, 'environment' => $environment] + $base,
        );

        $make('sbx_qt_'.str_repeat('a', 26), 'production');   // sandbox-looking reference, production row
        $make('foreign_sandbox_ref', 'sandbox');              // sandbox row with an unexpected reference
        // Wrong case: not what QuoteService mints. Different letters from the rows above on purpose — MySQL's
        // default collation is case-insensitive, so 'A…' would collide with 'a…' under the unique key.
        $make('sbx_qt_'.str_repeat('C', 26), 'sandbox');
        $make('sbx_qt_short', 'sandbox');                     // wrong length
        $make('sbx_qt_'.str_repeat('b', 26), 'sandbox');      // exactly the minted format: this one goes

        $this->migration()->down();

        $left = DB::table('api_quotes')->pluck('environment', 'reference')->all();
        $this->assertArrayNotHasKey('sbx_qt_'.str_repeat('b', 26), $left, 'a well-formed sandbox quote is removed');
        $this->assertArrayNotHasKey($d['sbx'], $left);
        $this->assertSame('production', $left['sbx_qt_'.str_repeat('a', 26)], 'a production row is never removed, whatever its reference looks like');
        $this->assertSame('sandbox', $left['foreign_sandbox_ref']);
        $this->assertSame('sandbox', $left['sbx_qt_'.str_repeat('C', 26)]);
        $this->assertSame('sandbox', $left['sbx_qt_short']);
        $this->assertSame('production', $left[$d['prod']]);
    }

    public function test_every_sandbox_quote_goes_across_vendors_and_applications_and_every_production_quote_stays(): void
    {
        $this->paystack();
        $a = $this->sandboxWorld();
        $b = $this->sandboxWorld();
        $secondApp = $this->makeApp($a['vendor']);                         // same vendor, another application
        $secondKey = $this->makeKey($secondApp, null, null, 'sandbox')[1];
        $secondLive = $this->makeKey($secondApp, null, null, 'production')[1];

        $production = [
            $this->quote($this->liveKey($a['app']), $a['offer'])->json('data.reference'),
            $this->quote($this->liveKey($b['app']), $b['offer'])->json('data.reference'),
            $this->quote($secondLive, $a['offer'])->json('data.reference'),
        ];
        $sandbox = [
            $this->quote($a['key'], $a['offer'])->json('data.reference'),
            $this->quote($b['key'], $b['offer'])->json('data.reference'),
            $this->quote($secondKey, $a['offer'])->json('data.reference'),
        ];
        $this->assertCount(6, array_unique(array_merge($production, $sandbox)));
        $before = DB::table('api_quotes')->whereIn('reference', $production)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->migration()->down();

        $this->assertSame(0, DB::table('api_quotes')->whereIn('reference', $sandbox)->count());
        $this->assertSame($before, DB::table('api_quotes')->whereIn('reference', $production)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(3, DB::table('api_quotes')->count());
    }

    public function test_cross_vendor_quote_access_is_blocked_before_and_after_the_rollback(): void
    {
        $d = $this->bothEnvironments();
        $other = $this->sandboxWorld();                                   // a different approved vendor
        $otherLive = $this->liveKey($other['app']);

        $assertBlocked = function (string $when) use ($d, $other, $otherLive) {
            // Neither of the owner's quotes can be turned into an order by another vendor's key, in either environment.
            $this->order($other['key'], $d['sbx'], "x-{$when}-1")->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
            $this->order($other['key'], $d['prod'], "x-{$when}-2")->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
            $this->order($otherLive, $d['sbx'], "x-{$when}-3")->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
            $this->order($otherLive, $d['prod'], "x-{$when}-4")->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
        };

        $assertBlocked('before');
        $this->assertNull($this->quoteRow($d['prod'])->consumed_at);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $assertBlocked('after');
        $this->assertNotNull($this->quoteRow($d['prod']), 'the production quote survived');
        $this->assertNull($this->quoteRow($d['prod'])->consumed_at, 'and nobody consumed it');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, SandboxOrder::count());
    }

    public function test_the_rollback_makes_no_payment_gateway_or_webhook_request(): void
    {
        $this->bothEnvironments();

        $this->migration()->down();

        $this->assertSame([], $this->transport->sent, 'no webhook left the process');
    }
}
