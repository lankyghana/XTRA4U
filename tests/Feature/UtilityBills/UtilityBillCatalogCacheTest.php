<?php

namespace Tests\Feature\UtilityBills;

use App\Services\UtilityBills\Exceptions\ProviderUnreachable;
use App\Services\UtilityBills\Exceptions\SaleNotAllowed;
use App\Services\UtilityBills\KingFlexyUtilityProvider;
use App\Services\UtilityBills\UtilityBillAvailability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Provider catalog caching: single-flight refresh, no waiting behind another refresh when a
 * recent copy exists, bounded staleness for DISPLAY only, and sales that always fail closed.
 * (Multi-process single-flight is covered against MySQL in UtilityBillMySqlConcurrencyTest.)
 */
class UtilityBillCatalogCacheTest extends UtilityBillTestCase
{
    private const LOCK = 'utility_bills.catalog.refresh';

    private int $outageCalls = 0;

    /** The provider times out; counted here because Http::recorded() skips requests whose fake throws. */
    private function fakeOutage(): void
    {
        $this->fake([self::BASE.'/utilities/billers' => function () {
            $this->outageCalls++;
            throw new ConnectionException('timed out');
        }]);
    }

    private function billerCalls(): int
    {
        return Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/billers'))->count();
    }

    private function provider(): KingFlexyUtilityProvider
    {
        return app(KingFlexyUtilityProvider::class);
    }

    /**
     * A good catalog was fetched; then the fresh copy expired (the stale copy remains). Expired by
     * removal rather than time travel: Laravel's Lock::block() measures its wait with now(), which
     * travel() freezes, so a contended lock would never time out in a test.
     */
    private function warmThenExpire(array $body = []): void
    {
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody($body))]);
        $this->provider()->catalog();
        Cache::forget('utility_bills.catalog');
    }

    public function test_an_expired_catalog_is_refreshed_once_for_many_renders(): void
    {
        $this->warmThenExpire();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        for ($i = 0; $i < 25; $i++) {
            [$catalog, $stale] = $this->provider()->catalogForDisplay();
            $this->assertNotNull($catalog);
        }

        $this->assertSame(1, $this->billerCalls());
        $this->assertFalse($stale);
    }

    public function test_pages_never_wait_behind_a_refresh_in_progress_when_a_recent_copy_exists(): void
    {
        $this->warmThenExpire();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->assertTrue(Cache::lock(self::LOCK, 30)->get(), 'another process is refreshing');

        $started = microtime(true);
        [$catalog, $stale] = $this->provider()->catalogForDisplay();

        $this->assertLessThan(0.5, microtime(true) - $started, 'served the recent copy at once');
        $this->assertNotNull($catalog);
        $this->assertTrue($stale);
        $this->assertSame(0, $this->billerCalls());
        $this->assertNull(Cache::get('utility_bills.catalog.failed'), 'contention is not a provider failure');
    }

    public function test_with_no_copy_at_all_a_page_waits_briefly_then_shows_unavailable_without_calling(): void
    {
        config(['utility_bills.catalog_refresh_wait' => 1]);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $lock = Cache::lock(self::LOCK, 30);
        $this->assertTrue($lock->get());

        $started = microtime(true);
        [$catalog] = $this->provider()->catalogForDisplay();

        $this->assertLessThan(2.5, microtime(true) - $started);
        $this->assertNull($catalog);
        $this->assertSame(0, $this->billerCalls());
        $this->assertNull(Cache::get('utility_bills.catalog.failed'));

        // Once the refresh in progress is gone, the next render refreshes normally.
        $lock->release();
        [$catalog, $stale] = $this->provider()->catalogForDisplay();
        $this->assertNotNull($catalog);
        $this->assertFalse($stale);
        $this->assertSame(1, $this->billerCalls());
    }

    public function test_a_sale_never_uses_the_stale_copy_even_while_another_process_refreshes(): void
    {
        config(['services.kingflexy_utilities.timeout' => 1]);
        $this->openService(['dstv']);
        $this->warmThenExpire();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        $this->assertTrue(Cache::lock(self::LOCK, 30)->get());

        try {
            app(UtilityBillAvailability::class)->assertSellable('dstv');
            $this->fail('a sale was authorised from a stale catalog');
        } catch (SaleNotAllowed $e) {
            $this->assertSame('provider_unavailable', $e->reason);
        }
        $this->assertSame(0, $this->billerCalls());
    }

    public function test_a_failed_sale_path_refresh_is_not_retried_by_every_waiting_checkout(): void
    {
        $this->openService(['dstv']);
        $this->warmThenExpire();
        $this->fakeOutage();

        for ($i = 0; $i < 8; $i++) {
            try {
                app(UtilityBillAvailability::class)->assertSellable('dstv');
                $this->fail('sold during an outage');
            } catch (SaleNotAllowed) {
                // fail closed
            }
        }
        $this->assertSame(1, $this->outageCalls, 'one provider call for eight checkouts');

        // After the short sale-path memory, the next checkout asks the provider again.
        $this->travel((int) config('utility_bills.catalog_sale_failure_ttl') + 1)->seconds();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
        [$biller] = app(UtilityBillAvailability::class)->assertSellable('dstv');
        $this->assertSame('dstv', $biller->key);
    }

    public function test_display_failures_are_remembered_for_display_and_staleness_is_bounded(): void
    {
        $this->openService(['dstv']);
        $this->warmThenExpire();
        $this->fakeOutage();

        // Many renders during an outage: one provider call, the last good copy shown.
        for ($i = 0; $i < 10; $i++) {
            [$catalog, $stale] = $this->provider()->catalogForDisplay();
            $this->assertNotNull($catalog);
            $this->assertTrue($stale);
        }
        $this->assertSame(1, $this->outageCalls);
        $this->get(route('services.utility-bills'))->assertOk();

        // Past catalog_stale_ttl there is no copy to show: the page closes rather than show stale data.
        $this->travel((int) config('utility_bills.catalog_stale_ttl') + 1)->seconds();
        [$catalog] = $this->provider()->catalogForDisplay();
        $this->assertNull($catalog);
        $this->get(route('services.utility-bills'))->assertStatus(503);
    }

    public function test_a_biller_the_provider_disabled_cannot_be_bought_from_a_stale_listing(): void
    {
        $this->openService(['dstv']);
        $this->warmThenExpire();

        // The provider has since disabled DSTV. The page may briefly still list it from the
        // stale copy while a refresh is in progress, but the sale check asks the provider.
        $this->assertTrue(Cache::lock(self::LOCK, 30)->get());
        [$catalog, $stale] = $this->provider()->catalogForDisplay();
        $this->assertTrue($stale);
        $this->assertTrue($catalog->biller('dstv')->enabled);
        Cache::lock(self::LOCK)->forceRelease();

        $disabled = $this->billersBody();
        $disabled['data']['billers'] = array_map(fn ($b) => $b['key'] === 'dstv' ? ['enabled' => false] + $b : $b, $disabled['data']['billers']);
        $this->fake([self::BASE.'/utilities/billers' => Http::response($disabled)]);

        $this->expectException(SaleNotAllowed::class);
        app(UtilityBillAvailability::class)->assertSellable('dstv');
    }

    public function test_lock_contention_on_the_sale_path_waits_for_the_refresher_then_fails_closed(): void
    {
        config(['services.kingflexy_utilities.timeout' => 1]);
        $this->assertTrue(Cache::lock(self::LOCK, 30)->get());

        $started = microtime(true);
        try {
            $this->provider()->catalog();
            $this->fail('no catalog should be available');
        } catch (ProviderUnreachable) {
            $this->assertGreaterThan(1.5, microtime(true) - $started, 'waited for the refresh in progress');
            $this->assertLessThan(4, microtime(true) - $started, 'but only briefly');
        }
    }
}
