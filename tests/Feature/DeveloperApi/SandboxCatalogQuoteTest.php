<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Order;
use App\Models\Product;

class SandboxCatalogQuoteTest extends SandboxTestCase
{
    // ── catalog ──────────────────────────────────────────────────────────────

    public function test_the_sandbox_catalog_is_only_the_vendors_own_permitted_products(): void
    {
        $w = $this->sandboxWorld(10.0);
        $other = $this->approvedVendor();
        $foreign = $this->product($other, 7.0, [], ['name' => 'Foreign product']);

        // A reseller listing of ANOTHER vendor's resellable product belongs to this vendor as a reseller.
        $listed = $this->listing($foreign, $w['vendor'], 3.0);

        $ids = collect($this->getJson('/api/v1/products', $this->auth($w['key']))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$w['offer'], "rsl_{$listed->id}"], $ids);
        $this->assertNotContains("own_{$foreign->id}", $ids);
    }

    public function test_another_vendors_products_are_inaccessible_by_id_and_cannot_be_quoted(): void
    {
        $w = $this->sandboxWorld();
        $foreign = $this->product($this->approvedVendor(), 5.0);

        $this->getJson("/api/v1/products/own_{$foreign->id}", $this->auth($w['key']))->assertNotFound();
        $this->quote($w['key'], "own_{$foreign->id}")->assertStatus(404)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        $this->assertSame(0, ApiQuote::count());
    }

    public function test_inactive_unpublished_and_unapproved_seller_products_are_inaccessible(): void
    {
        $w = $this->sandboxWorld();
        $inactive = $this->product($w['vendor'], 4.0, [], ['is_active' => false]);
        $deleted = $this->product($w['vendor'], 4.0);
        $deleted->delete(); // soft delete: no longer published

        $ids = collect($this->getJson('/api/v1/products', $this->auth($w['key']))->json('data'))->pluck('id')->all();
        $this->assertSame([$w['offer']], $ids);

        foreach ([$inactive, $deleted] as $p) {
            $this->quote($w['key'], "own_{$p->id}")->assertStatus(404)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        }
    }

    public function test_sandbox_requests_cannot_modify_production_products(): void
    {
        $w = $this->sandboxWorld(10.0);
        $before = Product::query()->whereKey($w['product']->id)->first()->only(['price', 'is_active', 'name', 'description', 'updated_at']);

        $this->sandboxOrderId($w['key'], $w['offer']);
        $order = $this->postJson('/api/v1/products/'.$w['offer'], ['price' => 0.01], $this->auth($w['key']));
        $order->assertStatus(405);
        $this->putJson('/api/v1/products/'.$w['offer'], ['price' => 0.01], $this->auth($w['key']))->assertStatus(405);

        $this->assertEquals($before, Product::query()->whereKey($w['product']->id)->first()->only(['price', 'is_active', 'name', 'description', 'updated_at']));
    }

    public function test_only_the_data_category_is_exposed_in_the_sandbox(): void
    {
        $w = $this->sandboxWorld();
        $airtime = $this->product($w['vendor'], 2.0, ['category' => 'airtime']);
        $afa = $this->product($w['vendor'], 2.0, ['category' => 'afa']);

        $ids = collect($this->getJson('/api/v1/products', $this->auth($w['key']))->json('data'))->pluck('id')->all();
        $this->assertSame([$w['offer']], $ids);
        $this->quote($w['key'], "own_{$airtime->id}")->assertStatus(404);
        $this->quote($w['key'], "own_{$afa->id}")->assertStatus(404);

        $codes = collect($this->getJson('/api/v1/services', $this->auth($w['key']))->json('data'))->pluck('code')->all();
        $this->assertSame(['data'], $codes);
    }

    // ── quotes ───────────────────────────────────────────────────────────────

    public function test_a_sandbox_quote_has_the_vendor_specific_price_and_is_marked_simulated(): void
    {
        $w = $this->sandboxWorld(10.0);
        $reseller = $this->makeApp($this->approvedVendor());
        $listing = $this->listing($w['product'], $reseller->vendor, 2.5);
        [, $resellerKey] = $this->makeKey($reseller, null, null, 'sandbox');

        // Direct sale: the vendor's own price.
        $direct = $this->quote($w['key'], $w['offer'])->assertCreated();
        $direct->assertJsonPath('data.price', 10)->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.environment', 'sandbox')->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.availability', 'available');
        $this->assertStringStartsWith('sbx_qt_', $direct->json('data.reference'));

        // Reseller sale: base + the reseller's markup, exactly as the live API would price it.
        $this->quote($resellerKey, "rsl_{$listing->id}")->assertCreated()->assertJsonPath('data.price', 12.5);

        $row = ApiQuote::query()->where('reference', $direct->json('data.reference'))->firstOrFail();
        $this->assertSame('sandbox', $row->environment);
        $this->assertSame($w['app']->id, (int) $row->application_id);
    }

    public function test_the_sandbox_price_equals_the_production_price_for_the_same_offer(): void
    {
        $w = $this->sandboxWorld(13.37);
        $live = $this->liveKey($w['app']);

        $this->assertSame(
            $this->quote($live, $w['offer'])->assertCreated()->json('data.price'),
            $this->quote($w['key'], $w['offer'])->assertCreated()->json('data.price'),
        );
    }

    public function test_a_client_cannot_change_the_quoted_price(): void
    {
        $w = $this->sandboxWorld(10.0);

        $quote = $this->postJson('/api/v1/quotes', [
            'product_id' => $w['offer'], 'recipient_phone' => '0244000000', 'price' => 0.01, 'amount' => 0.01, 'total' => 0.01,
        ], $this->auth($w['key']))->assertCreated();
        $this->assertSame(10, $quote->json('data.price'));

        // Not even by sending the price with the order.
        $this->order($w['key'], $quote->json('data.reference'), 'k', ['amount' => 0.01, 'price' => 0.01, 'unit_price' => 0.01])->assertCreated()
            ->assertJsonPath('data.amount', 10);

        // And tampering with the stored quote cannot sneak a cheaper order through: re-pricing refuses it.
        $w2 = $this->sandboxWorld(10.0);
        $q2 = $this->quote($w2['key'], $w2['offer'])->json('data.reference');
        ApiQuote::query()->where('reference', $q2)->update(['amount' => '0.01']);
        $this->order($w2['key'], $q2)->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_PRICE_CHANGED');
    }

    public function test_an_invalid_recipient_is_a_validation_error(): void
    {
        $w = $this->sandboxWorld();

        $this->quote($w['key'], $w['offer'], '12345')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(0, ApiQuote::count());
    }

    public function test_quotes_cannot_be_reused_across_environments(): void
    {
        $this->paystack();
        $w = $this->sandboxWorld();
        $live = $this->liveKey($w['app']);

        $sbxQuote = $this->quote($w['key'], $w['offer'])->json('data.reference');
        $prodQuote = $this->quote($live, $w['offer'])->json('data.reference');

        // A sandbox quote presented with a production key, and a production quote with a sandbox key: not found.
        $this->order($live, $sbxQuote, 'x1')->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
        $this->order($w['key'], $prodQuote, 'x2')->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');

        $this->assertSame(0, Order::count());
        $this->assertNull(ApiQuote::query()->where('reference', $sbxQuote)->value('consumed_at'));
        $this->assertNull(ApiQuote::query()->where('reference', $prodQuote)->value('consumed_at'));
    }

    public function test_a_quote_is_private_to_its_application(): void
    {
        $w = $this->sandboxWorld();
        $otherApp = $this->makeApp($w['vendor']);          // same vendor, different application
        [, $otherKey] = $this->makeKey($otherApp, null, null, 'sandbox');

        $quote = $this->quote($w['key'], $w['offer'])->json('data.reference');

        $this->order($otherKey, $quote)->assertStatus(404)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
    }

    public function test_a_sandbox_quote_creates_no_financial_record(): void
    {
        $w = $this->sandboxWorld();
        $before = $this->productionFingerprint($w['vendor']);

        $this->quote($w['key'], $w['offer'])->assertCreated();

        $this->assertSame($before, $this->productionFingerprint($w['vendor']));
    }
}
