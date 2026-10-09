<?php

namespace Tests\Feature\UtilityBills;

use App\Models\User;
use App\Models\UtilityBillConfigAudit;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class UtilityBillVendorAndAdminTest extends UtilityBillTestCase
{
    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Signed in on the vendor guard ONLY, like a real vendor session. (actingAs(..., 'vendor')
     * would also make 'vendor' the default guard, which real requests never do.)
     */
    private function asVendorOnly(Vendor $vendor): void
    {
        \Illuminate\Support\Facades\Auth::guard('vendor')->setUser($vendor);
        \Illuminate\Support\Facades\Auth::shouldUse('web');
    }

    private function sale(Vendor $vendor, array $o = []): UtilityBillOrder
    {
        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-'.uniqid()))]);

        return $this->makeOrder(array_merge(['vendor' => $vendor, 'paid' => true], $o));
    }

    // ---- vendor --------------------------------------------------------

    public function test_vendor_sees_only_their_own_sales_and_precise_metrics(): void
    {
        $a = Vendor::factory()->create(['is_approved' => true]);
        $b = Vendor::factory()->create(['is_approved' => true]);

        $own = $this->sale($a, ['amount' => '100.00']);
        $this->fake([self::BASE.'/utilities/orders/*' => Http::response($this->statusBody('completed', $own->fresh()->provider_order_reference))]);
        app(\App\Services\UtilityBills\UtilityBillFulfillmentService::class)->syncStatus($own->id);
        $pending = $this->sale($a, ['amount' => '40.00']);
        $theirs = $this->sale($b, ['amount' => '55.55']);
        // An abandoned, unpaid checkout is not a sale.
        $unpaid = $this->makeOrder(['vendor' => $a]);

        $r = $this->actingAs($a, 'vendor')->get(route('vendor.utility-bills.index'));

        $r->assertOk()
            ->assertSee($own->public_ref)->assertSee($pending->public_ref)
            ->assertDontSee($theirs->public_ref)->assertDontSee($unpaid->public_ref)
            ->assertSee('GHS 100.00')          // sales value (completed only)
            ->assertSee('GHS 1.00')            // commission earned (1% of 100), a different number
            ->assertDontSee('7041234567')      // account never shown in full
            ->assertSee('••••4567', false);

        $this->assertSame('1.00', (string) $a->fresh()->wallet_balance);
        $this->assertSame('0.00', (string) $b->fresh()->wallet_balance);
    }

    public function test_vendor_cannot_open_another_vendors_sale_by_guessing_the_reference(): void
    {
        $a = Vendor::factory()->create(['is_approved' => true]);
        $b = Vendor::factory()->create(['is_approved' => true]);
        $theirs = $this->sale($b);
        $mine = $this->sale($a);

        $this->actingAs($a, 'vendor')->get(route('vendor.utility-bills.show', $theirs->public_ref))->assertNotFound();
        $this->actingAs($a, 'vendor')->get(route('vendor.utility-bills.show', $mine->public_ref))->assertOk()->assertDontSee('KWAME MENSAH');
        // Even a direct (vendor-less) sale is invisible to any vendor.
        $direct = $this->sale(Vendor::factory()->create(), ['vendor' => null]);
        $this->actingAs($a, 'vendor')->get(route('vendor.utility-bills.show', $direct->public_ref))->assertNotFound();
    }

    public function test_vendor_cannot_reach_admin_configuration_or_change_commission(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->openService(['dstv']);
        $this->asVendorOnly($vendor);

        $this->get(route('admin.utility-bills.settings'))->assertRedirect(route('admin.login'));
        $this->put(route('admin.utility-bills.settings.update'), ['enabled' => 1, 'billers' => ['dstv' => ['is_enabled' => 1, 'commission_type' => 'fixed', 'commission_value' => '50']]])
            ->assertRedirect(route('admin.login'));
        $this->get(route('admin.utility-bill-sales.index'))->assertRedirect(route('admin.login'));

        $this->assertSame('1.0000', (string) UtilityBillerConfig::where('biller_key', 'dstv')->value('commission_value'));
        $this->assertSame(0, UtilityBillConfigAudit::count());
    }

    public function test_vendor_pages_never_expose_provider_credentials(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $sale = $this->sale($vendor);

        foreach ([route('vendor.utility-bills.index'), route('vendor.utility-bills.show', $sale->public_ref)] as $url) {
            $html = $this->actingAs($vendor, 'vendor')->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('kf_cs_', $html);
            $this->assertStringNotContainsString('UTIL-DSTV', $html);          // provider reference
            $this->assertStringNotContainsString('XU-'.$sale->public_ref, $html); // our provider request reference
        }
    }

    public function test_guests_cannot_open_vendor_sales(): void
    {
        $this->get(route('vendor.utility-bills.index'))->assertRedirect(route('vendor.login.form'));
    }

    // ---- admin config --------------------------------------------------

    private function configPayload(array $biller = []): array
    {
        return [
            'enabled' => 1,
            'maintenance_message' => 'Back soon',
            'billers' => [
                'ecg' => $biller + ['is_enabled' => 1, 'commission_type' => 'percentage', 'commission_value' => '0.5'],
                'dstv' => ['is_enabled' => 1, 'commission_type' => 'fixed', 'commission_value' => '1'],
            ],
        ];
    }

    public function test_admin_saves_configuration_and_each_change_is_audited(): void
    {
        $admin = $this->admin();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        $this->put(route('admin.utility-bills.settings.update'), $this->configPayload())->assertRedirect(route('admin.utility-bills.settings'));

        $ecg = UtilityBillerConfig::where('biller_key', 'ecg')->first();
        $this->assertTrue($ecg->is_enabled);
        $this->assertSame('percentage', $ecg->commission_type);
        $this->assertSame('0.5000', (string) $ecg->commission_value);
        $this->assertSame('fixed', UtilityBillerConfig::where('biller_key', 'dstv')->value('commission_type'));
        $this->assertTrue(UtilityBillSettings::enabled());

        // Change one rate: one new audit row with old and new, attributed to the admin.
        UtilityBillConfigAudit::query()->delete();
        $this->put(route('admin.utility-bills.settings.update'), $this->configPayload(['commission_value' => '1.25']))->assertRedirect();

        $audit = UtilityBillConfigAudit::where('biller_key', 'ecg')->sole();
        $this->assertSame($admin->id, $audit->admin_id);
        $this->assertSame($admin->email, $audit->admin_email);
        $this->assertSame('0.5000', $audit->old_values['commission_value']);
        $this->assertSame('1.2500', $audit->new_values['commission_value']);
        $this->assertSame(1, UtilityBillConfigAudit::count());   // unchanged rows are not audited

        $this->get(route('admin.utility-bills.settings'))->assertOk()->assertSee('ECG Prepaid')->assertDontSee('kf_cs_live_');
    }

    public function test_admin_validation_rejects_bad_commission_and_unknown_billers(): void
    {
        $this->admin();
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);

        foreach ([
            ['commission_type' => 'percentage', 'commission_value' => '-1'],
            ['commission_type' => 'percentage', 'commission_value' => '21'],
            ['commission_type' => 'fixed', 'commission_value' => '101'],
            ['commission_type' => 'fixed', 'commission_value' => '1.234'],
            ['commission_type' => 'bogus', 'commission_value' => '1'],
            ['commission_type' => 'percentage', 'commission_value' => 'abc'],
        ] as $bad) {
            $this->put(route('admin.utility-bills.settings.update'), $this->configPayload($bad))->assertSessionHasErrors();
        }

        $payload = $this->configPayload();
        $payload['billers']['made_up'] = ['is_enabled' => 1, 'commission_type' => 'fixed', 'commission_value' => '1'];
        $this->put(route('admin.utility-bills.settings.update'), $payload)->assertSessionHasErrors('billers');

        $this->assertSame(0, UtilityBillerConfig::count());
    }

    public function test_admin_cannot_make_a_provider_disabled_biller_sellable(): void
    {
        $this->admin();
        $body = $this->billersBody();
        $body['data']['billers'][0]['enabled'] = false; // ECG disabled by provider
        $this->fake([self::BASE.'/utilities/billers' => Http::response($body)]);

        $this->put(route('admin.utility-bills.settings.update'), $this->configPayload())->assertRedirect();

        $this->assertTrue(UtilityBillerConfig::where('biller_key', 'ecg')->value('is_enabled'));
        $this->get('/services/utility-bills')->assertOk()->assertDontSee('ECG Prepaid');
        $this->get(route('admin.utility-bills.settings'))->assertSee('Cannot be sold until the provider enables it');
    }

    // ---- admin sales / recovery ---------------------------------------

    public function test_admin_sees_all_sales_and_filters_work(): void
    {
        $this->admin();
        $this->openService(['dstv', 'gotv']);   // configured billers also feed the filter list
        $a = Vendor::factory()->create(['is_approved' => true, 'name' => 'Alpha Store']);
        $b = Vendor::factory()->create(['is_approved' => true, 'name' => 'Beta Store']);
        $sa = $this->sale($a);
        $sb = $this->sale($b, ['biller' => 'ecg']);
        $sd = $this->sale($a, ['vendor' => null]);
        $unpaid = $this->makeOrder(['vendor' => $b]);

        $this->get(route('admin.utility-bill-sales.index'))->assertOk()
            ->assertSee($sa->public_ref)->assertSee($sb->public_ref)->assertSee($sd->public_ref)->assertSee($unpaid->public_ref);

        $this->get(route('admin.utility-bill-sales.index', ['vendor_id' => $a->id]))->assertSee($sa->public_ref)->assertDontSee($sb->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['vendor_id' => 'direct']))->assertSee($sd->public_ref)->assertDontSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['biller' => 'ecg']))->assertSee($sb->public_ref)->assertDontSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['payment' => 'unpaid']))->assertSee($unpaid->public_ref)->assertDontSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['payment' => 'paid']))->assertDontSee($unpaid->public_ref)->assertSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['fulfillment' => FulfillmentStatus::AWAITING_PAYMENT]))->assertSee($unpaid->public_ref)->assertDontSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['q' => $sb->public_ref]))->assertSee($sb->public_ref)->assertDontSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.index', ['from' => now()->addDay()->toDateString()]))->assertDontSee($sa->public_ref);
        $this->get(route('admin.utility-bill-sales.show', $sa))->assertOk()->assertSee($sa->provider_request_reference)->assertSee('Timeline');
    }

    public function test_admin_retry_recovers_a_paid_unfulfilled_order_once(): void
    {
        $this->admin();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->fake([self::BASE.'/utilities/pay' => Http::response(['success' => false, 'message' => 'Insufficient wallet balance'], 400)]);
        $u = $this->makeOrder(['vendor' => $vendor, 'paid' => true])->refresh();
        $this->assertSame(FulfillmentStatus::ATTENTION, $u->fulfillment_status);

        $this->fake([self::BASE.'/utilities/pay' => Http::response($this->payBody('UTIL-DSTV-RECOVERED'))]);
        $this->post(route('admin.utility-bill-sales.retry', $u))->assertSessionHas('success');
        $this->post(route('admin.utility-bill-sales.retry', $u))->assertSessionHas('error');   // double click

        $this->assertSame(1, Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/utilities/pay'))->count());
        $this->assertSame('UTIL-DSTV-RECOVERED', $u->fresh()->provider_order_reference);
    }

    public function test_new_attempt_requires_confirmation_checkbox(): void
    {
        $this->admin();
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $u = $this->sale($vendor);
        $u->forceFill(['fulfillment_status' => FulfillmentStatus::PROVIDER_REFUNDED, 'provider_status' => 'refunded'])->save();

        $this->post(route('admin.utility-bill-sales.new-attempt', $u), ['reason' => 'because'])->assertSessionHasErrors('confirm');
        $this->post(route('admin.utility-bill-sales.new-attempt', $u), ['confirm' => 1])->assertSessionHasErrors('reason');
        $this->assertSame(1, $u->fresh()->provider_attempt);
    }

    public function test_retry_endpoints_require_an_admin(): void
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $u = $this->sale($vendor);

        $this->post(route('admin.utility-bill-sales.retry', $u))->assertRedirect(route('admin.login'));
        $this->asVendorOnly($vendor);
        $this->post(route('admin.utility-bill-sales.new-attempt', $u), ['confirm' => 1])->assertRedirect(route('admin.login'));
        $this->assertSame(1, $u->fresh()->provider_attempt);
    }
}
