<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Models\User;
use App\Models\Vendor;
use App\Services\Ai\Tools\Exceptions\AiToolContextException;
use App\Services\Ai\Tools\ToolContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Who may use the Vendor Assistant: exactly the signed-in, currently approved vendor, through the EXISTING vendor guard
 * and `vendor.approved` middleware. No new guard, login or identity table exists.
 */
class AccessControlTest extends VendorAssistantTestCase
{
    public function test_a_guest_is_redirected_to_the_existing_vendor_login(): void
    {
        $this->get(route('vendor.assistant.show'))->assertRedirect(route('vendor.login.form'));
        $this->postJson(route('vendor.assistant.message'), ['message' => 'hello'])->assertRedirect(route('vendor.login.form'));

        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_an_unapproved_vendor_is_denied_and_signed_out(): void
    {
        $pending = $this->unapprovedVendor();

        $this->signedIn($pending)->get(route('vendor.assistant.show'))->assertRedirect(route('vendor.login.form'));
        $this->assertGuest('vendor');

        $this->signedIn($pending)->postJson(route('vendor.assistant.message'), ['message' => 'hello'])->assertRedirect(route('vendor.login.form'));
        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_an_approved_vendor_can_open_the_page_and_ask_a_question_when_explicitly_enabled(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->respondWith('Hello vendor.');

        $this->signedIn($vendor)->get(route('vendor.assistant.show'))
            ->assertOk()
            ->assertSee('data-assistant-state="available"', false);

        $this->ask($vendor, 'How are things?')
            ->assertOk()
            ->assertJson(['ok' => true, 'answer' => 'Hello vendor.'])
            ->assertHeader('Cache-Control');
    }

    public function test_access_is_denied_immediately_after_suspension_even_with_a_live_session(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->respondWith('first');

        $this->ask($vendor, 'before suspension')->assertOk();
        $callsBefore = $this->mockProvider()->callCount();

        // An admin withdraws approval while the vendor's session is still alive (the stale model still says approved).
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);
        $this->app['auth']->forgetGuards();

        $this->signedIn(Vendor::find($vendor->id))->postJson(route('vendor.assistant.message'), ['message' => 'after suspension'])
            ->assertRedirect(route('vendor.login.form'));
        $this->signedIn(Vendor::find($vendor->id))->get(route('vendor.assistant.show'))->assertRedirect(route('vendor.login.form'));

        $this->assertSame($callsBefore, $this->mockProvider()->callCount(), 'no provider request after suspension');
    }

    public function test_a_tool_context_stops_working_the_moment_the_vendor_is_suspended(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forVendor($vendor);

        $this->assertTrue($this->runTool('vendor_get_my_store_info', [], $context)->isOk());

        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $result = $this->runTool('vendor_get_my_store_info', [], $context, 'call_2');
        $this->assertSame('invalid_context', $result->errorCode);
        $this->assertFalse($result->wasExecuted());
    }

    public function test_a_deleted_vendor_context_is_also_refused(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forVendor($vendor);

        DB::table('vendors')->where('id', $vendor->id)->delete();

        $this->assertSame('invalid_context', $this->runTool('vendor_get_dashboard_summary', [], $context)->errorCode);
    }

    public function test_other_identities_cannot_use_the_vendor_assistant(): void
    {
        // A web (customer/admin user) session is not a vendor.
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get(route('vendor.assistant.show'))->assertRedirect(route('vendor.login.form'));
        $this->actingAs($user, 'web')->postJson(route('vendor.assistant.message'), ['message' => 'hi'])->assertRedirect(route('vendor.login.form'));

        // A Developer API bearer key authenticates the public API only, never a dashboard session.
        $this->withToken('xt_test_'.str_repeat('a', 40))->postJson(route('vendor.assistant.message'), ['message' => 'hi'])
            ->assertRedirect(route('vendor.login.form'));

        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_the_developer_assistant_context_cannot_drive_the_vendor_assistant_tools(): void
    {
        $vendor = $this->approvedVendor();
        $developerContext = ToolContext::forDeveloper($vendor);

        $result = $this->runTool('vendor_get_dashboard_summary', [], $developerContext);

        $this->assertSame('unknown_tool', $result->errorCode, 'a developer-surface context does not see vendor tools');
        $this->assertFalse($result->wasExecuted());
    }

    public function test_no_new_guard_login_or_identity_table_was_added(): void
    {
        $this->assertSame(['web', 'vendor', 'admin'], array_keys(config('auth.guards')));
        $this->assertSame(['users', 'vendors', 'admins'], array_keys(config('auth.providers')));

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'assistant')) {
                $this->assertDoesNotMatchRegularExpression('/(login|register|password|token)/i', $route->uri());
            }
        }

        $tables = array_map(fn ($t) => is_array($t) ? $t['name'] : $t, \Illuminate\Support\Facades\Schema::getTables());
        foreach ($tables as $table) {
            $this->assertDoesNotMatchRegularExpression('/(assistant|^ai_|_ai_|ai_conversation|ai_message|chat_message)/i', $table, "unexpected table: {$table}");
        }
    }

    public function test_the_routes_sit_behind_the_existing_vendor_middleware_and_throttle(): void
    {
        foreach (['vendor.assistant.show', 'vendor.assistant.message'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('vendor.approved', $route->gatherMiddleware(), $name);
            $this->assertContains('web', $route->gatherMiddleware(), $name);
        }

        $this->assertContains('throttle:vendor-assistant', Route::getRoutes()->getByName('vendor.assistant.message')->gatherMiddleware());
    }

    public function test_the_post_is_csrf_protected(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->respondWith('ok');

        // The test environment skips CSRF; pretend to be a real one for these requests.
        $this->app['env'] = 'local';

        $this->signedIn($vendor)->postJson(route('vendor.assistant.message'), ['message' => 'no token'])->assertStatus(419);
        $this->assertSame(0, $this->mockProvider()->callCount());

        $this->withSession(['_token' => 'csrf-test-token'])
            ->postJson(route('vendor.assistant.message'), ['message' => 'with token'], ['X-CSRF-TOKEN' => 'csrf-test-token'])
            ->assertOk();
    }

    public function test_the_http_throttle_is_per_vendor(): void
    {
        $vendor = $this->approvedVendor();

        foreach (range(1, 10) as $i) {
            $this->ask($vendor, "question {$i}")->assertOk();
        }

        $this->ask($vendor, 'one too many')->assertStatus(429);
        $this->assertSame(10, $this->mockProvider()->callCount());

        $this->ask($this->approvedVendor(), 'a different vendor has their own bucket')->assertOk();
    }

    public function test_a_tool_context_can_only_be_built_from_a_persisted_approved_vendor(): void
    {
        foreach ([new Vendor(['name' => 'unsaved']), $this->unapprovedVendor()] as $bad) {
            try {
                ToolContext::forVendor($bad);
                $this->fail('expected a context error');
            } catch (AiToolContextException) {
                $this->addToAssertionCount(1);
            }
        }

        // Not an id, array or string: a Vendor model only.
        foreach ([7, '7', ['id' => 7]] as $bad) {
            try {
                ToolContext::forVendor($bad);
                $this->fail('expected a TypeError');
            } catch (\TypeError) {
                $this->addToAssertionCount(1);
            }
        }

        $vendor = $this->approvedVendor();
        $context = ToolContext::forVendor($vendor);
        $this->assertSame($vendor->id, $context->vendorId);
        $this->assertSame('vendor', $context->surface->value);

        $this->expectException(AiToolContextException::class);
        unserialize(serialize($context));
    }
}
