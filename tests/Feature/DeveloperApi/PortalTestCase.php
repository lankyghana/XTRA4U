<?php

namespace Tests\Feature\DeveloperApi;

use App\Models\Vendor;
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

    /** An approved vendor, the only kind that may use the Developer Portal. */
    protected function approvedVendor(array $attrs = []): Vendor
    {
        return $this->vendor(array_merge(['is_approved' => true], $attrs))->fresh();
    }

    /** Pending, rejected and suspended vendors are all just `is_approved = false`. */
    protected function unapprovedVendor(array $attrs = []): Vendor
    {
        return $this->vendor(array_merge(['is_approved' => false], $attrs))->fresh();
    }

    /** Sign in through the EXISTING vendor guard: there is no separate developer login. */
    protected function actingAsVendor(Vendor $vendor): static
    {
        return $this->actingAs($vendor, 'vendor');
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

    /** @return array{0:DeveloperWebhook,1:string} */
    protected function makeWebhook(DeveloperApplication $app, string $url = 'https://hooks.example.com/in', array $events = ['order.created', 'payment.succeeded', 'order.completed']): array
    {
        $r = app(WebhookService::class)->create($app, 'test', $url, $events, null);

        return [$r['webhook'], $r['secret']];
    }
}
