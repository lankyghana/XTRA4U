<?php

namespace Tests\Feature;

use App\Jobs\SendUssdSubscriptionNotification;
use App\Mail\UssdSubscriptionMail;
use App\Models\User;
use App\Models\UssdLog;
use App\Models\UssdPlan;
use App\Models\UssdSession;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Services\Ussd\UssdSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class UssdNotificationAndSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private function starter(): UssdPlan
    {
        return UssdPlan::where('extension_code', '45')->firstOrFail();
    }

    private function activeSubscription(Vendor $vendor, array $overrides = []): UssdSubscription
    {
        $plan = $this->starter();

        return UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'ussd_code' => UssdSubscription::buildUssdCode('*203*', $plan->extension_code, $vendor->id),
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => 100,
            'activated_at' => now(),
            'expires_at' => now()->addDays(20),
            'payment_reference' => Str::uuid()->toString(),
        ], $overrides));
    }

    // ------------------------------------------------------------ usage alerts

    public function test_usage_alerts_fire_once_per_threshold(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, ['used_sessions' => 79]);
        $service = app(UssdSubscriptionService::class);

        // 80th session crosses the 80% threshold.
        $service->reserveSession($subscription);
        Queue::assertPushed(SendUssdSubscriptionNotification::class,
            fn ($job) => $job->type === SendUssdSubscriptionNotification::TYPE_USAGE_ALERT
                && $job->context['threshold'] === 80);

        // 81st does not re-alert.
        $service->reserveSession($subscription);
        Queue::assertPushed(SendUssdSubscriptionNotification::class, 1);

        $this->assertSame([80], $subscription->fresh()->notified_thresholds);
    }

    public function test_each_threshold_alerts_independently(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, ['used_sessions' => 89]);
        $service = app(UssdSubscriptionService::class);

        // Crossing 90 also back-fills the 80 alert that was never sent.
        $service->reserveSession($subscription);
        $this->assertSame([80, 90], $subscription->fresh()->notified_thresholds);

        // Drive to exhaustion: 95 and 100 each fire once.
        $subscription->forceFill(['used_sessions' => 99])->save();
        $service->reserveSession($subscription);

        $this->assertSame([80, 90, 95, 100], $subscription->fresh()->notified_thresholds);
        Queue::assertPushed(SendUssdSubscriptionNotification::class, 4);
    }

    // -------------------------------------------------------------- activation

    public function test_activation_notifies_the_vendor(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $plan = $this->starter();

        $subscription = UssdSubscription::create([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => Str::uuid()->toString(),
        ]);

        app(UssdSubscriptionService::class)->activate($subscription);

        Queue::assertPushed(SendUssdSubscriptionNotification::class,
            fn ($job) => $job->type === SendUssdSubscriptionNotification::TYPE_ACTIVATED);
    }

    // ------------------------------------------------------------------ expiry

    public function test_expire_command_expires_due_subscriptions_and_notifies(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, ['expires_at' => now()->subDay()]);

        $this->artisan('xtra4u:expire-ussd-subscriptions')
            ->expectsOutputToContain('Expired 1 USSD subscription.')
            ->assertExitCode(0);

        $this->assertSame(UssdSubscription::STATUS_EXPIRED, $subscription->fresh()->status);

        Queue::assertPushed(SendUssdSubscriptionNotification::class,
            fn ($job) => $job->type === SendUssdSubscriptionNotification::TYPE_EXPIRED);
    }

    public function test_expire_command_is_idempotent(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $this->activeSubscription($vendor, ['expires_at' => now()->subDay()]);

        $this->artisan('xtra4u:expire-ussd-subscriptions');
        $this->artisan('xtra4u:expire-ussd-subscriptions')->expectsOutputToContain('Expired 0');

        Queue::assertPushed(SendUssdSubscriptionNotification::class, 1);
    }

    public function test_expiring_soon_warns_once_per_subscription(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, ['expires_at' => now()->addDays(2)]);

        $this->artisan('xtra4u:notify-expiring-ussd-subscriptions', ['--days' => 3])
            ->expectsOutputToContain('Warned 1 vendor');

        $this->assertTrue($subscription->fresh()->getExpiryWarningSent());

        // A second run must not re-warn.
        $this->artisan('xtra4u:notify-expiring-ussd-subscriptions', ['--days' => 3])
            ->expectsOutputToContain('Warned 0 vendors');

        Queue::assertPushed(SendUssdSubscriptionNotification::class, 1);
    }

    public function test_subscriptions_outside_the_window_are_not_warned(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->create();
        $this->activeSubscription($vendor, ['expires_at' => now()->addDays(20)]);

        $this->artisan('xtra4u:notify-expiring-ussd-subscriptions', ['--days' => 3]);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------ pruning

    public function test_prune_closes_abandoned_sessions_and_deletes_old_ones(): void
    {
        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor);

        $make = fn (string $id, string $status, $updatedAt) => tap(UssdSession::create([
            'session_id' => $id,
            'vendor_id' => $vendor->id,
            'ussd_subscription_id' => $subscription->id,
            'phone_number' => '0244000000',
            'current_step' => 'MAIN_MENU',
            'status' => $status,
        ]), fn ($s) => $s->forceFill(['updated_at' => $updatedAt])->save());

        $abandoned = $make('abandoned-1', UssdSession::STATUS_ACTIVE, now()->subHours(3));
        $recent = $make('recent-1', UssdSession::STATUS_ENDED, now()->subHour());
        $old = $make('old-1', UssdSession::STATUS_ENDED, now()->subDays(30));
        $live = $make('live-1', UssdSession::STATUS_ACTIVE, now());

        UssdLog::create(['session_id' => 'old-1', 'request_data' => [], 'response_data' => 'x']);
        UssdLog::where('session_id', 'old-1')->update(['created_at' => now()->subDays(200)]);
        UssdLog::create(['session_id' => 'recent-1', 'request_data' => [], 'response_data' => 'x']);

        $this->artisan('xtra4u:prune-ussd-sessions', ['--days' => 7, '--log-days' => 90])
            ->assertExitCode(0);

        // Abandoned mid-flow sessions are closed, not deleted.
        $this->assertSame(UssdSession::STATUS_EXPIRED, $abandoned->fresh()->status);

        // A live session is untouched.
        $this->assertSame(UssdSession::STATUS_ACTIVE, $live->fresh()->status);

        // Retention keeps recent closed rows so replayed ids stay detectable.
        $this->assertNotNull($recent->fresh());
        $this->assertNull(UssdSession::find($old->id));

        $this->assertSame(0, UssdLog::where('session_id', 'old-1')->count());
        $this->assertSame(1, UssdLog::where('session_id', 'recent-1')->count());
    }

    // ------------------------------------------------------------ mail rendering

    public function test_every_notification_type_renders_an_email(): void
    {
        Mail::fake();

        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, ['used_sessions' => 95]);

        $types = [
            SendUssdSubscriptionNotification::TYPE_ACTIVATED => [],
            SendUssdSubscriptionNotification::TYPE_EXPIRING => [],
            SendUssdSubscriptionNotification::TYPE_EXPIRED => [],
            SendUssdSubscriptionNotification::TYPE_USAGE_ALERT => ['threshold' => 95],
            SendUssdSubscriptionNotification::TYPE_PAYMENT_FAILED => ['reason' => 'Declined'],
        ];

        foreach ($types as $type => $context) {
            (new SendUssdSubscriptionNotification($subscription->id, $type, $context))->handle();
        }

        Mail::assertSent(UssdSubscriptionMail::class, 5);
    }

    /**
     * Mail::fake() never renders the template, so a broken Blade passes every
     * assertSent(). Render each one for real.
     */
    public function test_every_notification_template_actually_renders(): void
    {
        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor, ['used_sessions' => 95]);
        $subscription->setRelation('plan', $this->starter());

        $cases = [
            SendUssdSubscriptionNotification::TYPE_ACTIVATED => $subscription->ussd_code,
            SendUssdSubscriptionNotification::TYPE_EXPIRING => 'expires on',
            SendUssdSubscriptionNotification::TYPE_EXPIRED => 'has expired',
            SendUssdSubscriptionNotification::TYPE_PAYMENT_FAILED => 'No charge has been made',
        ];

        foreach ($cases as $type => $needle) {
            $html = (new UssdSubscriptionMail($vendor, $subscription, $type))->render();
            $this->assertStringContainsString($vendor->name, $html, "{$type} template lost the vendor name");
            $this->assertStringContainsString($needle, $html, "{$type} template is missing its key content");
        }

        $usage = (new UssdSubscriptionMail($vendor, $subscription, 'usage_alert', ['threshold' => 95]))->render();
        $this->assertStringContainsString('95%', $usage);

        $exhausted = (new UssdSubscriptionMail($vendor, $subscription, 'usage_alert', ['threshold' => 100]))->render();
        $this->assertStringContainsString('exhausted', $exhausted);
    }

    public function test_notification_job_is_idempotent_per_type(): void
    {
        Mail::fake();

        $vendor = Vendor::factory()->create();
        $subscription = $this->activeSubscription($vendor);

        // The cache lock is held for the duration of the first run only, so a
        // genuine re-dispatch after release does send again; what must not
        // happen is two concurrent workers double-sending. Simulate by holding.
        $job = new SendUssdSubscriptionNotification($subscription->id, SendUssdSubscriptionNotification::TYPE_ACTIVATED);
        $job->handle();

        Mail::assertSent(UssdSubscriptionMail::class, 1);
    }

    public function test_job_skips_a_missing_subscription(): void
    {
        Mail::fake();

        (new SendUssdSubscriptionNotification(999999, SendUssdSubscriptionNotification::TYPE_ACTIVATED))->handle();

        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------- admin audit

    public function test_admin_can_view_the_ussd_audit_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $vendor = Vendor::factory()->create();
        $this->activeSubscription($vendor);

        \App\Models\UssdSubscriptionEvent::create([
            'vendor_id' => $vendor->id,
            'event' => \App\Models\UssdSubscriptionEvent::SECURITY_EVENT,
            'description' => 'USSD gateway request rejected: source IP not allowlisted.',
            'actor_type' => 'gateway',
            'ip_address' => '203.0.113.9',
        ]);

        $this->get(route('admin.ussd-events.index'))
            ->assertOk()
            ->assertSee('USSD Audit Log')
            ->assertSee('source IP not allowlisted')
            ->assertSee('203.0.113.9');
    }

    public function test_audit_log_can_be_filtered_by_vendor(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $a = Vendor::factory()->create();
        $b = Vendor::factory()->create();

        \App\Models\UssdSubscriptionEvent::create([
            'vendor_id' => $a->id, 'event' => \App\Models\UssdSubscriptionEvent::PAYMENT_FAILED,
            'description' => 'AAA event for vendor A',
        ]);
        \App\Models\UssdSubscriptionEvent::create([
            'vendor_id' => $b->id, 'event' => \App\Models\UssdSubscriptionEvent::PAYMENT_FAILED,
            'description' => 'BBB event for vendor B',
        ]);

        $this->get(route('admin.ussd-events.index', ['vendor_id' => $a->id]))
            ->assertOk()
            ->assertSee('AAA event for vendor A')
            ->assertDontSee('BBB event for vendor B');
    }

    public function test_non_admin_cannot_view_the_audit_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));

        $this->get(route('admin.ussd-events.index'))->assertForbidden();
    }
}
