<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\DeveloperWebhook;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Models\Developer\SandboxOrder;
use App\Services\DeveloperApi\DeveloperDashboardService;
use App\Services\DeveloperApi\Webhooks\WebhookService;

class SandboxPortalTest extends SandboxTestCase
{
    private function hook($app, string $env, string $url = 'https://hooks.example.com/in'): DeveloperWebhook
    {
        return app(WebhookService::class)->create($app, 'test', $url, ['order.created', 'order.completed', 'payment.succeeded'], null, $env)['webhook'];
    }

    /** Two orders: one completed, one with a failed payment. */
    private function activity(array $w): void
    {
        $a = $this->sandboxOrderId($w['key'], $w['offer'], 'a');
        $this->startPayment($w['key'], $a);
        $this->simulate($w['key'], $a, ['scenario' => 'success']);

        $b = $this->sandboxOrderId($w['key'], $w['offer'], 'b');
        $this->startPayment($w['key'], $b);
        $this->simulate($w['key'], $b, ['scenario' => 'payment_failed']);
    }

    // ── dashboard ────────────────────────────────────────────────────────────

    public function test_the_dashboard_shows_separate_sandbox_activity(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox');
        $this->activity($w);

        $page = $this->actingAsVendor($w['vendor'])->get('/developers/dashboard')->assertOk();

        $page->assertSee('Sandbox activity')
            ->assertSee('data-stat="sandbox-orders">2</div>', false)
            ->assertSee('data-stat="sandbox-payments">2</div>', false)
            ->assertSee('data-stat="sandbox-completed">1</div>', false)
            ->assertSee('data-stat="sandbox-failed">1</div>', false)
            ->assertSee('Recent sandbox orders')
            ->assertSee('Recent sandbox failures')
            ->assertSee('Sandbox webhook deliveries')
            ->assertSee('0 live &middot; 1 test', false);
        $this->assertStringContainsString(SandboxOrder::first()->order_ref, $page->getContent());
    }

    public function test_sandbox_numbers_never_mix_into_production_numbers(): void
    {
        $w = $this->sandboxWorld();
        $this->hook($w['app'], 'sandbox');
        $prod = $this->hook($w['app'], 'production', 'https://other.example.org/live');
        $this->activity($w);

        $d = app(DeveloperDashboardService::class)->forVendor($w['vendor']);

        // Sandbox side saw the deliveries...
        $this->assertGreaterThan(0, $d['sandbox']['webhooks']['deliveries_7d']['succeeded']);
        $this->assertSame(1, $d['sandbox']['webhooks']['endpoints']);
        // ...the production webhook panel did not.
        $this->assertSame(1, $d['webhooks']['endpoints']);
        $this->assertSame(['succeeded' => 0, 'pending' => 0, 'failed' => 0], $d['webhooks']['deliveries_7d']);
        $this->assertCount(0, $d['webhooks']['recent']);
        $this->assertSame(0, DeveloperWebhookDelivery::where('webhook_id', $prod->id)->count());

        // Request usage is split by key environment.
        $this->assertGreaterThan(0, $d['usage_by_environment']['sandbox']);
        $this->assertSame(0, $d['usage_by_environment']['production']);
        $this->assertSame(['sandbox' => 1, 'production' => 0], $d['active_keys_by_environment']);
    }

    public function test_another_vendors_sandbox_activity_is_never_shown(): void
    {
        $mine = $this->sandboxWorld();
        $theirs = $this->sandboxWorld();
        $this->activity($theirs);

        $d = app(DeveloperDashboardService::class)->forVendor($mine['vendor']);

        $this->assertSame(0, $d['sandbox']['orders']);
        $this->assertSame(0, $d['sandbox']['payments']);
        $this->assertCount(0, $d['sandbox']['recent_orders']);

        $page = $this->actingAsVendor($mine['vendor'])->get('/developers/dashboard')->assertOk();
        $this->assertStringNotContainsString(SandboxOrder::first()->order_ref, $page->getContent());
    }

    public function test_an_empty_account_shows_zeros_not_invented_numbers(): void
    {
        $vendor = $this->approvedVendor();

        $this->actingAsVendor($vendor)->get('/developers/dashboard')->assertOk()
            ->assertSee('data-stat="sandbox-orders">0</div>', false)
            ->assertSee('No sandbox orders yet');
    }

    // ── application page ─────────────────────────────────────────────────────

    public function test_keys_are_labelled_test_or_live_and_the_application_page_has_its_sandbox_activity(): void
    {
        $w = $this->sandboxWorld();
        $this->liveKey($w['app']);
        $this->activity($w);

        $this->actingAsVendor($w['vendor'])->get("/developers/applications/{$w['app']->id}")->assertOk()
            ->assertSee('TEST (sandbox)')->assertSee('LIVE')
            ->assertSee('Sandbox activity')
            ->assertSee('data-stat="sandbox-orders">2</div>', false)
            ->assertSee('xt_test_', false);

        // Sandbox requests carry a "test" badge (the badge component pads its slot with whitespace).
        $log = $this->actingAsVendor($w['vendor'])->get("/developers/applications/{$w['app']->id}/requests")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*test\s*<\/span>/', $log);
    }

    // ── webhook management through the portal ────────────────────────────────

    private function registerHook(array $w, array $extra = [])
    {
        return $this->actingAsVendor($w['vendor'])->post("/developers/applications/{$w['app']->id}/webhooks", array_merge([
            'url' => 'https://hooks.example.com/in', 'events' => ['order.created'],
        ], $extra));
    }

    public function test_a_sandbox_endpoint_can_be_registered_in_the_portal(): void
    {
        $w = $this->sandboxWorld();

        $this->registerHook($w, ['environment' => 'sandbox'])->assertOk();

        $this->assertSame('sandbox', DeveloperWebhook::firstOrFail()->environment);
    }

    public function test_the_portal_defaults_to_production_when_no_environment_is_sent(): void
    {
        $w = $this->sandboxWorld();

        $this->registerHook($w)->assertOk();

        $this->assertSame('production', DeveloperWebhook::firstOrFail()->environment);
    }

    public function test_an_unknown_environment_is_rejected(): void
    {
        $w = $this->sandboxWorld();

        $this->registerHook($w, ['environment' => 'staging'])->assertSessionHasErrors('environment');

        $this->assertSame(0, DeveloperWebhook::count());
    }

    public function test_an_endpoints_environment_cannot_be_changed_afterwards(): void
    {
        $w = $this->sandboxWorld();
        $hook = $this->hook($w['app'], 'sandbox');

        $this->actingAsVendor($w['vendor'])->put("/developers/applications/{$w['app']->id}/webhooks/{$hook->id}", [
            'url' => 'https://hooks.example.com/changed', 'events' => ['order.created'], 'environment' => 'production',
        ]);

        $this->assertSame('sandbox', $hook->fresh()->environment);
    }

    public function test_the_webhook_pages_label_each_endpoint(): void
    {
        $w = $this->sandboxWorld();
        $sbx = $this->hook($w['app'], 'sandbox');
        $this->hook($w['app'], 'production', 'https://other.example.org/live');

        $this->actingAsVendor($w['vendor'])->get("/developers/applications/{$w['app']->id}/webhooks")->assertOk()
            ->assertSee('SANDBOX')->assertSee('LIVE')->assertSee('Sandbox: receives only simulated test-key events');
        $this->get("/developers/applications/{$w['app']->id}/webhooks/{$sbx->id}/deliveries")->assertOk()->assertSee('SANDBOX');
    }

    public function test_another_vendor_cannot_register_a_sandbox_endpoint_on_my_application(): void
    {
        $mine = $this->sandboxWorld();
        $other = $this->approvedVendor();

        $this->actingAsVendor($other)->post("/developers/applications/{$mine['app']->id}/webhooks", [
            'url' => 'https://hooks.example.com/in', 'events' => ['order.created'], 'environment' => 'sandbox',
        ])->assertNotFound();

        $this->assertSame(0, DeveloperWebhook::count());
    }
}
