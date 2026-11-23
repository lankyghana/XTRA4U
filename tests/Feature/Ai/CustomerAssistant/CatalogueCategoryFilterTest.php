<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\Catalogue\PublicCatalogueReadService;
use Illuminate\Support\Facades\DB;

/**
 * The storefront category filter and the public-field search filter run INSIDE the database (SQLite here, MariaDB in the
 * opt-in run), with exactly the storefront's category semantics - and the scan window (400 rows) now only bounds rows
 * that already match, so a match can no longer hide behind 400 rows of another category.
 */
class CatalogueCategoryFilterTest extends CustomerAssistantTestCase
{
    private function service(): PublicCatalogueReadService
    {
        return app(PublicCatalogueReadService::class);
    }

    /** @return list<string> product names found in a category, in the name order */
    private function names(?string $category, ?string $text = null, int $limit = 100): array
    {
        $result = $this->service()->search($text, $category, 'name', 1, $limit);

        return array_values(array_map(fn ($i) => $i['name'], $result['items']));
    }

    /** @param list<array<string,mixed>> $rows */
    private function bulk(array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            Product::query()->insert(array_map(fn ($r) => $r + ['is_active' => true, 'is_resellable' => true, 'created_at' => now(), 'updated_at' => now()], $chunk));
        }
    }

    private function row(int $vendorId, string $name, ?string $description, float $price = 5.0, array $over = []): array
    {
        return ['vendor_id' => $vendorId, 'name' => $name, 'description' => $description, 'price' => $price] + $over;
    }

    // ------------------------------------------------------------------------- beyond the scan window

    public function test_matches_after_more_than_four_hundred_rows_of_other_categories_are_found(): void
    {
        $vendor = $this->approvedVendor(['name' => 'Data Vendor']);
        $this->assign('data', $vendor);

        $filler = [];
        for ($i = 1; $i <= 450; $i++) {
            $filler[] = $this->row($vendor->id, sprintf('Voucher filler %03d', $i), json_encode(['category' => 'shop', 'size' => '1GB']));
        }
        $this->bulk($filler);
        $this->bulk([
            $this->row($vendor->id, 'MTN 1GB Late', json_encode(['category' => 'data', 'network' => 'MTN', 'size' => '1GB'])),
            $this->row($vendor->id, 'Telecel 2GB Late', json_encode(['category' => 'data', 'network' => 'TELECEL', 'size' => '2GB'])),
        ]);

        $this->assertGreaterThan(450, Product::query()->where('vendor_id', $vendor->id)->count());

        $result = $this->service()->search(null, 'data', 'name', 1, 50);

        $this->assertSame(['MTN 1GB Late', 'Telecel 2GB Late'], array_column($result['items'], 'name'), 'found even though 450 other-category rows sort first');
        $this->assertFalse($result['incomplete'], 'nothing was cut off: the filter ran in the database');
        $this->assertSame(['MTN 1GB Late'], $this->names('data', 'mtn'));
        $this->assertSame([], $this->names('data', 'voucher'), 'the filler is not "data"');
    }

    public function test_search_words_also_run_in_the_database_so_a_late_match_is_not_hidden_by_four_hundred_non_matches(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);

        $rows = [];
        for ($i = 1; $i <= 430; $i++) {
            $rows[] = $this->row($vendor->id, sprintf('Bundle %03d', $i), json_encode(['category' => 'data', 'network' => 'MTN', 'size' => '1GB']));
        }
        $rows[] = $this->row($vendor->id, 'Zebra Special', json_encode(['category' => 'data', 'network' => 'AT', 'size' => '9GB', 'validity' => 'zebra days']));
        $this->bulk($rows);

        $found = $this->service()->search('zebra', 'data', 'name', 1, 20);

        $this->assertSame(['Zebra Special'], array_column($found['items'], 'name'));
        $this->assertFalse($found['incomplete']);
    }

    public function test_the_completeness_flag_is_true_only_when_genuinely_more_than_the_window_matched(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);

        $rows = [];
        for ($i = 1; $i <= 400; $i++) {
            $rows[] = $this->row($vendor->id, sprintf('Match %03d', $i), json_encode(['category' => 'data']));
        }
        $this->bulk($rows);

        $this->assertFalse($this->service()->search(null, 'data', 'name', 1, 5)['incomplete'], 'exactly the window size is still complete');

        $this->bulk([$this->row($vendor->id, 'Match 401', json_encode(['category' => 'data']))]);

        $over = $this->service()->search(null, 'data', 'name', 1, 5);
        $this->assertTrue($over['incomplete'], 'one more matching row than the window is reported as incomplete');
        $this->assertTrue($over['has_more']);
        $this->assertCount(5, $over['items']);
    }

    public function test_non_matching_volume_never_sets_the_incomplete_flag(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->assign('shop', $vendor);

        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = $this->row($vendor->id, "Shop item {$i}", json_encode(['category' => 'shop']));
        }
        $this->bulk($rows);

        $this->assertFalse($this->service()->search(null, 'data', 'name', 1, 5)['incomplete']);
        $this->assertSame([], $this->names('data'));
    }

    // ---------------------------------------------------------------- the storefront's category semantics

    public function test_every_category_metadata_state_lands_where_the_storefront_puts_it(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->assign('shop', $vendor);

        $states = [
            // description => expected category ('data' is the configured default; null = shown nowhere)
            'D01 empty string' => ['', 'data'],
            'D02 whitespace' => ['   ', 'data'],
            'D03 plain legacy text' => ['A plain legacy text description', 'data'],
            'D04 broken json' => ['{"category":"shop"', 'data'],
            'D05 json array' => ['[1,2,3]', 'data'],
            'D06 json string' => ['"shop"', 'data'],
            'D07 json number' => ['42', 'data'],
            'D08 json true' => ['true', 'data'],
            'D09 empty object' => ['{}', 'data'],
            'D10 no category key' => ['{"size":"1GB"}', 'data'],
            'D11 category null' => ['{"category":null}', 'data'],
            'D12 category empty' => ['{"category":""}', 'data'],
            'D13 category number' => ['{"category":5}', 'data'],
            'D14 category list' => ['{"category":["shop"]}', 'data'],
            'D15 category object' => ['{"category":{"x":"shop"}}', 'data'],
            'D16 category bool' => ['{"category":true}', 'data'],
            'D17 explicit data' => ['{"category":"data"}', 'data'],
            'S01 explicit shop' => ['{"category":"shop"}', 'shop'],
            'S02 shop with other keys' => ['{"size":"1","category":"shop","x":null}', 'shop'],
            'N01 upper case is a different category' => ['{"category":"DATA"}', null],
            'N02 trailing space is a different category' => ['{"category":"data "}', null],
            'N03 unknown category' => ['{"category":"airtime-xyz"}', null],
            'N04 utility-bills slot not owned by this vendor' => ['{"category":"ecg"}', null],
        ];

        $rows = [];
        foreach ($states as $name => [$description]) {
            $rows[] = $this->row($vendor->id, $name, $description);
        }
        $this->bulk($rows);

        $expected = fn (string $category) => collect($states)->filter(fn ($s) => $s[1] === $category)->keys()->sort()->values()->all();

        $this->assertSame($expected('data'), $this->names('data'));
        $this->assertSame($expected('shop'), $this->names('shop'));

        $shown = array_merge($this->names('data'), $this->names('shop'));
        foreach (['N01', 'N02', 'N03', 'N04'] as $prefix) {
            $this->assertEmpty(array_filter($shown, fn ($n) => str_starts_with($n, $prefix)), "{$prefix} is shown nowhere");
        }
    }

    public function test_a_null_description_falls_back_to_the_default_category_when_the_column_allows_null(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);

        try {
            $this->bulk([$this->row($vendor->id, 'Null description', null)]);
        } catch (\Throwable) {
            $this->markTestSkipped('products.description is NOT NULL on this schema');
        }

        $this->assertSame(['Null description'], $this->names('data'));
    }

    public function test_a_configured_default_category_is_honoured(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->assign('shop', $vendor);
        $this->bulk([$this->row($vendor->id, 'No category at all', '{"size":"1GB"}')]);

        config(['storefront.default_category' => 'shop']);

        $this->assertSame(['No category at all'], $this->names('shop'));
        $this->assertSame([], $this->names('data'));
    }

    // -------------------------------------------------------------------------------------- exclusions

    public function test_hidden_inactive_unassigned_unapproved_and_closed_offers_stay_hidden(): void
    {
        $data = $this->approvedVendor(['name' => 'Data']);
        $other = $this->approvedVendor(['name' => 'Not assigned']);
        $this->assign('data', $data);

        $this->bulk([
            $this->row($data->id, 'Visible', json_encode(['category' => 'data'])),
            $this->row($data->id, 'Inactive', json_encode(['category' => 'data']), 5.0, ['is_active' => false]),
            $this->row($other->id, 'Other vendor', json_encode(['category' => 'data'])),
            $this->row($data->id, 'Wrong category', json_encode(['category' => 'shop'])),
        ]);

        $this->assertSame(['Visible'], $this->names('data'));

        $this->closeCategory('data');
        $this->assertSame([], $this->names('data'), 'a closed category shows nothing');
    }

    public function test_an_unapproved_assigned_vendor_shows_nothing(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->bulk([$this->row($vendor->id, 'Visible', json_encode(['category' => 'data']))]);
        $this->assertSame(['Visible'], $this->names('data'));

        $vendor->forceFill(['is_approved' => false])->save();

        $this->assertSame([], $this->names('data'));
    }

    // ------------------------------------------------------------------------------------ reseller path

    public function test_reseller_listings_are_filtered_by_the_source_products_category_and_resellable_flag(): void
    {
        $source = $this->approvedVendor(['name' => 'Source']);
        $reseller = $this->approvedVendor(['name' => 'Reseller']);
        $this->assign('data', $reseller);

        $make = fn (string $name, string $description, bool $resellable = true, bool $active = true) => Product::create([
            'vendor_id' => $source->id, 'name' => $name, 'description' => $description, 'price' => 10.0, 'is_active' => $active, 'is_resellable' => $resellable,
        ]);

        // 420 listings of a different category come first, so the matches are beyond the 400-row window.
        for ($i = 1; $i <= 420; $i++) {
            $this->listing($make(sprintf('Shop source %03d', $i), json_encode(['category' => 'shop'])), $reseller);
        }

        $ok = $this->listing($make('Telecel 10GB Source', json_encode(['category' => 'data', 'network' => 'TELECEL', 'size' => '10GB'])), $reseller);
        $legacy = $this->listing($make('Legacy plain source', 'plain legacy text'), $reseller);
        $this->listing($make('Not resellable', json_encode(['category' => 'data']), resellable: false), $reseller);
        $this->listing($make('Inactive product', json_encode(['category' => 'data']), active: false), $reseller);
        $this->listing($make('Inactive listing', json_encode(['category' => 'data'])), $reseller, active: false);

        $result = $this->service()->search(null, 'data', 'name', 1, 50);

        $this->assertSame(['Legacy plain source', 'Telecel 10GB Source'], array_column($result['items'], 'name'));
        $this->assertFalse($result['incomplete']);
        $this->assertSame(['Telecel 10GB Source'], $this->names('data', 'telecel'));
        $this->assertNotNull($this->service()->find('reseller_'.$ok->id));
        $this->assertNotNull($this->service()->find('reseller_'.$legacy->id));
        $this->assertGreaterThan(420, ResellerProduct::query()->count() - 4);
    }

    // ---------------------------------------------------------------------------------- search words

    public function test_search_words_match_public_fields_only_case_insensitively_and_never_private_ones(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);

        $this->bulk([
            $this->row($vendor->id, 'Alpha Bundle', json_encode(['category' => 'data', 'network' => 'Vodafone', 'size' => '3GB', 'validity' => '14 days', 'service' => 'Roaming', 'notes' => 'SECRETNOTE', 'supplier_cost' => 'SECRETCOST', 'external_mappings' => ['xpres' => ['service_id' => 'SECRETSVC']]])),
            $this->row($vendor->id, 'Beta Bundle', json_encode(['category' => 'data', 'network' => 'MTN'])),
        ]);

        // The public text is name + service (or network when there is no service) + size + validity.
        foreach (['alpha', 'ALPHA', '3gb', 'roaming', 'ROAMING', '14 days', 'alpha roaming'] as $word) {
            $this->assertSame(['Alpha Bundle'], $this->names('data', $word), $word);
        }
        $this->assertSame(['Beta Bundle'], $this->names('data', 'mtn'), 'the network is public text when no service is named');
        $this->assertSame([], $this->names('data', 'vodafone'), 'a network hidden behind an explicit service is not searchable text');

        foreach (['SECRETNOTE', 'secretcost', 'SECRETSVC', 'xpres', 'external_mappings'] as $private) {
            $this->assertSame([], $this->names('data', $private), "{$private} is not searchable");
        }

        $this->assertSame(['Alpha Bundle', 'Beta Bundle'], $this->names('data', 'bundle'));
        $this->assertSame([], $this->names('data', 'alpha beta'), 'every word must match');
    }

    public function test_like_wildcards_in_a_search_do_not_act_as_wildcards(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->bulk([
            $this->row($vendor->id, 'axb', json_encode(['category' => 'data'])),
            $this->row($vendor->id, 'a_b', json_encode(['category' => 'data'])),
            $this->row($vendor->id, '100% data', json_encode(['category' => 'data'])),
            $this->row($vendor->id, 'plain', json_encode(['category' => 'data'])),
        ]);

        // Words made only of wildcard characters are cleaned away entirely: nothing can match (never "everything").
        foreach (['%', '_', '%%', '_%_', '\\', '%_%\\'] as $word) {
            $this->assertSame([], $this->names('data', $word), "[{$word}]");
        }

        // `_` and `%` split words; they are never SQL wildcards (`a_b` is the two words "a" and "b").
        $this->assertSame(['100% data'], array_values(array_intersect($this->names('data', '100%'), ['100% data'])));
        $this->assertNotContains('plain', $this->names('data', 'a_b'));
        $this->assertSame([], $this->names('data', 'zq_xw'), 'no row contains both words');
    }

    public function test_sql_special_characters_in_search_text_are_inert(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->bulk([$this->row($vendor->id, 'Plain', json_encode(['category' => 'data']))]);
        $before = Product::query()->count();

        foreach (["'; DROP TABLE products; --", '" OR 1=1 --', "mtn') OR ('1'='1", '\\', "x\0y"] as $word) {
            $this->assertIsArray($this->service()->search($word, 'data', 'name', 1, 10));
        }

        $this->assertSame($before, Product::query()->count());
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_the_category_condition_is_part_of_the_sql_that_fetches_products(): void
    {
        $vendor = $this->approvedVendor();
        $this->assign('data', $vendor);
        $this->bulk([$this->row($vendor->id, 'Filler', json_encode(['category' => 'shop']))]);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (preg_match('/from [`"]products[`"]/i', $query->sql) === 1) {
                $statements[] = strtolower($query->sql);
            }
        });

        $this->service()->search('bundle', 'data', 'name', 1, 5);

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertStringContainsString('json_valid(', $sql, 'the category is decided by the database, not after fetching');
            $this->assertStringContainsString('like', $sql, 'the search words are applied in SQL too');
        }
    }
}
