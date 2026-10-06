<?php

namespace Tests\Feature\Support;

use App\Models\AdminNotification;
use App\Models\SupportConversation;
use App\Models\SupportConversationEvent;
use App\Models\SupportMessage;
use App\Models\VendorNotification;
use App\Support\Support\SupportPrincipal;
use Illuminate\Validation\ValidationException;

class SupportMessagesTest extends SupportTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    public function test_vendor_can_start_a_conversation_over_http(): void
    {
        $vendor = $this->vendor();

        $response = $this->actingAs($vendor, 'vendor')->post(route('vendor.support.store'), [
            'category_id' => $this->category()->id,
            'message' => 'My order is still processing',
        ], self::JSON)->assertCreated();

        $conversation = SupportConversation::firstOrFail();
        $response->assertJson(['redirect' => route('vendor.support.show', $conversation->id)]);
        $this->assertSame($vendor->id, (int) $conversation->vendor_id);
        $this->assertSame('waiting_admin', $conversation->status);
        $this->assertNotNull($conversation->waiting_since);
        $this->assertSame('My order is still processing', SupportMessage::first()->body);
        $this->assertTrue(SupportConversationEvent::where('event', 'created')->exists());

        $this->get(route('vendor.support.show', $conversation->id))->assertOk()->assertSee('My order is still processing');
    }

    public function test_admin_can_reply_over_http_and_status_becomes_waiting_for_vendor(): void
    {
        $vendor = $this->vendor();
        $conversation = $this->startConversation($vendor);
        $admin = $this->adminModel();

        $this->actingAs($admin, 'admin')->post(route('admin.support.send', $conversation->id), [
            'message' => 'We are on it',
        ], self::JSON)->assertCreated()->assertJsonPath('status', 'waiting_vendor');

        $conversation->refresh();
        $this->assertSame('waiting_vendor', $conversation->status);
        $this->assertNull($conversation->waiting_since);

        $message = SupportMessage::latest('id')->first();
        $this->assertSame('admin', $message->sender_type);
        $this->assertSame('admin', $message->sender_guard);
        $this->assertSame($admin->id, (int) $message->sender_id);
    }

    public function test_web_user_admin_is_recorded_with_the_web_guard(): void
    {
        $conversation = $this->startConversation($this->vendor());
        $user = $this->adminUser();

        $this->actingAs($user)->post(route('admin.support.send', $conversation->id), ['message' => 'hi'], self::JSON)->assertCreated();

        $message = SupportMessage::latest('id')->first();
        $this->assertSame('web', $message->sender_guard);
        $this->assertSame($user->id, (int) $message->sender_id);
    }

    public function test_empty_and_oversized_messages_are_rejected(): void
    {
        $vendor = $this->vendor();
        $conversation = $this->startConversation($vendor);
        $this->actingAs($vendor, 'vendor');

        $this->post(route('vendor.support.send', $conversation->id), ['message' => '   '], self::JSON)->assertStatus(422);
        $this->post(route('vendor.support.send', $conversation->id), [], self::JSON)->assertStatus(422);
        $this->post(route('vendor.support.send', $conversation->id), ['message' => str_repeat('a', 4001)], self::JSON)->assertStatus(422);

        $this->assertSame(1, SupportMessage::count());
    }

    public function test_new_request_requires_a_valid_category(): void
    {
        $this->actingAs($this->vendor(), 'vendor')
            ->post(route('vendor.support.store'), ['category_id' => 99999, 'message' => 'hi'], self::JSON)
            ->assertStatus(422);

        $this->assertSame(0, SupportConversation::count());
    }

    public function test_inactive_category_cannot_be_used(): void
    {
        $category = $this->category();
        $category->update(['is_active' => false]);

        $this->actingAs($this->vendor(), 'vendor')
            ->post(route('vendor.support.store'), ['category_id' => $category->id, 'message' => 'hi'], self::JSON)
            ->assertStatus(422);
    }

    public function test_status_transitions_follow_the_conversation(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();

        $c = $this->startConversation($vendor);
        $this->assertSame('waiting_admin', $c->status);

        $this->reply($admin, $c);
        $this->assertSame('waiting_vendor', $c->fresh()->status);

        $this->vendorSays($vendor, $c);
        $this->assertSame('waiting_admin', $c->fresh()->status);

        $this->service()->resolve(SupportPrincipal::forAdmin($admin), $c);
        $c->refresh();
        $this->assertSame('resolved', $c->status);
        $this->assertSame('admin', $c->resolved_by_guard);
        $this->assertSame($admin->id, (int) $c->resolved_by_id);
        $this->assertNotNull($c->resolved_at);
        $this->assertNull($c->waiting_since);

        $this->service()->close(SupportPrincipal::forAdmin($admin), $c);
        $this->assertSame('closed', $c->fresh()->status);
    }

    public function test_vendor_reply_to_a_recently_resolved_conversation_reopens_it(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $c = $this->startConversation($vendor);
        $this->service()->resolve(SupportPrincipal::forAdmin($admin), $c);

        $this->travel(6)->days();
        $this->vendorSays($vendor, $c, 'It happened again');

        $c->refresh();
        $this->assertSame('waiting_admin', $c->status);
        $this->assertNotNull($c->waiting_since);
        $this->assertNull($c->resolved_at);
        $this->assertTrue(SupportConversationEvent::where('conversation_id', $c->id)->where('event', 'reopened')->exists());
    }

    public function test_vendor_cannot_reply_to_a_conversation_resolved_longer_ago_than_the_window(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->service()->resolve(SupportPrincipal::forAdmin($this->adminModel()), $c);

        $this->travel(8)->days();

        $this->actingAs($vendor, 'vendor')
            ->post(route('vendor.support.send', $c->id), ['message' => 'old thread'], self::JSON)
            ->assertStatus(422);

        $this->assertSame('resolved', $c->fresh()->status);
        $this->assertSame(1, SupportMessage::count());
    }

    public function test_reopen_window_is_configurable(): void
    {
        config(['support.reopen_days' => 1]);
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->service()->resolve(SupportPrincipal::forAdmin($this->adminModel()), $c);

        $this->travel(2)->days();

        $this->expectException(ValidationException::class);
        $this->vendorSays($vendor, $c);
    }

    public function test_closed_conversations_stay_closed_for_vendor_replies(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->service()->close(SupportPrincipal::forAdmin($this->adminModel()), $c);

        $this->actingAs($vendor, 'vendor')
            ->post(route('vendor.support.send', $c->id), ['message' => 'hello?'], self::JSON)
            ->assertStatus(422);

        $this->assertSame('closed', $c->fresh()->status);

        // ...but the vendor can always open a brand-new request.
        $this->post(route('vendor.support.store'), ['category_id' => $this->category()->id, 'message' => 'new issue'], self::JSON)->assertCreated();
        $this->assertSame(2, SupportConversation::count());
    }

    public function test_admin_reply_to_resolved_reopens_and_admin_can_reopen_closed(): void
    {
        $admin = $this->adminModel();
        $principal = SupportPrincipal::forAdmin($admin);
        $c = $this->startConversation($this->vendor());

        $this->service()->resolve($principal, $c);
        $this->reply($admin, $c, 'one more thing');
        $this->assertSame('waiting_vendor', $c->fresh()->status);

        $this->service()->close($principal, $c);
        $this->service()->reopen($principal, $c);
        $c->refresh();
        $this->assertSame('open', $c->status);
        $this->assertNull($c->closed_at);
    }

    public function test_retried_request_with_same_client_token_does_not_duplicate(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->actingAs($vendor, 'vendor');

        $payload = ['message' => 'double click', 'client_token' => 'tok-12345678', 'images' => [$this->png()]];
        $first = $this->post(route('vendor.support.send', $c->id), $payload, self::JSON)->assertCreated();
        $second = $this->post(route('vendor.support.send', $c->id), ['images' => [$this->png()]] + $payload, self::JSON)->assertOk();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame(2, SupportMessage::count());   // first message + one retried message
        $this->assertSame(1, \App\Models\SupportAttachment::count());
        $this->assertCount(1, \Storage::disk(config('support.disk'))->allFiles());
    }

    public function test_double_submit_of_new_request_creates_one_conversation(): void
    {
        $vendor = $this->vendor();
        $this->actingAs($vendor, 'vendor');
        $payload = ['category_id' => $this->category()->id, 'message' => 'help', 'client_token' => 'create-token-1'];

        $a = $this->post(route('vendor.support.store'), $payload, self::JSON)->assertCreated();
        $b = $this->post(route('vendor.support.store'), $payload, self::JSON)->assertCreated();

        $this->assertSame($a->json('redirect'), $b->json('redirect'));
        $this->assertSame(1, SupportConversation::count());
        $this->assertSame(1, SupportMessage::count());
    }

    public function test_notifications_fire_only_on_status_transitions(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();

        $c = $this->startConversation($vendor);
        $this->assertSame(1, AdminNotification::where('type', 'support_message')->count());

        // Follow-ups while already waiting for an admin do not spam.
        $this->vendorSays($vendor, $c, 'hello?');
        $this->vendorSays($vendor, $c, 'anyone?');
        $this->assertSame(1, AdminNotification::where('type', 'support_message')->count());

        $this->reply($admin, $c);
        $this->reply($admin, $c, 'second');
        $this->assertSame(1, VendorNotification::where('type', 'support_reply')->where('vendor_id', $vendor->id)->count());

        $this->vendorSays($vendor, $c, 'thanks');
        $this->assertSame(2, AdminNotification::where('type', 'support_message')->count());
    }

    public function test_existing_notification_endpoints_still_work_with_support_notifications(): void
    {
        $vendor = $this->vendor();
        $this->startConversation($vendor);
        $this->reply($this->adminModel(), SupportConversation::first());

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.notifications.unread-count'))
            ->assertOk();
    }

    public function test_history_is_cursor_paginated(): void
    {
        config(['support.messages_per_page' => 5]);
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor, 'm1');
        foreach (range(2, 12) as $i) {
            $this->vendorSays($vendor, $c, "m{$i}");
        }
        $this->actingAs($vendor, 'vendor');

        $latest = $this->get(route('vendor.support.messages', $c->id), self::JSON)->assertOk();
        $this->assertSame(['m8', 'm9', 'm10', 'm11', 'm12'], array_column($latest->json('messages'), 'body'));
        $this->assertTrue($latest->json('has_more_before'));

        $firstId = $latest->json('messages.0.id');
        $older = $this->get(route('vendor.support.messages', [$c->id, 'before' => $firstId]), self::JSON)->assertOk();
        $this->assertSame(['m3', 'm4', 'm5', 'm6', 'm7'], array_column($older->json('messages'), 'body'));

        $newer = $this->get(route('vendor.support.messages', [$c->id, 'after' => $latest->json('last_id') - 1]), self::JSON);
        $this->assertSame(['m12'], array_column($newer->json('messages'), 'body'));
    }

    public function test_vendors_do_not_see_which_admin_replied_but_admins_do(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $c = $this->startConversation($vendor);
        $this->reply($admin, $c, 'hello');

        $vendorView = $this->actingAs($vendor, 'vendor')->get(route('vendor.support.messages', $c->id), self::JSON);
        $this->assertSame('XTRA4U Support', $vendorView->json('messages.1.sender'));

        $adminView = $this->actingAs($admin, 'admin')->get(route('admin.support.messages', $c->id), self::JSON);
        $this->assertSame($admin->name, $adminView->json('messages.1.sender'));
        $this->assertSame($vendor->name, $adminView->json('messages.0.sender'));
    }
}
