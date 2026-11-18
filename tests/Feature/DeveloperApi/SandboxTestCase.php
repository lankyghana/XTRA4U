<?php

namespace Tests\Feature\DeveloperApi;

use App\Events\OrderCompleted;
use App\Models\Developer\DeveloperApplication;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\WalletLedger;
use App\Services\DeveloperApi\ApiOrderService;
use App\Services\DeveloperApi\ApiPaymentService;
use App\Services\DeveloperApi\Webhooks\WebhookTransport;
use App\Services\GatewayManager;
use App\Services\Payments\PaymentIntegrityGuard;
use App\Services\PaymentService;
use App\Services\SmsService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Shared sandbox helpers. Every test that extends this runs with:
 *  - Http::preventStrayRequests() and a fake DNS resolver (from PortalTestCase),
 *  - a RecordingTransport bound as the webhook transport, so NO webhook request can leave the process.
 */
abstract class SandboxTestCase extends PortalTestCase
{
    protected RecordingTransport $transport;

    /** @var list<string> production services that something tried to resolve while they were forbidden */
    protected array $touched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new RecordingTransport;
        $this->app->instance(WebhookTransport::class, $this->transport);
    }

    /**
     * An approved vendor with one product, an application, and a SANDBOX key.
     *
     * @return array{vendor:Vendor, app:DeveloperApplication, key:string, product:Product, offer:string}
     */
    protected function sandboxWorld(float $price = 10.0, ?array $scopes = null): array
    {
        $vendor = $this->approvedVendor();
        $app = $this->makeApp($vendor);
        $product = $this->product($vendor, $price);
        [, $plain] = $this->makeKey($app, $scopes, null, 'sandbox');

        return ['vendor' => $vendor, 'app' => $app, 'key' => $plain, 'product' => $product, 'offer' => "own_{$product->id}"];
    }

    protected function liveKey(DeveloperApplication $app, ?array $scopes = null): string
    {
        return $this->makeKey($app, $scopes, null, 'production')[1];
    }

    protected function quote(string $key, string $offer, string $phone = '0244000000'): TestResponse
    {
        return $this->postJson('/api/v1/quotes', ['product_id' => $offer, 'recipient_phone' => $phone], $this->auth($key));
    }

    protected function order(string $key, string $quoteRef, string $idem = 'idem-1', array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/orders', ['quote_reference' => $quoteRef] + $body, $this->auth($key) + ['Idempotency-Key' => $idem]);
    }

    /** Quote + order in one go. @return string sandbox order id */
    protected function sandboxOrderId(string $key, string $offer, string $idem = 'idem-1', array $body = []): string
    {
        $quote = $this->quote($key, $offer)->assertCreated()->json('data.reference');

        return $this->order($key, $quote, $idem, $body)->assertCreated()->json('data.id');
    }

    protected function startPayment(string $key, string $orderId): TestResponse
    {
        return $this->postJson("/api/v1/orders/{$orderId}/payments", [], $this->auth($key));
    }

    protected function simulate(string $key, string $orderId, array $body): TestResponse
    {
        return $this->postJson("/api/v1/sandbox/orders/{$orderId}/simulate", $body, $this->auth($key));
    }

    // ── "production is never touched" instrumentation ────────────────────────

    /**
     * From now on, resolving any production payment / fulfilment / wallet / SMS service is recorded AND throws.
     * Also fakes mail, notifications and the OrderCompleted event, and refuses outbound HTTP.
     */
    protected function forbidProductionServices(): void
    {
        foreach ([
            GatewayManager::class, PaymentService::class, PaymentIntegrityGuard::class, WalletService::class,
            SmsService::class, ApiOrderService::class, ApiPaymentService::class,
        ] as $abstract) {
            $this->app->bind($abstract, function () use ($abstract) {
                $this->touched[] = $abstract;
                throw new \LogicException("The sandbox touched the production service {$abstract}.");
            });
        }

        Event::fake([OrderCompleted::class]);
        Mail::fake();
        Notification::fake();
        Http::fake();
    }

    protected function assertNoProductionServiceTouched(): void
    {
        $this->assertSame([], $this->touched, 'Production services were resolved by a sandbox flow.');
        Event::assertNotDispatched(OrderCompleted::class);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    /** Snapshot of every production money/order table the sandbox must never change. */
    protected function productionFingerprint(Vendor ...$vendors): array
    {
        return [
            'orders' => Order::count(),
            'transactions' => Transaction::count(),
            'wallet_ledgers' => WalletLedger::count(),
            'vendors' => collect($vendors)->mapWithKeys(fn (Vendor $v) => [$v->id => $v->fresh()->only(['balance', 'wallet_balance'])])->all(),
        ];
    }
}
