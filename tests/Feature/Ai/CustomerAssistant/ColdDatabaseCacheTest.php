<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Models\Product;
use App\Models\Setting;
use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\Tools\Exceptions\ToolWriteBlockedException;
use App\Services\Ai\Tools\ReadOnlyGuard;
use App\Services\Catalogue\PublicCatalogueReadService;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The production cache configuration: `CACHE_STORE=database` with a COLD cache.
 *
 * Why this file exists: `Setting::get()` is a read-through cache (`Cache::remember`). With the database cache store a
 * miss writes (`INSERT INTO cache ...`, or a DELETE for a null/expired entry). The tools run inside the ReadOnlyGuard,
 * which - correctly - refuses every non-SELECT statement, so a tool that reached `Setting::get()` failed with
 * `read_only_violation` whenever the settings cache was cold (and always for a setting that has no row). The array cache
 * used by the rest of the suite hides this completely, so every test here runs on the database store, asserts that the
 * relevant cache entries really are absent first, and never warms the cache on a test's behalf.
 */
class ColdDatabaseCacheTest extends CustomerAssistantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database']);
        Cache::forgetDriver();
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
    }

    /** @return list<string> every settings cache entry currently stored */
    private function settingCacheKeys(): array
    {
        return DB::table('cache')->where('key', 'like', '%setting%')->pluck('key')->all();
    }

    private function assertSettingsCacheIsCold(string $when = ''): void
    {
        $this->assertSame([], $this->settingCacheKeys(), 'the settings cache must be absent '.$when);
    }

    /** @return array<string,string> md5 of every table's contents, except the framework's own cache/lock/session tables */
    private function businessSnapshot(): array
    {
        $skip = ['cache', 'cache_locks', 'sessions', ...self::DURABLE_TABLES]; // the budget ledger legitimately changes on every turn
        $snapshot = [];

        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? $table['name'] : $table;
            if (! in_array($name, $skip, true)) {
                $snapshot[$name] = md5(DB::table($name)->get()->toJson());
            }
        }

        return $snapshot;
    }

    // ------------------------------------------------------------------------------------------ root cause

    public function test_root_cause_setting_get_inside_the_read_only_guard_is_a_blocked_cache_write(): void
    {
        $seen = [];
        DB::beforeExecuting(function (string $query) use (&$seen) {
            $seen[] = $query;
        });
        $this->assertSettingsCacheIsCold('before the call');

        $blocked = false;
        try {
            (new ReadOnlyGuard)->run(fn () => Setting::get('service_open.data', '1'));
        } catch (ToolWriteBlockedException) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'the guard must keep refusing cache writes made during a tool');
        $this->assertMatchesRegularExpression('/\b(insert|replace|delete|update)\b.*\bcache\b/is', (string) end($seen), 'the refused statement is the cache write');
    }

    // ------------------------------------------------------------------------------------------ the four tools, cold

    public function test_all_four_tools_work_with_a_cold_database_cache(): void
    {
        $this->catalogue();
        $this->faq('How do I pay?', 'Choose the package on the service page, then pay at checkout.');
        $this->assertSettingsCacheIsCold('before any tool ran (fixtures must not have warmed it)');

        $services = $this->runTool('customer_list_services', [], null, 'c1');
        $this->assertTrue($services->isOk(), (string) $services->errorCode);
        $this->assertSame(
            ['data' => 'available', 'shop' => 'available', 'results' => 'available', 'afa' => 'available', 'utility_bills' => 'see_page'],
            collect($services->data['services'])->pluck('availability', 'category')->all(),
        );

        $search = $this->runTool('customer_search_products', ['query' => 'mtn', 'category' => 'data'], null, 'c2');
        $this->assertTrue($search->isOk(), (string) $search->errorCode);
        $this->assertSame(['MTN 1GB', 'MTN 5GB'], array_column($search->data['products'], 'name'));
        $this->assertSame(['5.00', '20.00'], array_column($search->data['products'], 'price'));

        $product = $this->runTool('customer_get_product', ['product_ref' => $search->data['products'][0]['ref']], null, 'c3');
        $this->assertTrue($product->isOk(), (string) $product->errorCode);
        $this->assertTrue($product->data['found']);
        $this->assertSame('5.00', $product->data['product']['price']);

        $help = $this->runTool('customer_search_help', ['query' => 'pay at checkout'], null, 'c4');
        $this->assertTrue($help->isOk(), (string) $help->errorCode);
        $this->assertSame(1, $help->data['returned']);

        // The tools only read: they left no settings cache entry behind and warmed nothing on anyone's behalf.
        $this->assertSettingsCacheIsCold('after the tools ran');
    }

    public function test_each_tool_works_on_its_own_with_nothing_else_having_touched_the_cache(): void
    {
        $this->catalogue();
        $this->faq('How do I pay?', 'Pay at checkout.');
        $calls = [
            ['customer_list_services', []],
            ['customer_search_products', ['query' => 'telecel']],
            ['customer_get_product', ['product_ref' => 'product_1']],
            ['customer_get_product', ['product_ref' => 'result_checker_1']],
            ['customer_search_help', ['query' => 'checkout']],
        ];

        foreach ($calls as $i => [$name, $args]) {
            DB::table('cache')->delete();
            $this->assertSettingsCacheIsCold("before {$name}");
            $result = $this->runTool($name, $args, null, 'x'.$i);
            $this->assertTrue($result->isOk(), "{$name}: ".$result->errorCode);
        }
    }

    public function test_settings_that_have_no_row_work_cold_and_mean_what_they_always_meant(): void
    {
        // No catalogue, no assignment, no service_open row at all: every category is "open" by default but has no vendor.
        // (Migrations seed unrelated settings, so check the keys that matter and that the count does not move.)
        $before = Setting::query()->count();
        $this->assertSame(0, Setting::query()->where('key', 'like', 'service_open.%')->orWhere('key', 'like', 'platform_service_vendor.%')->count());

        $services = $this->runTool('customer_list_services', []);
        $this->assertTrue($services->isOk(), (string) $services->errorCode);
        $this->assertSame(
            ['data' => 'unavailable', 'shop' => 'unavailable', 'results' => 'unavailable', 'afa' => 'unavailable', 'utility_bills' => 'see_page'],
            collect($services->data['services'])->pluck('availability', 'category')->all(),
        );

        $search = $this->runTool('customer_search_products', ['query' => 'data'], null, 'b');
        $this->assertTrue($search->isOk(), (string) $search->errorCode);
        $this->assertSame([], $search->data['products']);

        $product = $this->runTool('customer_get_product', ['product_ref' => 'product_1'], null, 'c');
        $this->assertTrue($product->isOk());
        $this->assertFalse($product->data['found']);
        $this->assertSame($before, Setting::query()->count(), 'reading settings must not create settings');
    }

    // ------------------------------------------------------------------------------------------ warm cache

    public function test_the_tools_keep_working_and_agree_after_the_cache_has_been_warmed(): void
    {
        $this->catalogue();
        $this->assertSettingsCacheIsCold();
        $cold = [
            $this->runTool('customer_list_services', [], null, 'a')->data,
            $this->runTool('customer_search_products', ['limit' => 10], null, 'b')->data,
            $this->runTool('customer_get_product', ['product_ref' => 'product_1'], null, 'c')->data,
        ];

        // Warm it the way an ordinary public page does.
        foreach (['data', 'shop', 'results', 'afa'] as $category) {
            ServiceAvailability::isClosed($category);
            PlatformServiceVendor::resolve($category);
        }
        $this->assertNotSame([], $this->settingCacheKeys(), 'the cache really is warm now');

        $warm = [
            $this->runTool('customer_list_services', [], null, 'a2')->data,
            $this->runTool('customer_search_products', ['limit' => 10], null, 'b2')->data,
            $this->runTool('customer_get_product', ['product_ref' => 'product_1'], null, 'c2')->data,
        ];

        $this->assertSame($cold, $warm);
        // Running the tools a second time changes nothing either.
        $this->assertSame($warm[1], $this->runTool('customer_search_products', ['limit' => 10], null, 'b3')->data);
    }

    // ------------------------------------------------------------------------------------------ authoritative settings

    public function test_availability_and_vendor_assignment_follow_the_settings_table_when_cold(): void
    {
        $w = $this->catalogue();
        $this->assertSettingsCacheIsCold();

        $this->closeCategory('data');
        $this->assertSettingsCacheIsCold('after closing via the admin path');
        $services = collect($this->runTool('customer_list_services', [])->data['services'])->pluck('availability', 'category');
        $this->assertSame('closed', $services['data']);
        $this->assertSame('available', $services['shop']);
        $this->assertSame([], $this->runTool('customer_search_products', ['category' => 'data'], null, 'b')->data['products']);
        $this->assertFalse($this->runTool('customer_get_product', ['product_ref' => 'product_'.$w['p1']->id], null, 'c')->data['found']);

        ServiceAvailability::setOpen('data', true);
        $this->assertTrue($this->runTool('customer_get_product', ['product_ref' => 'product_'.$w['p1']->id], null, 'd')->data['found']);

        // Re-assigning the category to an unapproved vendor hides it; to nobody, too.
        $this->assign('data', $w['z']);
        $w['z']->forceFill(['is_approved' => false])->save();
        $this->assertSame([], $this->runTool('customer_search_products', ['category' => 'data'], null, 'e')->data['products']);
        $this->assign('data', null);
        $this->assertSame('unavailable', collect($this->runTool('customer_list_services', [], null, 'f')->data['services'])->pluck('availability', 'category')['data']);
    }

    public function test_the_tools_read_the_settings_table_not_a_stale_cache_entry(): void
    {
        $w = $this->catalogue();
        ServiceAvailability::setOpen('data', true);
        $this->assertTrue(ServiceAvailability::isOpen('data'), 'warms the cached entry');
        $this->assertNotSame([], $this->settingCacheKeys());

        // Changed behind the cache's back (a direct edit): the public page may keep its cached answer for up to an hour,
        // but the assistant must follow the authoritative table.
        DB::table('settings')->where('key', 'service_open.data')->update(['value' => '0']);

        $this->assertTrue(ServiceAvailability::isOpen('data'), 'the cached read is stale, as it always was');
        $this->assertFalse($this->runTool('customer_get_product', ['product_ref' => 'product_'.$w['p1']->id])->data['found']);
    }

    public function test_an_expired_cache_entry_cannot_trip_the_guard(): void
    {
        $this->catalogue();
        $prefix = (string) config('cache.prefix');
        DB::table('cache')->insert([
            ['key' => $prefix.'setting.service_open.data', 'value' => serialize('0'), 'expiration' => time() - 600],
            ['key' => $prefix.'setting.platform_service_vendor.data', 'value' => serialize(null), 'expiration' => time() - 600],
        ]);

        $services = $this->runTool('customer_list_services', []);

        $this->assertTrue($services->isOk(), (string) $services->errorCode);
        $this->assertSame(2, DB::table('cache')->where('key', 'like', '%setting%')->count(), 'the tools do not even look at the cache');
    }

    // ------------------------------------------------------------------------------------------ parity with the cached reads

    public function test_the_uncached_reads_mean_exactly_what_the_cached_reads_mean(): void
    {
        $approved = $this->approvedVendor();
        $pending = $this->approvedVendor(['is_approved' => false]);
        $pending->forceFill(['is_approved' => false])->save();

        foreach ([null, '1', '0', '', 'banana'] as $value) {
            DB::table('settings')->where('key', ServiceAvailability::key('data'))->delete();
            if ($value !== null) {
                Setting::set(ServiceAvailability::key('data'), $value, ServiceAvailability::GROUP);
            }
            Cache::flush();

            $this->assertSame(ServiceAvailability::isOpen('data'), ServiceAvailability::isOpenFresh('data'), 'open: '.var_export($value, true));
            $this->assertSame(ServiceAvailability::isClosed('data'), ServiceAvailability::isClosedFresh('data'), 'closed: '.var_export($value, true));
        }

        foreach ([null, '', (string) $approved->id, (string) $pending->id, '999999', 'abc', '0'] as $value) {
            DB::table('settings')->where('key', PlatformServiceVendor::key('data'))->delete();
            if ($value !== null) {
                Setting::set(PlatformServiceVendor::key('data'), $value, PlatformServiceVendor::GROUP);
            }
            Cache::flush();

            $this->assertSame(PlatformServiceVendor::vendorIdFor('data'), PlatformServiceVendor::vendorIdForFresh('data'), 'vendor id: '.var_export($value, true));
            $this->assertSame(PlatformServiceVendor::resolve('data')?->id, PlatformServiceVendor::resolveFresh('data')?->id, 'vendor: '.var_export($value, true));
        }

        $this->assertSame('fallback', Setting::getFresh('no.such.key', 'fallback'));
        Setting::set('some.key', '', 'general');
        $this->assertSame(Setting::get('some.key', 'fallback'), Setting::getFresh('some.key', 'fallback'));
    }

    // ------------------------------------------------------------------------------------------ whole turn

    public function test_a_whole_cold_turn_through_the_endpoint_answers_changes_no_business_table_and_stores_no_conversation(): void
    {
        $this->catalogue();
        $this->faq('How do I pay?', 'Choose the package, then pay at checkout.');
        $this->assertSettingsCacheIsCold();
        $before = $this->businessSnapshot();

        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('customer_list_services', [], 'c1'),
                $this->toolCall('customer_search_products', ['query' => 'TOOLARG_CANARY mtn'], 'c2'),
                $this->toolCall('customer_get_product', ['product_ref' => 'product_1'], 'c3'),
                $this->toolCall('customer_search_help', ['query' => 'pay'], 'c4'),
            ]),
            $this->textResponse('GIRAFFE_CANARY here are the bundles'),
        );

        $response = $this->say('ZEBRA_CANARY what bundles do you have');

        $response->assertOk()->assertJsonPath('answer', 'GIRAFFE_CANARY here are the bundles');
        $this->assertEqualsCanonicalizing(CustomerAssistant::TOOLS, $response->json('tools_used'), 'all four tools succeeded on a cold cache');
        $this->assertNotSame([], $response->json('links'));

        $this->assertSame([], array_keys(array_diff_assoc($before, $this->businessSnapshot())), 'no business table changed');

        foreach (array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()) as $table) {
            foreach (DB::table($table)->get() as $row) {
                $flat = json_encode($row);
                foreach (['ZEBRA_CANARY', 'GIRAFFE_CANARY', 'TOOLARG_CANARY'] as $canary) {
                    $this->assertStringNotContainsString($canary, (string) $flat, "{$canary} was persisted in {$table}");
                }
            }
        }
        $this->assertSame([], $this->settingCacheKeys(), 'the turn did not write settings into the cache either');
    }

    // ------------------------------------------------------------------------------------------ the guard is intact

    public function test_the_read_only_guard_still_refuses_business_and_cache_writes_and_allows_reads(): void
    {
        $w = $this->catalogue();
        $guard = new ReadOnlyGuard;
        $productCount = Product::query()->count();

        $this->assertSame($productCount, $guard->run(fn () => Product::query()->count()), 'plain reads still work');

        $writes = [
            'insert into a business table' => fn () => DB::table('products')->insert(['vendor_id' => $w['a']->id, 'name' => 'X', 'price' => 1]),
            'update a business table' => fn () => Product::query()->whereKey($w['p1']->id)->update(['price' => 0.01]),
            'delete from a business table' => fn () => Product::query()->whereKey($w['p1']->id)->delete(),
            'update settings' => fn () => DB::table('settings')->update(['value' => '0']),
            'Setting::set' => fn () => Setting::set('x.y', '1', 'general'),
            'Cache::put' => fn () => Cache::put('anything', 'x', 60),
            'Cache::forget' => fn () => Cache::forget('anything'),
            'cache lock' => fn () => Cache::lock('anything', 5)->get(),
            'stacked statement' => fn () => DB::select('select 1; delete from products'),
        ];

        foreach ($writes as $label => $write) {
            try {
                $guard->run($write);
                $this->fail("the guard allowed: {$label}");
            } catch (ToolWriteBlockedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame('5.00', number_format((float) Product::query()->find($w['p1']->id)->price, 2, '.', ''));
        $this->assertSame('1', Setting::getFresh('service_open.data', '1'));
        $this->assertFalse($guard->isActive(), 'the guard is released after refusing');
    }

    // ------------------------------------------------------------------------------------------ architecture

    public function test_the_catalogue_service_never_touches_the_cached_settings_path_or_the_cache(): void
    {
        $source = '';
        foreach (\PhpToken::tokenize((string) file_get_contents(app_path('Services/Catalogue/PublicCatalogueReadService.php'))) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $source .= $token->text;
            }
        }

        foreach (['Setting::get(', 'Setting::getGroup(', 'isClosed(', 'isOpen(', 'vendorIdFor(', 'PlatformServiceVendor::resolve(', 'Cache::', 'cache(', 'remember('] as $cachedPath) {
            $this->assertStringNotContainsString($cachedPath, $source, "PublicCatalogueReadService reaches the cached settings path via {$cachedPath}");
        }
        $this->assertStringContainsString('ServiceAvailability::isClosedFresh(', $source);
        $this->assertStringContainsString('PlatformServiceVendor::resolveFresh(', $source);

        // The service itself is a plain read model: it resolves from the container and reads without a guard around it.
        $this->assertInstanceOf(PublicCatalogueReadService::class, app(PublicCatalogueReadService::class));
    }
}
