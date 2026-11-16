<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Setting;
use App\Support\ServiceAvailability;

class ApiCatalogIsolationTest extends DeveloperApiTestCase
{
    private function ids($response): array
    {
        return collect($response->json('data'))->pluck('id')->all();
    }

    public function test_marketplace_app_sees_offers_from_all_approved_vendors_with_seller_identity(): void
    {
        $a = $this->vendor(['name' => 'Vendor A']);
        $b = $this->vendor(['name' => 'Vendor B']);
        $pa = $this->product($a, 10);
        $pb = $this->product($b, 12);
        [, $plain] = $this->makeKey($this->makeApp());

        $res = $this->getJson('/api/v1/products', $this->auth($plain))->assertOk();

        $this->assertEqualsCanonicalizing(["own_{$pa->id}", "own_{$pb->id}"], $this->ids($res));
        $offer = collect($res->json('data'))->firstWhere('id', "own_{$pa->id}");
        $this->assertSame(['code' => $a->vendor_code, 'name' => 'Vendor A'], $offer['seller']);
        $this->assertSame('MTN', $offer['network']);
        $this->assertEquals(10.0, $offer["price"]);
        $this->assertSame('GHS', $offer['currency']);
    }

    public function test_only_active_available_and_publicly_sellable_offers_appear(): void
    {
        $good = $this->vendor();
        $unapproved = $this->vendor(['is_approved' => false]);
        $ok = $this->product($good, 10);
        $this->product($good, 10, [], ['is_active' => false]);
        $this->product($good, 0);
        $this->product($unapproved, 10);
        $deleted = $this->product($good, 10);
        $deleted->delete();
        // Not an exposed category (e.g. shop/results/afa have their own flows).
        $this->product($good, 10, ['category' => 'shop']);
        [, $plain] = $this->makeKey($this->makeApp());

        $this->assertSame(["own_{$ok->id}"], $this->ids($this->getJson('/api/v1/products', $this->auth($plain))->assertOk()));
    }

    public function test_reseller_listings_follow_the_marketplace_rules_and_price_is_base_plus_markup(): void
    {
        $owner = $this->vendor();
        $reseller = $this->vendor();
        $p = $this->product($owner, 10);
        $l = $this->listing($p, $reseller, 2.5);
        [, $plain] = $this->makeKey($this->makeApp());

        $res = $this->getJson('/api/v1/products', $this->auth($plain))->assertOk();
        $offer = collect($res->json('data'))->firstWhere('id', "rsl_{$l->id}");

        $this->assertEquals(12.5, $offer["price"]);
        $this->assertSame($reseller->vendor_code, $offer['seller']['code']);
        $this->assertSame('reseller', $offer['type']);

        // Non-resellable base product => listing disappears.
        $p->update(['is_resellable' => false]);
        $this->assertNotContains("rsl_{$l->id}", $this->ids($this->getJson('/api/v1/products', $this->auth($plain))));
    }

    public function test_closed_service_category_is_not_offered(): void
    {
        $v = $this->vendor();
        $this->product($v, 10);
        [, $plain] = $this->makeKey($this->makeApp());

        ServiceAvailability::setOpen('data', false);

        $this->assertSame([], $this->ids($this->getJson('/api/v1/products', $this->auth($plain))->assertOk()));
    }

    public function test_filters_pagination_and_sorting(): void
    {
        $v = $this->vendor();
        $this->product($v, 30, ['network' => 'MTN', 'size' => '10GB'], ['name' => 'MTN 10GB']);
        $this->product($v, 10, ['network' => 'MTN', 'size' => '2GB'], ['name' => 'MTN 2GB']);
        $this->product($v, 20, ['network' => 'TELECEL', 'size' => '5GB'], ['name' => 'Telecel 5GB']);
        [, $plain] = $this->makeKey($this->makeApp());

        $mtn = $this->getJson('/api/v1/products?network=mtn', $this->auth($plain))->assertOk();
        $this->assertEquals([10.0, 30.0], collect($mtn->json('data'))->pluck('price')->all());

        $range = $this->getJson('/api/v1/products?min_price=15&max_price=25', $this->auth($plain));
        $this->assertEquals([20.0], collect($range->json('data'))->pluck('price')->all());

        $page = $this->getJson('/api/v1/products?per_page=2&page=2&sort=price', $this->auth($plain));
        $this->assertEquals([30.0], collect($page->json('data'))->pluck('price')->all());
        $page->assertJsonPath('meta.pagination.total', 3)->assertJsonPath('meta.pagination.last_page', 2);

        $this->getJson('/api/v1/products?per_page=1000', $this->auth($plain))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_vendor_bound_app_sees_only_its_own_vendors_offers(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $own = $this->product($mine, 10);
        $theirs = $this->product($other, 5);
        $myListing = $this->listing($theirs, $mine, 1);          // my reseller listing of their product
        $theirListing = $this->listing($own, $other, 1);         // THEIR listing of my product
        [, $plain] = $this->makeKey($this->makeApp('production', $mine));

        $ids = $this->ids($this->getJson('/api/v1/products', $this->auth($plain))->assertOk());

        $this->assertEqualsCanonicalizing(["own_{$own->id}", "rsl_{$myListing->id}"], $ids);
        $this->assertNotContains("own_{$theirs->id}", $ids);
        $this->assertNotContains("rsl_{$theirListing->id}", $ids);
    }

    public function test_vendor_bound_app_cannot_fetch_another_vendors_offer_by_id(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $theirs = $this->product($other, 5);
        [, $plain] = $this->makeKey($this->makeApp('production', $mine));

        $this->getJson("/api/v1/products/own_{$theirs->id}", $this->auth($plain))
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        // ...and it is indistinguishable from an id that never existed.
        $this->getJson('/api/v1/products/own_999999', $this->auth($plain))->assertStatus(404);
    }

    public function test_vendor_vendor_filter_cannot_widen_a_bound_apps_scope(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $this->product($mine, 10);
        $this->product($other, 5);
        [, $plain] = $this->makeKey($this->makeApp('production', $mine));

        $res = $this->getJson('/api/v1/products?vendor='.$other->vendor_code, $this->auth($plain))->assertOk();

        $this->assertSame([], $res->json('data'));
    }

    public function test_unapproved_vendor_link_yields_an_empty_catalog_never_the_marketplace(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $this->product($mine, 10);
        $this->product($other, 5);
        [, $plain] = $this->makeKey($this->makeApp('production', $mine, 'pending'));

        $this->assertSame([], $this->getJson('/api/v1/products', $this->auth($plain))->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/networks', $this->auth($plain))->assertOk()->json('data'));
    }

    public function test_networks_and_services_are_derived_from_visible_offers_and_exposed_categories(): void
    {
        $v = $this->vendor();
        $this->product($v, 10, ['network' => 'MTN']);
        $this->product($v, 10, ['network' => 'MTN']);
        $this->product($v, 10, ['network' => 'TELECEL']);
        [, $plain] = $this->makeKey($this->makeApp());

        $networks = collect($this->getJson('/api/v1/networks', $this->auth($plain))->assertOk()->json('data'));
        $this->assertSame(2, $networks->firstWhere('code', 'MTN')['offers']);
        $this->assertNotNull($networks->firstWhere('code', 'TELECEL'));

        $services = $this->getJson('/api/v1/services', $this->auth($plain))->assertOk()->json('data');
        $this->assertSame(['data'], collect($services)->pluck('code')->all());
    }

    public function test_responses_never_expose_vendor_contact_or_internal_fields(): void
    {
        $v = $this->vendor();
        $p = $this->product($v, 10);
        [, $plain] = $this->makeKey($this->makeApp());

        $body = $this->getJson("/api/v1/products/own_{$p->id}", $this->auth($plain))->assertOk()->getContent();

        foreach ([$v->email, (string) $v->phone_number, 'password', 'balance', 'min_base_price', 'vendor_id'] as $needle) {
            if ($needle !== '') {
                $this->assertStringNotContainsString($needle, $body);
            }
        }
    }
}
