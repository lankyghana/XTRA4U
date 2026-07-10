<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\Vendor;
use App\Services\Ussd\UssdConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UssdSubscriptionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_plans_are_seeded(): void
    {
        $this->assertSame(3, UssdPlan::count());

        $starter = UssdPlan::where('extension_code', '45')->firstOrFail();
        $this->assertSame('Starter', $starter->name);
        $this->assertSame(5000, $starter->included_sessions);
        $this->assertSame(80.0, $starter->price);
        $this->assertTrue($starter->isPurchasable());
    }

    public function test_plan_extension_codes_are_unique(): void
    {
        $this->expectException(QueryException::class);

        UssdPlan::create([
            'name' => 'Duplicate',
            'price' => 10,
            'included_sessions' => 100,
            'duration_days' => 30,
            'extension_code' => '45',
        ]);
    }

    public function test_ussd_code_is_built_from_base_extension_and_vendor_id(): void
    {
        $this->assertSame('*203*45*102#', UssdSubscription::buildUssdCode('*203*', '45', 102));

        // Tolerates a base code entered with or without the trailing separator.
        $this->assertSame('*203*45*102#', UssdSubscription::buildUssdCode('*203', '45', 102));
        $this->assertSame('*203*45*102#', UssdSubscription::buildUssdCode('*203*#', '45', 102));
    }

    public function test_a_vendor_cannot_hold_two_live_subscriptions(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $this->makeSubscription($vendor, $plan, [
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
        ]);

        $this->expectException(QueryException::class);

        $this->makeSubscription($vendor, $plan, [
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
        ]);
    }

    public function test_released_slot_allows_a_new_live_subscription(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $old = $this->makeSubscription($vendor, $plan, [
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
        ]);

        $old->update([
            'status' => UssdSubscription::STATUS_EXPIRED,
            'current_for_vendor_id' => null,
        ]);

        $new = $this->makeSubscription($vendor, $plan, [
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
        ]);

        $this->assertSame($new->id, $vendor->fresh()->currentUssdSubscription->id);
    }

    public function test_usage_accounting_and_alert_thresholds(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $subscription = $this->makeSubscription($vendor, $plan, [
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'total_sessions' => 1000,
            'used_sessions' => 810,
            'expires_at' => now()->addDays(10),
        ]);

        $this->assertSame(190, $subscription->remaining_sessions);
        $this->assertSame(81.0, $subscription->usage_percentage);
        $this->assertTrue($subscription->isUsable());
        $this->assertSame([80], $subscription->pendingUsageAlerts());

        $subscription->markThresholdNotified(80);
        $this->assertSame([], $subscription->fresh()->pendingUsageAlerts());

        // Exhausted subscriptions are not usable, and never report negative remaining.
        $subscription->update(['used_sessions' => 1000]);
        $subscription->refresh();
        $this->assertSame(0, $subscription->remaining_sessions);
        $this->assertFalse($subscription->isUsable());
        $this->assertFalse($vendor->fresh()->hasUssdAccess());
    }

    public function test_expired_subscription_is_not_usable(): void
    {
        $vendor = Vendor::factory()->create();
        $plan = UssdPlan::where('extension_code', '45')->firstOrFail();

        $subscription = $this->makeSubscription($vendor, $plan, [
            'status' => UssdSubscription::STATUS_ACTIVE,
            'current_for_vendor_id' => $vendor->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($subscription->isExpired());
        $this->assertFalse($subscription->isUsable());
        $this->assertTrue(UssdSubscription::dueForExpiry()->whereKey($subscription->id)->exists());
    }

    public function test_ussd_config_reads_seeded_settings(): void
    {
        $config = app(UssdConfig::class);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('*203*', $config->baseCode());
        $this->assertSame('moolre', $config->provider());
        $this->assertSame(240, $config->sessionTimeoutSeconds());
        $this->assertSame(20, $config->maxRequestsPerSession());
        $this->assertSame(3, $config->maxRetryAttempts());
        $this->assertSame([], $config->gatewayIpAllowlist());
        $this->assertFalse($config->hasGatewaySecret());
    }

    public function test_ussd_config_falls_back_to_legacy_service_code(): void
    {
        Setting::set('ussd_base_code', '', 'ussd');
        Setting::set('ussd_service_code', '*714*5#', 'ussd');
        Setting::clearCache();

        $this->assertSame('*714*5#', app(UssdConfig::class)->baseCode());
    }

    public function test_gateway_secret_is_stored_encrypted(): void
    {
        Setting::set('ussd_gateway_secret', \Illuminate\Support\Facades\Crypt::encryptString('a-very-long-secret'), 'ussd');
        Setting::clearCache();

        $raw = Setting::where('key', 'ussd_gateway_secret')->value('value');
        $this->assertNotSame('a-very-long-secret', $raw);
        $this->assertSame('a-very-long-secret', app(UssdConfig::class)->gatewaySecret());
    }

    private function makeSubscription(Vendor $vendor, UssdPlan $plan, array $overrides = []): UssdSubscription
    {
        return UssdSubscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'ussd_plan_id' => $plan->id,
            'status' => UssdSubscription::STATUS_PENDING_PAYMENT,
            'extension_code' => $plan->extension_code,
            'price_paid' => $plan->price,
            'total_sessions' => $plan->included_sessions,
            'payment_reference' => \Illuminate\Support\Str::uuid()->toString(),
        ], $overrides));
    }
}
