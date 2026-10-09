<?php

namespace Tests\Feature\Support;

use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Support\Support\SupportPrincipal;
use Illuminate\Auth\Access\AuthorizationException;

class SupportAuthorizationTest extends SupportTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    public function test_anonymous_users_cannot_reach_vendor_or_admin_support(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $this->get(route('vendor.support.index'))->assertRedirect(route('vendor.login.form'));
        $this->get(route('vendor.support.show', $c->id))->assertRedirect(route('vendor.login.form'));
        $this->post(route('vendor.support.send', $c->id), ['message' => 'x'], self::JSON)->assertRedirect(route('vendor.login.form'));
        $this->get(route('admin.support.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.support.show', $c->id))->assertRedirect(route('admin.login'));
        $this->get(route('admin.support.quick-replies.index'))->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_web_user_cannot_open_admin_inbox(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->get(route('admin.support.index'))->assertForbidden();
    }

    public function test_a_vendor_session_cannot_reach_the_admin_inbox(): void
    {
        $this->actingAs($this->vendor(), 'vendor');

        // In tests actingAs() makes the vendor guard the default guard, so AdminOnly
        // lets the Vendor model through; SupportPrincipal::admin() is the second line
        // of defence and must still refuse. (Live: default guard is web => redirect.)
        $this->assertContains($this->get(route('admin.support.index'))->getStatusCode(), [302, 403]);
    }

    public function test_vendor_cannot_view_or_post_to_another_vendors_conversation(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();
        $conversation = $this->startConversation($b, 'private to B');

        $this->actingAs($a, 'vendor');
        $this->get(route('vendor.support.show', $conversation->id))->assertNotFound();
        $this->get(route('vendor.support.messages', $conversation->id), self::JSON)->assertNotFound();
        $this->post(route('vendor.support.send', $conversation->id), ['message' => 'sneaky'], self::JSON)->assertNotFound();
        $this->post(route('vendor.support.read', $conversation->id), [], self::JSON)->assertNotFound();

        $this->assertSame(1, SupportMessage::where('conversation_id', $conversation->id)->count());
    }

    public function test_vendor_cannot_fetch_another_vendors_attachment(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();
        $this->startConversation($b, 'see photo', ['images' => [$this->png()]]);
        $attachment = SupportAttachment::firstOrFail();

        $this->actingAs($a, 'vendor')
            ->get(route('vendor.support.attachments.show', $attachment->id))
            ->assertNotFound();

        // A nonexistent id is indistinguishable from a foreign one.
        $this->get(route('vendor.support.attachments.show', 987654))->assertNotFound();

        // The owner can.
        $this->actingAs($b, 'vendor')
            ->get(route('vendor.support.attachments.show', $attachment->id))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_anonymous_cannot_fetch_an_attachment(): void
    {
        $this->startConversation($this->vendor(), 'photo', ['images' => [$this->png()]]);
        $attachment = SupportAttachment::firstOrFail();

        $this->get(route('vendor.support.attachments.show', $attachment->id))->assertRedirect(route('vendor.login.form'));
        $this->get(route('admin.support.attachments.show', $attachment->id))->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_fetch_any_vendors_attachment(): void
    {
        $this->startConversation($this->vendor(), 'photo', ['images' => [$this->png()]]);
        $attachment = SupportAttachment::firstOrFail();

        $this->actingAs($this->adminModel(), 'admin')
            ->get(route('admin.support.attachments.show', $attachment->id))
            ->assertOk();
    }

    public function test_vendor_session_cannot_use_admin_endpoints(): void
    {
        $vendor = $this->vendor();
        $conversation = $this->startConversation($vendor, 'x', ['images' => [$this->png()]]);
        $attachment = SupportAttachment::firstOrFail();

        $this->actingAs($vendor, 'vendor');
        $this->assertContains($this->get(route('admin.support.attachments.show', $attachment->id))->getStatusCode(), [302, 403]);
        $this->assertContains($this->post(route('admin.support.resolve', $conversation->id))->getStatusCode(), [302, 403]);
        $this->assertSame(SupportConversation::STATUS_WAITING_ADMIN, $conversation->fresh()->status);
    }

    public function test_vendor_cannot_impersonate_admin_or_spoof_identity_via_request_fields(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();
        $conversation = $this->startConversation($a);

        $this->actingAs($a, 'vendor')->post(route('vendor.support.send', $conversation->id), [
            'message' => 'I am totally an admin',
            'sender_type' => 'admin',
            'sender_id' => 999,
            'sender_guard' => 'admin',
            'vendor_id' => $b->id,
            'status' => 'resolved',
            'resolved_by_id' => 1,
            'storage_path' => '../../.env',
        ], self::JSON)->assertCreated();

        $message = SupportMessage::latest('id')->first();
        $this->assertSame('vendor', $message->sender_type);
        $this->assertSame('vendor', $message->sender_guard);
        $this->assertSame($a->id, (int) $message->sender_id);

        $conversation->refresh();
        $this->assertSame($a->id, (int) $conversation->vendor_id);
        $this->assertSame(SupportConversation::STATUS_WAITING_ADMIN, $conversation->status);
        $this->assertNull($conversation->resolved_by_id);
    }

    public function test_vendor_principal_cannot_resolve_close_or_reopen(): void
    {
        $vendor = $this->vendor();
        $conversation = $this->startConversation($vendor);
        $principal = SupportPrincipal::forVendor($vendor);

        foreach (['resolve', 'close', 'reopen'] as $action) {
            try {
                $this->service()->{$action}($principal, $conversation);
                $this->fail("vendor could {$action}");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(SupportConversation::STATUS_WAITING_ADMIN, $conversation->fresh()->status);
    }

    public function test_service_refuses_a_vendor_principal_for_someone_elses_conversation(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();
        $conversation = $this->startConversation($b);

        $this->expectException(AuthorizationException::class);
        $this->service()->sendMessage(SupportPrincipal::forVendor($a), $conversation, ['body' => 'hi']);
    }

    public function test_vendor_principal_cannot_start_a_conversation_as_another_vendor(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();

        $conversation = $this->service()->startConversation(SupportPrincipal::forVendor($a), [
            'category_id' => $this->category()->id,
            'body' => 'hello',
            'vendor_id' => $b->id,
        ]);

        $this->assertSame($a->id, (int) $conversation->vendor_id);
    }

    public function test_principal_constructors_enforce_identity(): void
    {
        $this->actingAs($this->vendor(), 'vendor');
        $this->assertNotNull(SupportPrincipal::tryVendor());
        $this->assertNull(SupportPrincipal::tryAdmin());

        $this->expectException(\InvalidArgumentException::class);
        SupportPrincipal::forAdmin(User::factory()->create(['role' => 'user']));
    }

    public function test_both_admin_representations_can_use_the_inbox(): void
    {
        $conversation = $this->startConversation($this->vendor(), 'hello team');

        $this->actingAs($this->adminModel(), 'admin');
        $this->get(route('admin.support.index'))->assertOk()->assertSee('hello team');

        // Name the guard: the actingAs above made `admin` the default guard, and a User on the
        // admin guard is (correctly) refused by the fail-closed admin check.
        $this->actingAs($this->adminUser(), 'web');
        $this->get(route('admin.support.show', $conversation->id))->assertOk();
    }
}
