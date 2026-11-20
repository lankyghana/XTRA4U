<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use App\Models\User;
use App\Models\Vendor;
use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Tools\Exceptions\AiToolContextException;
use App\Services\Ai\Tools\ToolContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The assistant sits behind the EXISTING Developer Portal gate (vendor guard + live approval). There is no second
 * identity system, and approval is re-read on every request and on every tool call.
 */
class AccessControlTest extends DeveloperAssistantTestCase
{
    public function test_a_guest_is_sent_to_the_existing_vendor_login(): void
    {
        $this->get(route('developers.assistant'))->assertRedirect(route('vendor.login.form'));
        $this->postJson(route('developers.assistant.message'), ['message' => 'hello'])->assertRedirect(route('vendor.login.form'));

        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_a_pending_or_unapproved_vendor_is_denied(): void
    {
        $vendor = $this->unapprovedVendor();

        $this->signedIn($vendor)->get(route('developers.assistant'))->assertForbidden()->assertViewIs('developers.access-required');
        $this->ask($vendor, 'hello')->assertForbidden();

        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_a_suspended_vendor_is_denied(): void
    {
        $vendor = $this->approvedVendor();
        $vendor->forceFill(['is_approved' => false])->save(); // suspension is exactly is_approved = false

        $this->signedIn($vendor->fresh())->get(route('developers.assistant'))->assertForbidden();
        $this->ask($vendor->fresh(), 'hello')->assertForbidden();
        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_an_approved_vendor_can_use_the_assistant_when_it_is_explicitly_enabled(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->respondWith('Send the key as a Bearer token.');

        $this->signedIn($vendor)->get(route('developers.assistant'))
            ->assertOk()
            ->assertSee('data-assistant-state="available"', false)
            ->assertSee('Developer Assistant');

        $this->ask($vendor, 'How do I authenticate?')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('answer', 'Send the key as a Bearer token.');
    }

    public function test_access_is_denied_immediately_after_the_vendor_is_suspended(): void
    {
        $vendor = $this->approvedVendor();
        $this->mockProvider()->respondWith('first answer');

        $this->ask($vendor, 'hello')->assertOk();
        $callsBefore = $this->mockProvider()->callCount();

        // Suspended behind Eloquent's back (a raw query-builder update): the live check must still catch it.
        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $this->ask(Vendor::find($vendor->id), 'hello again')->assertForbidden();
        $this->signedIn(Vendor::find($vendor->id))->get(route('developers.assistant'))->assertForbidden();
        $this->assertSame($callsBefore, $this->mockProvider()->callCount(), 'the provider is not reached for a suspended vendor');
    }

    public function test_a_session_that_outlives_approval_is_not_enough(): void
    {
        $vendor = $this->approvedVendor();
        // A real vendor-guard SESSION (not an in-memory acting-as user), as a browser would hold it.
        $this->withSession([Auth::guard('vendor')->getName() => $vendor->id]);
        $this->get(route('developers.assistant'))->assertOk();

        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        // The next HTTP request is a new PHP process in production: the guard reloads the vendor from the database.
        Auth::forgetGuards();
        $this->postJson(route('developers.assistant.message'), ['message' => 'still here?'])->assertForbidden();
        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_there_is_no_separate_developer_identity(): void
    {
        $this->assertArrayNotHasKey('developer', config('auth.guards'));
        $this->assertArrayNotHasKey('developers', config('auth.guards'));

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $this->assertDoesNotMatchRegularExpression('/^developers\.(login|register|logout|password)/', $name);
        }

        // A non-vendor identity (an admin user on the web guard) is not a developer either.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->get(route('developers.assistant'))->assertRedirect(route('vendor.login.form'));
        $this->actingAs($admin, 'web')->postJson(route('developers.assistant.message'), ['message' => 'hi'])->assertRedirect(route('vendor.login.form'));
        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_the_assistant_is_not_exposed_on_the_public_developer_information_page(): void
    {
        $this->get(route('developers.landing'))
            ->assertOk()
            ->assertDontSee('assistant/messages')
            ->assertDontSee('developerAssistant');

        $route = Route::getRoutes()->getByName('developers.landing');
        $this->assertNotContains('developer.access', $route->gatherMiddleware(), 'the landing page stays public');

        foreach (['developers.assistant', 'developers.assistant.message'] as $name) {
            $this->assertContains('developer.access', Route::getRoutes()->getByName($name)->gatherMiddleware(), "{$name} sits behind the existing portal gate");
        }
    }

    public function test_the_assistant_posts_are_csrf_protected_and_throttled(): void
    {
        $route = Route::getRoutes()->getByName('developers.assistant.message');
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:developer-assistant', $route->gatherMiddleware());

        // The test environment skips CSRF; pretend to be a real one for these two requests.
        $vendor = $this->approvedVendor();
        $this->app['env'] = 'local';
        $this->mockProvider()->respondWith('ok');

        $this->signedIn($vendor)->postJson(route('developers.assistant.message'), ['message' => 'no token'])->assertStatus(419);
        $this->assertSame(0, $this->mockProvider()->callCount());

        $this->withSession(['_token' => 'csrf-test-token'])
            ->postJson(route('developers.assistant.message'), ['message' => 'with token'], ['X-CSRF-TOKEN' => 'csrf-test-token'])
            ->assertOk();
    }

    public function test_the_http_throttle_limits_a_vendor(): void
    {
        $vendor = $this->approvedVendor();

        foreach (range(1, 10) as $i) {
            $this->ask($vendor, "question {$i}")->assertOk();
        }

        $this->ask($vendor, 'one too many')->assertStatus(429);
        $this->assertSame(10, $this->mockProvider()->callCount());

        // Another vendor has their own bucket.
        $this->ask($this->approvedVendor(), 'fresh vendor')->assertOk();
    }

    // ---------------------------------------------------------------- ToolContext (section 4 of the brief)

    public function test_a_developer_context_is_built_only_from_a_persisted_approved_vendor_model(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forDeveloper($vendor);

        $this->assertSame($vendor->id, $context->vendorId);
        $this->assertSame($vendor->id, $context->actorId);
        $this->assertTrue($context->can(ToolContext::CAP_DEVELOPER_PORTAL));

        foreach ([$vendor->id, (string) $vendor->id, ['vendor_id' => $vendor->id], (object) ['id' => $vendor->id], null] as $notAVendor) {
            try {
                ToolContext::forDeveloper($notAVendor);
                $this->fail('a context must not be creatable from request-shaped data');
            } catch (\TypeError) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(AiToolContextException::class);
        ToolContext::forDeveloper(new Vendor(['name' => 'forged', 'is_approved' => true])); // never persisted
    }

    public function test_a_context_cannot_be_made_for_an_unapproved_vendor_nor_unserialised(): void
    {
        try {
            ToolContext::forDeveloper($this->unapprovedVendor());
            $this->fail('unapproved vendor accepted');
        } catch (AiToolContextException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(AiToolContextException::class);
        unserialize(serialize(ToolContext::forDeveloper($this->approvedVendor())));
    }

    public function test_a_context_created_before_suspension_stops_working_the_moment_the_vendor_is_suspended(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forDeveloper($vendor);

        $this->assertTrue($this->runTool('developer_list_my_apps', [], $context)->isOk());

        DB::table('vendors')->where('id', $vendor->id)->update(['is_approved' => false]);

        $result = $this->runTool('developer_list_my_apps', [], $context, 'call_2');
        $this->assertFalse($result->isOk());
        $this->assertSame('invalid_context', $result->errorCode);

        $this->assertSame('invalid_context', $this->runTool('developer_search_docs', ['query' => 'scopes'], $context, 'call_3')->errorCode);
    }

    public function test_a_context_for_a_deleted_vendor_stops_working(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forDeveloper($vendor);

        DB::table('vendors')->where('id', $vendor->id)->delete();

        $this->assertSame('invalid_context', $this->runTool('developer_search_docs', ['query' => 'scopes'], $context)->errorCode);
    }

    public function test_the_http_body_cannot_choose_the_vendor_the_context_is_built_for(): void
    {
        $a = $this->approvedVendor();
        $b = $this->approvedVendor();

        // An extra field is refused outright...
        $this->ask($a, 'hello', ['vendor_id' => $b->id])->assertStatus(422);
        $this->ask($a, 'hello', ['context' => ['vendor_id' => $b->id], 'owner_id' => $b->id])->assertStatus(422);
        $this->assertSame(0, $this->mockProvider()->callCount());

        // ...and a normal request runs for the authenticated vendor, whatever else is going on.
        $this->ask($a, 'hello')->assertOk();
        $this->assertSame('vendor:'.$a->id, $this->mockProvider()->calls()[0]->principalKey);
    }

    public function test_for_developer_is_only_called_from_the_assistant_controller(): void
    {
        $callers = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'forDeveloper(')) {
                $callers[] = basename($f->getPathname());
            }
        }
        sort($callers);

        $this->assertSame(['AssistantController.php', 'ToolContext.php'], $callers);
        $this->assertStringContainsString('ToolContext::forDeveloper($vendor)', (string) file_get_contents(app_path('Http/Controllers/Developers/AssistantController.php')));
        $this->assertStringContainsString("Auth::guard('vendor')->user()", (string) file_get_contents(app_path('Http/Controllers/Developers/DeveloperController.php')));
        $this->assertInstanceOf(DeveloperAssistant::class, app(DeveloperAssistant::class));
    }
}
