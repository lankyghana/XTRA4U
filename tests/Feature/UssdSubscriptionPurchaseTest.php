<?php

namespace Tests\Feature;

use App\Jobs\SendUssdSubscriptionNotification;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use App\Services\PaymentService;
use App\Services\Ussd\UssdSubscriptionPurchaseService;
use App\Services\Ussd\UssdSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class UssdSubscriptionPurchaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gateway verification keyed by payment reference.
     *
     * A single PaymentService double is bound once, in setUp, and dispatches on
     * the reference. Re-binding a mock mid-test does not work here: Laravel's
     * Route::getController() caches the controller instance on the Route object
     * for the life of the process, so a controller built during the first
     * request keeps the dependencies it was constructed with.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $verifications = [];

    /** @var array<string, mixed> */
    private array $initiateResult = [];

    /** @var array<int, string> */
    private array $verifiedReferences = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->initiateResult = [
            'success' => true,
            'reference' => null,
            'authorization_url' => 'https://gateway.test/pay/abc',
            'gateway_name' => 'paystack',
        ];

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('isReady')->andReturn(true);
        $mock->shouldReceive('initiateGenericPayment')->andReturnUsing(fn () => $this->initiateResult);
        $mock->shouldReceive('checkPaymentStatus')->andReturnUsing(function (string $reference) {
            $this->verifiedReferences[] = $reference;

            return $this->verifications[$reference] ?? ['success' => false, 'message' => 'Not found'];
        });

        $this->app->instance(PaymentService::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function starter(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    private function business(): UssdPlan
    {
        return UssdPlan::where('extension_code', '46')->firstOrFail();
    }

    private function settles(string $reference, float $amount, string $status = 'success'): void
    {
        $this->verifications[$reference] = [
            'success' => true,
            'data' => ['status' => $status, 'amount' => $amount],
        ];
    }

    private function pendingSubscription(Vendor $vendor, UssdPlan $plan, string $reference, string $gateway = 'hubtel'): UssdSubscription
    {
        return UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => $reference,
            'payment_gateway' => $gateway,
        ]);
    }

    private function hitCallback(string $reference)
    {
        return $this->get(route('vendor.ussd.subscription.callback', ['reference' => $reference]));
    }

    // ---------------------------------------------------------------- initiate

    public function test_vendor_can_initiate_a_plan_purchase(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $plan->id])
            ->assertOk()
            ->assertJson(['success' => true, 'payment_type' => 'ussd_subscription']);

        $subscription = UssdSubscription::firstOrFail();
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, $subscription->status);
        $this->assertSame($vendor->id, $subscription->vendor_id);
        $this->assertSame(80.0, $subscription->price_paid);
        $this->assertSame(5000, $subscription->total_sessions);
        $this->assertNull($subscription->ussd_code, 'No code may exist before payment settles.');
        $this->assertNull($subscription->current_for_vendor_id);

        $this->assertDatabaseHas('ussd_subscription_events', [
            'event' => UssdSubscriptionEvent::PLAN_PURCHASE_INITIATED,
        ]);
    }

    public function test_vendor_id_in_the_request_body_is_ignored(): void
    {
        $vendor = Vendor::factory()->create();
        $attacker = Vendor::factory()->create();

        $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), [
                'plan_id' => $this->starter()->id,
                'vendor_id' => $attacker->id,
            ])->assertOk();

        $this->assertSame($vendor->id, UssdSubscription::firstOrFail()->vendor_id);
    }

    public function test_inactive_plan_cannot_be_purchased(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();
        $plan->update(['is_active' => false]);

        $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $plan->id])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertDatabaseCount('ussd_subscriptions', 0);
    }

    public function test_failed_gateway_initiation_leaves_no_subscription_behind(): void
    {
        $vendor = Vendor::factory()->create();
        $this->initiateResult = ['success' => false, 'message' => 'Gateway down'];

        $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $this->starter()->id])
            ->assertStatus(400);

        $this->assertDatabaseCount('ussd_subscriptions', 0);
        $this->assertDatabaseHas('ussd_subscription_events', ['event' => UssdSubscriptionEvent::PAYMENT_FAILED]);
    }

    public function test_gateway_issued_reference_replaces_ours(): void
    {
        $vendor = Vendor::factory()->create();
        $this->initiateResult = ['success' => true, 'reference' => 'XTRA4U-BCX-999', 'gateway_name' => 'bulkclix'];

        $this->actingAs($vendor, 'vendor')
            ->postJson(route('vendor.ussd.subscription.purchase'), ['plan_id' => $this->starter()->id])
            ->assertOk()
            ->assertJson(['reference' => 'XTRA4U-BCX-999']);

        $this->assertSame('XTRA4U-BCX-999', UssdSubscription::firstOrFail()->payment_reference);
    }

    public function test_guest_is_redirected_away_from_purchase(): void
    {
        $this->post(route('vendor.ussd.subscription.purchase'), ['plan_id' => $this->starter()->id])
            ->assertRedirect(route('vendor.login.form'));

        $this->assertDatabaseCount('ussd_subscriptions', 0);
    }

    // ---------------------------------------------------------------- callback

    public function test_verified_payment_activates_and_issues_a_ussd_code(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-1');
        $this->settles('ussd-ref-1', 80.00);

        $this->hitCallback('ussd-ref-1')->assertRedirect(route('vendor.ussd.subscription.index'));

        $subscription = UssdSubscription::firstOrFail();
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame("*203*45*{$vendor->id}#", $subscription->ussd_code);
        $this->assertSame($vendor->id, $subscription->current_for_vendor_id);
        $this->assertNotNull($subscription->expires_at);
        $this->assertSame(30, (int) now()->startOfDay()->diffInDays($subscription->expires_at->startOfDay()));
        $this->assertTrue($subscription->isUsable());
        $this->assertTrue($vendor->fresh()->hasUssdAccess());

        $this->assertDatabaseHas('ussd_subscription_events', ['event' => UssdSubscriptionEvent::PAYMENT_VERIFIED]);
        $this->assertDatabaseHas('ussd_subscription_events', ['event' => UssdSubscriptionEvent::USSD_CODE_GENERATED]);
    }

    public function test_activation_is_idempotent_across_repeated_callbacks(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-2');
        $this->settles('ussd-ref-2', 80.00);

        $this->hitCallback('ussd-ref-2');
        $this->hitCallback('ussd-ref-2');
        $this->hitCallback('ussd-ref-2');

        $this->assertDatabaseCount('ussd_subscriptions', 1);
        $this->assertSame(1, UssdSubscriptionEvent::where('event', UssdSubscriptionEvent::SUBSCRIPTION_ACTIVATED)->count());
        $this->assertSame(5000, UssdSubscription::firstOrFail()->total_sessions, 'Sessions must not be granted twice.');
    }

    public function test_underpayment_does_not_activate(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-3');
        $this->settles('ussd-ref-3', 20.00);

        $this->hitCallback('ussd-ref-3');

        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, UssdSubscription::firstOrFail()->status);
        $this->assertDatabaseHas('ussd_subscription_events', ['event' => UssdSubscriptionEvent::PAYMENT_FAILED]);
    }

    public function test_unsuccessful_gateway_status_does_not_activate(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-4');
        $this->settles('ussd-ref-4', 80.00, 'failed');

        $this->hitCallback('ussd-ref-4');

        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, UssdSubscription::firstOrFail()->status);
    }

    public function test_pending_gateway_status_on_callback_never_notifies_failure(): void
    {
        Bus::fake();

        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-pend');
        $this->settles('ussd-ref-pend', 80.00, 'pending');

        $this->hitCallback('ussd-ref-pend');

        // A payment that is merely not settled yet is not a failure: the money
        // may already be deducted and settlement just lagging.
        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, UssdSubscription::firstOrFail()->status);
        $this->assertDatabaseMissing('ussd_subscription_events', ['event' => UssdSubscriptionEvent::PAYMENT_FAILED]);
        Bus::assertNotDispatched(SendUssdSubscriptionNotification::class);
    }

    public function test_verification_error_on_callback_never_notifies_failure(): void
    {
        Bus::fake();

        $vendor = Vendor::factory()->create();
        // No settles(): the gateway double returns success=false ("Not found"),
        // i.e. the verification call itself failed — not the payment.
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-err');

        $this->hitCallback('ussd-ref-err');

        $this->assertSame(UssdSubscription::STATUS_PENDING_PAYMENT, UssdSubscription::firstOrFail()->status);
        $this->assertDatabaseMissing('ussd_subscription_events', ['event' => UssdSubscriptionEvent::PAYMENT_FAILED]);
        Bus::assertNotDispatched(SendUssdSubscriptionNotification::class);
    }

    public function test_terminal_failure_notifies_only_once_across_retried_callbacks(): void
    {
        Bus::fake();

        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-term');
        $this->settles('ussd-ref-term', 80.00, 'failed');

        $this->hitCallback('ussd-ref-term');
        $this->hitCallback('ussd-ref-term');
        $this->hitCallback('ussd-ref-term');

        $this->assertSame(1, UssdSubscriptionEvent::where('event', UssdSubscriptionEvent::PAYMENT_FAILED)->count());
        Bus::assertDispatchedTimes(SendUssdSubscriptionNotification::class, 1);
    }

    public function test_visiting_the_subscription_page_activates_a_late_settled_purchase(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-late-1');
        // Settlement arrived after the purchase page's polling window closed.
        $this->settles('ussd-late-1', 80.00);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.ussd.subscription.index'))
            ->assertOk();

        $subscription = UssdSubscription::firstOrFail();
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->ussd_code);
    }

    public function test_paystack_pesewa_amounts_are_normalised_before_comparison(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-5', 'paystack');

        // Paystack reports GHS 80.00 as 8000 pesewas.
        $this->settles('ussd-ref-5', 8000);

        $this->hitCallback('ussd-ref-5');

        $this->assertSame(UssdSubscription::STATUS_ACTIVE, UssdSubscription::firstOrFail()->status);
    }

    public function test_unknown_reference_is_rejected(): void
    {
        $this->getJson(route('vendor.ussd.subscription.callback', ['reference' => 'does-not-exist-12345']))
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function test_malformed_reference_never_reaches_the_gateway(): void
    {
        $this->getJson(url('/vendor/ussd/subscription/callback/short'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame([], $this->verifiedReferences, 'A malformed reference must be rejected before any gateway call.');
    }

    // ------------------------------------------------------------ status polling

    public function test_status_endpoint_reports_pending_then_paid(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-poll-1');

        // While the gateway has not settled, polling must report pending and must
        // not activate the subscription.
        $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => 'ussd-poll-1']))
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'pending']);

        $this->assertSame(
            UssdSubscription::STATUS_PENDING_PAYMENT,
            UssdSubscription::firstOrFail()->status
        );

        // Once the gateway settles, the next poll activates and reports paid.
        $this->settles('ussd-poll-1', 80.00);

        $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => 'ussd-poll-1']))
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'paid']);

        $this->assertSame(
            UssdSubscription::STATUS_ACTIVE,
            UssdSubscription::firstOrFail()->status
        );
    }

    public function test_status_endpoint_never_records_a_failure_while_pending(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-poll-2');

        $this->actingAs($vendor, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => 'ussd-poll-2']))
            ->assertOk();

        // A merely-pending poll must not spam PAYMENT_FAILED events/notifications.
        $this->assertDatabaseMissing('ussd_subscription_events', [
            'event' => UssdSubscriptionEvent::PAYMENT_FAILED,
        ]);
    }

    public function test_status_endpoint_blocks_cross_vendor_access(): void
    {
        $owner = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $this->pendingSubscription($owner, $this->starter(), 'ussd-poll-3');

        $this->actingAs($other, 'vendor')
            ->getJson(route('vendor.ussd.subscription.status', ['reference' => 'ussd-poll-3']))
            ->assertStatus(404)
            ->assertJson(['success' => false, 'status' => 'unknown']);
    }

    public function test_status_endpoint_rejects_a_malformed_reference(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor, 'vendor')
            ->getJson(url('/vendor/ussd/subscription/status/short'))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame([], $this->verifiedReferences, 'A malformed reference must never reach the gateway.');
    }

    public function test_status_endpoint_requires_authentication(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-poll-4');

        // The vendor.approved guard sends unauthenticated visitors to the login
        // form rather than exposing any subscription state.
        $this->get(route('vendor.ussd.subscription.status', ['reference' => 'ussd-poll-4']))
            ->assertRedirect(route('vendor.login.form'));
    }

    // ---------------------------------------------------- upgrade and carryover

    public function test_upgrade_carries_over_unused_sessions_and_reissues_the_code(): void
    {
        $vendor = Vendor::factory()->create();

        $old = $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-old');
        $this->settles('ussd-ref-old', 80.00);
        $this->hitCallback('ussd-ref-old');

        $old->refresh();
        $old->update(['used_sessions' => 4200]); // 800 remaining
        $this->assertSame("*203*45*{$vendor->id}#", $old->ussd_code);

        $this->pendingSubscription($vendor, $this->business(), 'ussd-ref-new');
        $this->settles('ussd-ref-new', 250.00);
        $this->hitCallback('ussd-ref-new');

        $old->refresh();
        $new = UssdSubscription::where('payment_reference', 'ussd-ref-new')->firstOrFail();

        // The superseded subscription surrenders its slot and its code.
        $this->assertSame(UssdSubscription::STATUS_CANCELLED, $old->status);
        $this->assertNull($old->ussd_code);
        $this->assertNull($old->current_for_vendor_id);
        $this->assertSame($new->id, $old->superseded_by_id);
        $this->assertSame("*203*45*{$vendor->id}#", $old->metadata['released_ussd_code']);

        // New subscription: 20,000 from Business plus 800 carried over.
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $new->status);
        $this->assertSame(800, $new->carried_over_sessions);
        $this->assertSame(20800, $new->total_sessions);
        $this->assertSame("*203*46*{$vendor->id}#", $new->ussd_code);
        $this->assertSame($vendor->id, $new->current_for_vendor_id);

        $this->assertSame($new->id, $vendor->fresh()->currentUssdSubscription->id);
    }

    public function test_renewing_the_same_plan_reuses_the_released_code(): void
    {
        $vendor = Vendor::factory()->create();
        $starter = $this->starter();

        $this->pendingSubscription($vendor, $starter, 'ussd-ref-a');
        $this->settles('ussd-ref-a', 80.00);
        $this->hitCallback('ussd-ref-a');

        $this->pendingSubscription($vendor, $starter, 'ussd-ref-b');
        $this->settles('ussd-ref-b', 80.00);
        $this->hitCallback('ussd-ref-b');

        $new = UssdSubscription::where('payment_reference', 'ussd-ref-b')->firstOrFail();
        $this->assertSame(UssdSubscription::STATUS_ACTIVE, $new->status);
        $this->assertSame("*203*45*{$vendor->id}#", $new->ussd_code, 'A released code must be re-issuable.');
        $this->assertSame(10000, $new->total_sessions, '5000 new plus 5000 carried over.');
    }

    public function test_two_vendors_on_the_same_plan_get_different_codes(): void
    {
        $plan = $this->starter();
        $a = Vendor::factory()->create();
        $b = Vendor::factory()->create();

        $this->pendingSubscription($a, $plan, 'ussd-ref-a');
        $this->pendingSubscription($b, $plan, 'ussd-ref-b');
        $this->settles('ussd-ref-a', 80.00);
        $this->settles('ussd-ref-b', 80.00);

        $this->hitCallback('ussd-ref-a');
        $this->hitCallback('ussd-ref-b');

        $codeA = UssdSubscription::where('payment_reference', 'ussd-ref-a')->value('ussd_code');
        $codeB = UssdSubscription::where('payment_reference', 'ussd-ref-b')->value('ussd_code');

        $this->assertNotSame($codeA, $codeB);
        $this->assertSame("*203*45*{$a->id}#", $codeA);
        $this->assertSame("*203*45*{$b->id}#", $codeB);
    }

    // --------------------------------------------------- metering and lifecycle

    private function activeSubscription(Vendor $vendor, UssdPlan $plan, array $overrides = []): UssdSubscription
    {
        return UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'expires_at' => now()->addDays(10),
            'payment_reference' => Str::uuid()->toString(),
        ], $overrides));
    }

    public function test_reserve_session_increments_usage_and_stops_at_the_allowance(): void
    {
        $vendor = Vendor::factory()->create();
        $service = app(UssdSubscriptionService::class);
        $subscription = $this->activeSubscription($vendor, $this->starter(), ['total_sessions' => 2]);

        $this->assertTrue($service->reserveSession($subscription));
        $this->assertTrue($service->reserveSession($subscription));

        // Allowance exhausted: further reservations are refused, never negative.
        $this->assertFalse($service->reserveSession($subscription));

        $subscription->refresh();
        $this->assertSame(2, $subscription->used_sessions);
        $this->assertSame(0, $subscription->remaining_sessions);
        $this->assertDatabaseHas('ussd_subscription_events', ['event' => UssdSubscriptionEvent::SESSIONS_EXHAUSTED]);
    }

    public function test_reserve_session_refuses_an_expired_subscription(): void
    {
        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, $this->starter(), ['expires_at' => now()->subMinute()]);

        $this->assertFalse(app(UssdSubscriptionService::class)->reserveSession($subscription));
        $this->assertSame(0, $subscription->fresh()->used_sessions);
    }

    public function test_expire_due_releases_slot_and_code_and_is_idempotent(): void
    {
        $vendor = Vendor::factory()->create();
        $this->pendingSubscription($vendor, $this->starter(), 'ussd-ref-exp');
        $this->settles('ussd-ref-exp', 80.00);
        $this->hitCallback('ussd-ref-exp');

        $subscription = UssdSubscription::firstOrFail();
        $subscription->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame(1, app(UssdSubscriptionService::class)->expireDue());

        $subscription->refresh();
        $this->assertSame(UssdSubscription::STATUS_EXPIRED, $subscription->status);
        $this->assertNull($subscription->ussd_code);
        $this->assertNull($subscription->current_for_vendor_id);
        $this->assertFalse($vendor->fresh()->hasUssdAccess());
        $this->assertDatabaseHas('ussd_subscription_events', ['event' => UssdSubscriptionEvent::SUBSCRIPTION_EXPIRED]);

        $this->assertSame(0, app(UssdSubscriptionService::class)->expireDue(), 'expireDue must be idempotent.');
    }

    public function test_purchase_service_rejects_an_inactive_plan_at_the_service_layer(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = $this->starter();
        $plan->update(['is_active' => false]);

        $result = app(UssdSubscriptionPurchaseService::class)->initiate($vendor, $plan);

        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('ussd_subscriptions', 0);
    }
}
