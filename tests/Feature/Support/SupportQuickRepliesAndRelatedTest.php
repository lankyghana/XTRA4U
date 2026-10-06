<?php

namespace Tests\Feature\Support;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\ResultCheckerOrder;
use App\Models\SupportConversation;
use App\Models\SupportQuickReply;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use App\Models\WalletTopup;

class SupportQuickRepliesAndRelatedTest extends SupportTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    // ---- Quick replies ---------------------------------------------

    public function test_seeded_starter_replies_exist_and_are_ordinary_rows(): void
    {
        $this->assertGreaterThanOrEqual(2, SupportQuickReply::count());
        $this->assertTrue(SupportQuickReply::where('body', 'like', '%Order ID%')->exists());
    }

    public function test_only_active_quick_replies_are_offered_to_the_composer(): void
    {
        $active = SupportQuickReply::create(['title' => 'Active one', 'body' => 'Hello active', 'is_active' => true, 'sort_order' => 1]);
        $inactive = SupportQuickReply::create(['title' => 'Hidden one', 'body' => 'Hello hidden', 'is_active' => false, 'sort_order' => 2]);

        $ids = collect($this->actingAs($this->adminModel(), 'admin')
            ->get(route('admin.support.quick-replies.available'), self::JSON)->assertOk()->json('replies'))->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_quick_replies_can_be_filtered_by_category_and_include_general_ones(): void
    {
        $order = $this->category('order');
        $wallet = $this->category('wallet');
        SupportQuickReply::query()->delete();
        $general = SupportQuickReply::create(['title' => 'General', 'body' => 'g', 'category_id' => null, 'sort_order' => 1]);
        $forOrder = SupportQuickReply::create(['title' => 'Order', 'body' => 'o', 'category_id' => $order->id, 'sort_order' => 2]);
        SupportQuickReply::create(['title' => 'Wallet', 'body' => 'w', 'category_id' => $wallet->id, 'sort_order' => 3]);

        $ids = collect($this->actingAs($this->adminModel(), 'admin')
            ->get(route('admin.support.quick-replies.available', ['category' => $order->id]), self::JSON)->json('replies'))->pluck('id')->all();

        $this->assertSame([$general->id, $forOrder->id], $ids);
    }

    public function test_vendors_cannot_reach_quick_reply_management_or_the_list(): void
    {
        $this->actingAs($this->vendor(), 'vendor');

        $this->assertContains($this->get(route('admin.support.quick-replies.available'))->getStatusCode(), [302, 403]);
        $this->assertContains($this->post(route('admin.support.quick-replies.store'), ['title' => 'x', 'body' => 'y'])->getStatusCode(), [302, 403]);
        $this->assertSame(0, SupportQuickReply::where('title', 'x')->count());
    }

    public function test_admin_can_create_edit_toggle_reorder_and_delete_quick_replies(): void
    {
        $this->actingAs($this->adminModel(), 'admin');
        SupportQuickReply::query()->delete();

        $this->post(route('admin.support.quick-replies.store'), ['title' => 'First', 'body' => 'Body 1', 'is_active' => '1'])->assertRedirect();
        $this->post(route('admin.support.quick-replies.store'), ['title' => 'Second', 'body' => 'Body 2', 'is_active' => '1'])->assertRedirect();
        [$first, $second] = [SupportQuickReply::where('title', 'First')->first(), SupportQuickReply::where('title', 'Second')->first()];

        $this->get(route('admin.support.quick-replies.index'))->assertOk()->assertSee('First')->assertSee('Second');
        $this->get(route('admin.support.quick-replies.edit', $first->id))->assertOk()->assertSee('Body 1');

        $this->put(route('admin.support.quick-replies.update', $first->id), ['title' => 'First edited', 'body' => 'Edited body', 'category_id' => $this->category()->id, 'is_active' => '1'])->assertRedirect();
        $this->assertSame('Edited body', $first->fresh()->body);
        $this->assertSame($this->category()->id, (int) $first->fresh()->category_id);

        // Reorder: move Second above First.
        $this->post(route('admin.support.quick-replies.move', $second->id), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['Second', 'First edited'], SupportQuickReply::ordered()->pluck('title')->all());

        // Deactivate: no longer offered; reactivate: offered again.
        $this->post(route('admin.support.quick-replies.toggle', $second->id));
        $this->assertFalse($second->fresh()->is_active);
        $this->assertSame(['First edited'], collect($this->get(route('admin.support.quick-replies.available'), self::JSON)->json('replies'))->pluck('title')->all());
        $this->post(route('admin.support.quick-replies.toggle', $second->id));
        $this->assertTrue($second->fresh()->is_active);

        $this->delete(route('admin.support.quick-replies.destroy', $first->id))->assertRedirect();
        $this->assertNull(SupportQuickReply::find($first->id));
    }

    public function test_quick_reply_validation(): void
    {
        $this->actingAs($this->adminModel(), 'admin')
            ->post(route('admin.support.quick-replies.store'), ['title' => '', 'body' => ''])
            ->assertSessionHasErrors(['title', 'body']);
    }

    public function test_an_admin_can_send_an_edited_quick_reply_as_a_normal_message(): void
    {
        $c = $this->startConversation($this->vendor());
        $reply = SupportQuickReply::firstOrFail();

        $this->actingAs($this->adminModel(), 'admin')
            ->post(route('admin.support.send', $c->id), ['message' => $reply->body.' (edited)'], self::JSON)
            ->assertCreated();

        $this->assertStringEndsWith('(edited)', $c->messages()->latest('id')->first()->body);
    }

    // ---- Related records -------------------------------------------

    private function order(Vendor $vendor, array $extra = []): Order
    {
        return Order::create([
            'recipient_phone_number' => '0240000000', 'mobile_money_number' => '0240000000', 'service_purchased' => 'MTN 1GB',
            'amount_paid' => 5, 'vendor_id' => $vendor->id, 'status' => 'Processing', 'payment_status' => 'paid',
        ] + $extra);
    }

    private function create(Vendor $vendor, ?string $type, mixed $id)
    {
        return $this->actingAs($vendor, 'vendor')->post(route('vendor.support.store'), [
            'category_id' => $this->category()->id,
            'message' => 'About a record',
            'related_type' => $type,
            'related_id' => $id,
        ], self::JSON);
    }

    private function records(Vendor $v): array
    {
        $order = $this->order($v);

        return [
            'order' => $order->id,
            'transaction' => Transaction::create([
                'order_id' => $order->id, 'vendor_id' => $v->id, 'recipient_phone' => '0240000000', 'amount' => 5,
                'commission_amount' => 0, 'vendor_earning' => 0, 'payment_status' => 'pending',
            ])->id,
            'wallet_topup' => WalletTopup::create(['reference' => 'TOP-'.$v->id, 'vendor_id' => $v->id, 'amount' => 20, 'status' => 'initiated', 'payment_gateway' => 'paystack'])->id,
            'withdrawal' => VendorWithdrawal::create([
                'vendor_id' => $v->id, 'amount' => 30, 'status' => VendorWithdrawal::STATUS_PROCESSING, 'reference' => 'WD-'.$v->id,
                'momo_number' => '0244123456', 'momo_network' => VendorWithdrawal::NETWORK_MTN,
            ])->id,
            'afa_registration' => $this->afa($v, null)->id,
            'result_checker_order' => ResultCheckerOrder::factory()->create(['vendor_id' => $v->id])->id,
        ];
    }

    private function afa(Vendor $provider, ?Vendor $reseller): AfaRegistration
    {
        return AfaRegistration::create([
            'vendor_id' => $provider->id, 'reseller_vendor_id' => $reseller?->id, 'full_name' => 'Test Customer',
            'id_type' => AfaRegistration::ID_GHANA_CARD, 'id_number' => 'GHA-123456789-1', 'date_of_birth' => '1990-01-01',
            'phone_number' => '0240000000', 'location' => 'Accra', 'region' => 'Greater Accra', 'amount' => 100,
            'vendor_price' => 80, 'platform_commission' => 2, 'vendor_earning' => 78, 'reseller_earning' => 20,
            'is_reseller_order' => $reseller !== null, 'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'status' => AfaRegistration::STATUS_PENDING, 'reference' => 'AFA-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10)),
        ]);
    }

    public function test_every_supported_related_type_links_for_the_owner_and_stores_a_server_made_label(): void
    {
        $vendor = $this->vendor();

        foreach ($this->records($vendor) as $type => $id) {
            $this->create($vendor, $type, $id)->assertCreated();
        }

        $this->assertSame(6, SupportConversation::count());
        foreach (SupportConversation::all() as $conversation) {
            $this->assertNotEmpty($conversation->related_label);
            $this->assertSame($vendor->id, (int) $conversation->vendor_id);
        }
        $this->assertSame('Order #'.Order::first()->id.' · MTN 1GB', SupportConversation::where('related_type', 'order')->first()->related_label);
    }

    public function test_a_crafted_foreign_record_id_is_rejected_for_every_type(): void
    {
        $owner = $this->vendor();
        $attacker = $this->vendor();

        foreach ($this->records($owner) as $type => $id) {
            $this->create($attacker, $type, $id)->assertStatus(422)->assertJsonValidationErrors('related_id');
        }

        $this->assertSame(0, SupportConversation::count());
    }

    public function test_unknown_type_or_nonexistent_id_is_rejected(): void
    {
        $vendor = $this->vendor();
        $this->create($vendor, 'users', 1)->assertStatus(422);
        $this->create($vendor, 'order', 424242)->assertStatus(422);
        $this->create($vendor, 'order', 'abc')->assertStatus(422);
        $this->assertSame(0, SupportConversation::count());
    }

    public function test_product_owner_may_reference_a_reseller_order_but_other_vendors_may_not(): void
    {
        $owner = $this->vendor();
        $reseller = $this->vendor();
        $stranger = $this->vendor();
        $order = $this->order($reseller, ['owner_vendor_id' => $owner->id, 'is_reseller_order' => true]);

        $this->create($owner, 'order', $order->id)->assertCreated();
        $this->create($reseller, 'order', $order->id)->assertCreated();
        $this->create($stranger, 'order', $order->id)->assertStatus(422);
    }

    public function test_afa_provider_and_reseller_may_reference_but_others_may_not(): void
    {
        [$provider, $reseller, $stranger] = [$this->vendor(), $this->vendor(), $this->vendor()];
        $afa = $this->afa($provider, $reseller);

        $this->create($provider, 'afa_registration', $afa->id)->assertCreated();
        $this->create($reseller, 'afa_registration', $afa->id)->assertCreated();
        $this->create($stranger, 'afa_registration', $afa->id)->assertStatus(422);
    }

    public function test_related_options_only_list_the_authenticated_vendors_records(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $myOrder = $this->order($mine);
        $theirOrder = $this->order($other);

        $options = collect($this->actingAs($mine, 'vendor')
            ->get(route('vendor.support.related', ['type' => 'order']), self::JSON)->assertOk()->json('options'))->pluck('id');

        $this->assertTrue($options->contains($myOrder->id));
        $this->assertFalse($options->contains($theirOrder->id));
        $this->get(route('vendor.support.related', ['type' => 'bogus']), self::JSON)->assertNotFound();
    }

    public function test_new_request_page_prefills_only_verified_records(): void
    {
        $mine = $this->vendor();
        $other = $this->vendor();
        $myOrder = $this->order($mine);
        $theirOrder = $this->order($other);
        $this->actingAs($mine, 'vendor');

        $this->get(route('vendor.support.new', ['related_type' => 'order', 'related_id' => $myOrder->id]))
            ->assertOk()->assertSee('Order #'.$myOrder->id, false);

        $this->get(route('vendor.support.new', ['related_type' => 'order', 'related_id' => $theirOrder->id]))
            ->assertOk()->assertSee('could not find that record', false);
    }

    public function test_admin_sees_the_related_record_and_a_link_for_orders(): void
    {
        $vendor = $this->vendor();
        $order = $this->order($vendor);
        $c = $this->startConversation($vendor, 'order help', ['related_type' => 'order', 'related_id' => $order->id]);

        $this->actingAs($this->adminModel(), 'admin')
            ->get(route('admin.support.show', $c->id))
            ->assertOk()
            ->assertSee(route('admin.orders.show', $order->id), false)
            ->assertSee('Order #'.$order->id, false);
    }
}
