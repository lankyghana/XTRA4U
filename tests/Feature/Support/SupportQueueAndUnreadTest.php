<?php

namespace Tests\Feature\Support;

use App\Models\Admin;
use App\Models\SupportConversation;
use App\Models\SupportRead;
use App\Models\User;
use App\Services\Support\SupportInbox;
use App\Support\Support\SupportPrincipal;
use Carbon\Carbon;

class SupportQueueAndUnreadTest extends SupportTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private function queueOrder(SupportPrincipal $admin, array $filters = []): array
    {
        return app(SupportInbox::class)->forAdmin($admin, $filters)->getCollection()
            ->map(fn ($c) => $c->vendor->name)->all();
    }

    // ---- FIFO queue -------------------------------------------------

    public function test_queue_is_oldest_currently_waiting_first_per_the_spec_scenario(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $admin = $this->adminModel();
        [$a, $b, $c] = [$this->vendor(['name' => 'Vendor A']), $this->vendor(['name' => 'Vendor B']), $this->vendor(['name' => 'Vendor C'])];

        $convA = $this->startConversation($a);                 // 10:00 A messages
        Carbon::setTestNow('2026-10-05 10:05:00');
        $this->startConversation($b);                          // 10:05 B messages
        Carbon::setTestNow('2026-10-05 10:10:00');
        $this->reply($admin, $convA);                          // 10:10 admin replies to A
        Carbon::setTestNow('2026-10-05 10:12:00');
        $this->startConversation($c);                          // 10:12 C messages
        Carbon::setTestNow('2026-10-05 10:15:00');
        $this->vendorSays($a, $convA);                         // 10:15 A replies again

        $this->assertSame(['Vendor B', 'Vendor C', 'Vendor A'], $this->queueOrder(SupportPrincipal::forAdmin($admin)));

        Carbon::setTestNow();
    }

    public function test_waiting_since_is_set_on_entering_the_queue_and_not_moved_by_followups(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->assertEquals(Carbon::parse('2026-10-05 09:00:00'), $c->waiting_since);

        Carbon::setTestNow('2026-10-05 09:30:00');
        $this->vendorSays($vendor, $c, 'still waiting');
        $this->assertEquals(Carbon::parse('2026-10-05 09:00:00'), $c->fresh()->waiting_since, 'follow-up must not push the vendor to the back');

        Carbon::setTestNow('2026-10-05 09:40:00');
        $this->reply($this->adminModel(), $c);
        $this->assertNull($c->fresh()->waiting_since);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->vendorSays($vendor, $c);
        $this->assertEquals(Carbon::parse('2026-10-05 10:00:00'), $c->fresh()->waiting_since);

        Carbon::setTestNow();
    }

    public function test_resolved_vendor_followup_reenters_the_queue_at_the_back(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $admin = $this->adminModel();
        $a = $this->vendor(['name' => 'Vendor A']);
        $b = $this->vendor(['name' => 'Vendor B']);
        $convA = $this->startConversation($a);
        Carbon::setTestNow('2026-10-05 10:01:00');
        $this->startConversation($b);
        Carbon::setTestNow('2026-10-05 10:02:00');
        $this->service()->resolve(SupportPrincipal::forAdmin($admin), $convA);
        Carbon::setTestNow('2026-10-05 10:03:00');
        $this->vendorSays($a, $convA, 'not actually fixed');

        $this->assertSame(['Vendor B', 'Vendor A'], $this->queueOrder(SupportPrincipal::forAdmin($admin), ['view' => 'waiting_admin']));

        Carbon::setTestNow();
    }

    public function test_admin_can_change_sorting_and_filter(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $admin = SupportPrincipal::forAdmin($this->adminModel());
        $a = $this->vendor(['name' => 'Vendor A']);
        $b = $this->vendor(['name' => 'Vendor B']);
        $convA = $this->startConversation($a, 'a');
        Carbon::setTestNow('2026-10-05 10:05:00');
        $this->startConversation($b, 'b', ['images' => [$this->png()]]);
        Carbon::setTestNow('2026-10-05 10:10:00');
        $this->vendorSays($a, $convA, 'newest');

        $this->assertSame(['Vendor A', 'Vendor B'], $this->queueOrder($admin, ['sort' => 'newest']));
        $this->assertSame(['Vendor B', 'Vendor A'], $this->queueOrder($admin, ['sort' => 'oldest']));
        $this->assertSame(['Vendor B'], $this->queueOrder($admin, ['has_image' => '1']));
        $this->assertSame([], $this->queueOrder($admin, ['has_voice' => '1']));
        $this->assertSame(['Vendor A'], $this->queueOrder($admin, ['vendor' => $a->id]));
        $this->assertSame(['Vendor B'], $this->queueOrder($admin, ['category' => $this->category()->id, 'q' => 'Vendor B']));
        $this->assertSame(['Vendor A', 'Vendor B'], $this->queueOrder($admin, ['from' => '2026-10-05', 'to' => '2026-10-05', 'sort' => 'newest']));
        $this->assertSame([], $this->queueOrder($admin, ['from' => '2026-10-06']));

        Carbon::setTestNow();
    }

    public function test_search_matches_vendor_phone_message_text_and_related_order(): void
    {
        $admin = SupportPrincipal::forAdmin($this->adminModel());
        $a = $this->vendor(['name' => 'Alpha Store', 'phone_number' => '0241112222']);
        $b = $this->vendor(['name' => 'Beta Store', 'phone_number' => '0553334444']);
        $order = \App\Models\Order::create([
            'recipient_phone_number' => '0240000000', 'mobile_money_number' => '0240000000', 'service_purchased' => 'MTN 1GB',
            'amount_paid' => 5, 'vendor_id' => $b->id, 'status' => 'Processing', 'payment_status' => 'paid',
        ]);
        $this->startConversation($a, 'where is my refund');
        $this->startConversation($b, 'order problem', ['related_type' => 'order', 'related_id' => $order->id]);

        $this->assertSame(['Alpha Store'], $this->queueOrder($admin, ['q' => '0241112222']));
        $this->assertSame(['Alpha Store'], $this->queueOrder($admin, ['q' => 'refund']));
        $this->assertSame(['Beta Store'], $this->queueOrder($admin, ['q' => '#'.$order->id]));
        $this->assertSame(['Beta Store'], $this->queueOrder($admin, ['q' => 'Beta']));
    }

    public function test_concurrent_state_changes_end_in_a_consistent_state(): void
    {
        // Sequential stand-in for racing requests (SQLite has no row locks): a vendor
        // message right after an admin resolve must end up in a single coherent state.
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $c = $this->startConversation($vendor);
        $stale = SupportConversation::find($c->id);   // admin loaded it before the vendor wrote

        $this->vendorSays($vendor, $c, 'second');
        $this->service()->resolve(SupportPrincipal::forAdmin($admin), $stale);   // acts on locked fresh row

        $fresh = $c->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertNull($fresh->waiting_since);
        $this->assertNotNull($fresh->resolved_at);
    }

    // ---- Unread -----------------------------------------------------

    public function test_vendor_and_admin_unread_counts(): void
    {
        $vendor = $this->vendor();
        $admin = SupportPrincipal::forAdmin($this->adminModel());
        $vendorP = SupportPrincipal::forVendor($vendor);
        $inbox = app(SupportInbox::class);

        $c = $this->startConversation($vendor);
        $this->assertSame(1, $inbox->unreadConversations($admin));
        $this->assertSame(0, $inbox->unreadConversations($vendorP), 'own message is not unread for the sender');

        $this->reply($this->adminModel(), $c, 'r1');
        $this->reply($this->adminModel(), $c, 'r2');
        $this->assertSame(1, $inbox->unreadConversations($vendorP));
        $this->assertSame(2, $inbox->forVendor($vendorP)->first()->unread_count);
    }

    public function test_opening_a_conversation_marks_it_read_for_the_vendor_only(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $c = $this->startConversation($vendor);
        $this->reply($admin, $c);

        $this->actingAs($vendor, 'vendor')->get(route('vendor.support.show', $c->id))->assertOk();

        $this->assertSame(0, app(SupportInbox::class)->unreadConversations(SupportPrincipal::forVendor($vendor)));
        // The admin's own state is untouched: they replied (read up to their message) but a
        // vendor message sent after that is still unread for them.
        $this->vendorSays($vendor, $c, 'thanks!');
        $this->assertSame(1, app(SupportInbox::class)->unreadConversations(SupportPrincipal::forAdmin($admin)));
    }

    public function test_reading_by_one_admin_does_not_clear_unread_for_another_admin(): void
    {
        $adminA = $this->adminModel();
        $adminB = $this->adminModel();
        $c = $this->startConversation($this->vendor(), 'urgent');
        $inbox = app(SupportInbox::class);
        $a = SupportPrincipal::forAdmin($adminA);
        $b = SupportPrincipal::forAdmin($adminB);

        $this->assertSame(1, $inbox->unreadConversations($a));
        $this->assertSame(1, $inbox->unreadConversations($b));

        $this->actingAs($adminA, 'admin')->get(route('admin.support.show', $c->id))->assertOk();

        $this->assertSame(0, $inbox->unreadConversations($a));
        $this->assertSame(1, $inbox->unreadConversations($b), 'Admin B must still see it unread');
        $this->assertSame(0, $inbox->forAdmin($a)->first()->unread_count);
        $this->assertSame(1, $inbox->forAdmin($b)->first()->unread_count);

        // The queue/status is global: still waiting for an admin.
        $this->assertSame('waiting_admin', $c->fresh()->status);

        // Admin A replies: status becomes waiting_vendor for everyone.
        $this->actingAs($adminA, 'admin')->post(route('admin.support.send', $c->id), ['message' => 'on it'], self::JSON)->assertCreated();
        $this->assertSame('waiting_vendor', $c->fresh()->status);

        // Admin B reading afterwards clears only B.
        $this->actingAs($adminB, 'admin')->get(route('admin.support.show', $c->id))->assertOk();
        $this->assertSame(0, $inbox->unreadConversations($b));
    }

    public function test_admin_guard_and_web_user_admin_with_the_same_numeric_id_do_not_share_read_state(): void
    {
        // Force a numeric collision explicitly (auto-increment values differ per database).
        $adminModel = Admin::factory()->create(['id' => 424242]);
        $adminUser = User::factory()->create(['id' => 424242, 'role' => 'admin']);
        $this->assertSame($adminModel->id, $adminUser->id);

        $c = $this->startConversation($this->vendor());
        $inbox = app(SupportInbox::class);
        $viaGuard = SupportPrincipal::forAdmin($adminModel);
        $viaUser = SupportPrincipal::forAdmin($adminUser);

        $this->actingAs($adminModel, 'admin')->get(route('admin.support.show', $c->id))->assertOk();

        $this->assertSame(0, $inbox->unreadConversations($viaGuard));
        $this->assertSame(1, $inbox->unreadConversations($viaUser));

        $this->assertSame(['admin', 'web'], collect([$viaGuard->guard, $viaUser->guard])->sort()->values()->all());
        $this->assertSame(1, SupportRead::where('reader_guard', 'admin')->count());
        $this->assertSame(0, SupportRead::where('reader_guard', 'web')->count());
    }

    public function test_polling_marks_only_the_polling_reader_as_read(): void
    {
        $vendor = $this->vendor();
        $adminA = $this->adminModel();
        $adminB = $this->adminModel();
        $c = $this->startConversation($vendor);
        $firstId = $c->messages()->first()->id;
        $this->vendorSays($vendor, $c, 'second');
        $inbox = app(SupportInbox::class);

        $this->actingAs($adminA, 'admin')
            ->get(route('admin.support.messages', [$c->id, 'after' => $firstId, 'mark_read' => 1]), self::JSON)->assertOk();

        $this->assertSame(0, $inbox->unreadConversations(SupportPrincipal::forAdmin($adminA)));
        $this->assertSame(1, $inbox->unreadConversations(SupportPrincipal::forAdmin($adminB)));
        $this->assertSame(2, $inbox->forAdmin(SupportPrincipal::forAdmin($adminB))->first()->unread_count);
    }

    public function test_read_position_never_moves_backwards(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $p = SupportPrincipal::forAdmin($admin);
        $c = $this->startConversation($vendor);
        $m2 = $this->vendorSays($vendor, $c, 'two');

        $this->service()->markRead($p, $c->fresh());
        $this->service()->markRead($p, $c->fresh(), 1);

        $this->assertSame($m2->id, (int) SupportRead::first()->last_read_message_id);
    }

    public function test_unread_count_endpoints_are_per_principal(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $c = $this->startConversation($vendor);
        $this->reply($admin, $c);

        $this->actingAs($vendor, 'vendor')->get(route('vendor.support.unread'))->assertOk()->assertJson(['unread' => 1]);
        $this->actingAs($admin, 'admin')->get(route('admin.support.unread'))->assertOk()->assertJson(['unread' => 0, 'waiting' => 0]);
    }

    public function test_nav_badge_renders_the_per_user_unread_count(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->reply($this->adminModel(), $c);

        $this->actingAs($vendor, 'vendor')->get(route('vendor.support.index'))->assertOk()->assertSee('1 unread', false);

        $adminB = $this->adminModel();
        $this->actingAs($adminB, 'admin')->get(route('admin.support.index'))->assertOk();
    }
}
