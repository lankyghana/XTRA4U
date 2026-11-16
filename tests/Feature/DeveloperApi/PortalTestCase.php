<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Developer\Developer;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\DeveloperWebhook;
use App\Models\User;
use App\Services\DeveloperApi\Webhooks\DnsResolver;
use App\Services\DeveloperApi\Webhooks\WebhookService;
use Illuminate\Support\Facades\Http;

abstract class PortalTestCase extends DeveloperApiTestCase
{
    protected FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = (new FakeDnsResolver)
            ->set('hooks.example.com', '93.184.216.34')
            ->set('other.example.org', '8.8.8.8');
        $this->app->instance(DnsResolver::class, $this->dns);

        // Safety net: no test may ever make a real outbound request.
        Http::preventStrayRequests();
    }

    protected function developer(array $attrs = []): Developer
    {
        // fresh(): load DB defaults (status=active) exactly as a real request would.
        return Developer::create(array_merge([
            'name' => 'Dev '.uniqid(), 'email' => uniqid().'@example.test', 'password' => 'secret-pass-123',
        ], $attrs))->fresh();
    }

    protected function sandboxApp(Developer $developer, array $overrides = []): DeveloperApplication
    {
        $app = new DeveloperApplication(['developer_id' => $developer->id, 'name' => 'Portal App']);
        $app->forceFill(array_merge([
            'environment' => 'sandbox', 'production_status' => 'none', 'status' => 'active',
            'vendor_id' => null, 'vendor_link_status' => 'none',
        ], $overrides))->save();

        return $app;
    }

    /** Http::fake() calls accumulate (first matching stub wins), so re-faking mid-test needs a fresh factory. */
    protected function fakeHttp(mixed $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
        Http::preventStrayRequests();
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Explicit guard: actingAs() without one targets whichever guard was last made default. */
    protected function asAdmin(): static
    {
        return $this->actingAs($this->admin(), 'web');
    }

    protected function actingAsDeveloper(Developer $developer): static
    {
        return $this->actingAs($developer, 'developer');
    }

    /** @return array{0:DeveloperWebhook,1:string} */
    protected function makeWebhook(DeveloperApplication $app, string $url = 'https://hooks.example.com/in', array $events = ['order.created', 'payment.succeeded', 'order.completed']): array
    {
        $r = app(WebhookService::class)->create($app, 'test', $url, $events, null);

        return [$r['webhook'], $r['secret']];
    }
}
