<?php

namespace Tests\Feature\UtilityBills;

use App\Models\User;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin Utility Bill Sales search/filter correctness on a synthetic dataset, plus bounded query
 * counts. (Query plans and timings were verified separately against MySQL with 200k sales and
 * 1M marketplace orders; SQLite timings prove nothing about MySQL and are not asserted here.)
 */
class UtilityBillAdminSearchTest extends UtilityBillTestCase
{
    private Vendor $vendorA;

    private Vendor $vendorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendorA = Vendor::factory()->create(['is_approved' => true, 'name' => 'Alpha Store']);
        $this->vendorB = Vendor::factory()->create(['is_approved' => true, 'name' => 'Beta Store']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    /** Bulk-insert $n sales (and their envelope orders) quickly; $override applies to every row. */
    private function seedSales(int $n, array $override = [], ?callable $each = null): void
    {
        $orderId = (int) DB::table('orders')->max('id');
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $id = ++$orderId;
            $row = array_merge([
                'pay' => $i % 10 === 0 ? 'failed' : ($i % 7 === 0 ? 'unpaid' : 'paid'),
                'ful' => $i % 10 === 0 || $i % 7 === 0 ? 'awaiting_payment' : ($i % 13 === 0 ? 'attention' : 'completed'),
                'vendor_id' => [$this->vendorA->id, $this->vendorB->id, null][$i % 3],
                'biller' => ['dstv', 'ecg', 'gotv'][$i % 3 === 0 ? 0 : ($i % 2 === 0 ? 1 : 2)],
                'created_at' => Carbon::parse('2026-09-01 08:00:00')->addMinutes($i * 17),
                'public_ref' => 'UB'.strtoupper(Str::random(10)),
                'account_number' => (string) (7000000000 + $i * 37),
                'account_name' => ['KWAME MENSAH', 'AMA SERWAA', 'KOFI BOATENG'][$i % 3],
            ], $override, $each ? $each($i) : []);
            $rows[] = $row + ['id' => $id];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('orders')->insert(array_map(fn ($r) => [
                'id' => $r['id'], 'recipient_phone_number' => '0551617309', 'mobile_money_number' => '0551617309',
                'service_purchased' => 'Utility Bill', 'amount_paid' => '50.00', 'base_price' => '50.00', 'markup_price' => '0.00',
                'expected_amount' => '50.00', 'currency' => 'GHS', 'vendor_id' => null, 'status' => 'Pending', 'payment_status' => $r['pay'],
                'payment_gateway' => 'paystack', 'payment_reference' => 'PSK-'.$r['id'], 'payment_integrity_status' => $r['pay'] === 'paid' ? 'verified' : 'pending_verification',
                'created_at' => $r['created_at'], 'updated_at' => $r['created_at'],
            ], $chunk));
            DB::table('utility_bill_orders')->insert(array_map(fn ($r) => [
                'public_ref' => $r['public_ref'], 'access_token' => Str::random(40), 'order_id' => $r['id'], 'vendor_id' => $r['vendor_id'],
                'biller_key' => $r['biller'], 'biller_label' => strtoupper($r['biller']), 'account_number' => $r['account_number'],
                'account_name' => $r['account_name'], 'bill_amount' => '50.00', 'expected_amount' => '50.00', 'currency' => 'GHS',
                'fulfillment_status' => $r['ful'], 'provider_request_reference' => $r['ful'] === 'completed' ? 'XU-'.$r['public_ref'].'-A1' : null,
                'provider_order_reference' => $r['ful'] === 'completed' ? 'UTIL-'.strtoupper($r['biller']).'-'.strtolower($r['public_ref']) : null,
                'commission_basis' => 'bill_face_value', 'commission_basis_amount' => '0.00', 'commission_amount' => '0.00', 'commission_status' => 'none',
                'created_at' => $r['created_at'], 'updated_at' => $r['created_at'],
            ], $chunk));
        }
    }

    private function refs(array $params): array
    {
        $html = $this->get(route('admin.utility-bill-sales.index', $params))->assertOk()->getContent();
        preg_match_all('#/admin/utility-bill-sales/\d+">(UB[A-Z0-9]{10})<#', $html, $m);

        return $m[1];
    }

    private function expected(callable $where, int $limit = 30): array
    {
        return $where(UtilityBillOrder::query())->orderByDesc('id')->limit($limit)->pluck('public_ref')->all();
    }

    public function test_reference_and_account_searches_find_the_right_sale(): void
    {
        $this->seedSales(300);
        $target = UtilityBillOrder::query()->where('fulfillment_status', 'completed')->orderBy('id')->skip(40)->firstOrFail();
        $payRef = DB::table('orders')->where('id', $target->order_id)->value('payment_reference');

        foreach ([
            $target->public_ref,                                    // XTRA4U reference
            strtolower($target->public_ref),                        // case-insensitive
            substr($target->public_ref, 0, 9),                      // starts with
            $target->provider_order_reference,                      // provider's reference
            $target->provider_request_reference,                    // our provider request reference
            $target->account_number,                                // account number
            $payRef,                                                // gateway payment reference (exact)
        ] as $q) {
            $this->assertContains($target->public_ref, $this->refs(['q' => $q]), "search for {$q}");
        }

        // A full reference finds exactly that sale.
        $this->assertSame([$target->public_ref], $this->refs(['q' => $target->public_ref]));
        $this->assertSame([$target->public_ref], $this->refs(['q' => $target->provider_order_reference]));
    }

    public function test_default_search_is_starts_with_and_match_anywhere_keeps_the_substring_search(): void
    {
        $this->seedSales(120);
        $target = UtilityBillOrder::query()->orderBy('id')->skip(10)->firstOrFail();
        $middle = substr($target->account_number, 3, 5);

        $this->assertNotContains($target->public_ref, $this->refs(['q' => $middle]));
        $this->assertContains($target->public_ref, $this->refs(['q' => $middle, 'match' => 'contains']));

        // Names: starts with by default, anywhere on request.
        $this->assertSame($this->expected(fn ($q) => $q->where('account_name', 'like', 'AMA%')), $this->refs(['q' => 'AMA']));
        $this->assertSame([], $this->refs(['q' => 'SERWAA']));
        $this->assertSame($this->expected(fn ($q) => $q->where('account_name', 'like', '%SERWAA%')), $this->refs(['q' => 'SERWAA', 'match' => 'contains']));

        // LIKE wildcards in the input are literal characters, never "match everything".
        $this->assertSame([], $this->refs(['q' => '%']));
        $this->assertSame([], $this->refs(['q' => '_', 'match' => 'contains']));
    }

    public function test_filters_combine_correctly(): void
    {
        $this->seedSales(400);

        $cases = [
            [['vendor_id' => (string) $this->vendorA->id], fn ($q) => $q->where('vendor_id', $this->vendorA->id)],
            [['vendor_id' => 'direct'], fn ($q) => $q->whereNull('vendor_id')],
            [['biller' => 'ecg'], fn ($q) => $q->where('biller_key', 'ecg')],
            [['fulfillment' => 'attention'], fn ($q) => $q->where('fulfillment_status', 'attention')],
            [['payment' => 'paid'], fn ($q) => $q->whereHas('order', fn ($o) => $o->whereIn('payment_status', ['paid', 'completed']))],
            [['payment' => 'failed'], fn ($q) => $q->whereHas('order', fn ($o) => $o->where('payment_status', 'failed'))],
            [['payment' => 'unpaid'], fn ($q) => $q->whereHas('order', fn ($o) => $o->whereIn('payment_status', ['unpaid', 'pending']))],
            [['vendor_id' => (string) $this->vendorB->id, 'biller' => 'gotv', 'payment' => 'paid'], fn ($q) => $q->where('vendor_id', $this->vendorB->id)->where('biller_key', 'gotv')
                ->whereHas('order', fn ($o) => $o->whereIn('payment_status', ['paid', 'completed']))],
        ];

        foreach ($cases as [$params, $where]) {
            $expected = $this->expected($where);
            $this->assertNotEmpty($expected, json_encode($params));
            $this->assertSame($expected, $this->refs($params), json_encode($params));
        }
    }

    public function test_date_range_is_whole_days_inclusive(): void
    {
        $this->seedSales(3, [], fn ($i) => ['created_at' => Carbon::parse(['2026-09-10 23:59:59', '2026-09-11 00:00:00', '2026-09-09 00:00:00'][$i - 1])]);
        [$late, $nextDay, $start] = UtilityBillOrder::query()->orderBy('id')->pluck('public_ref')->all();

        $this->assertSame([$start, $late], $this->refs(['from' => '2026-09-09', 'to' => '2026-09-10']));   // newest id first
        $this->assertSame([$nextDay], $this->refs(['from' => '2026-09-11', 'to' => '2026-09-11']));
        $this->assertSame([$nextDay, $late], $this->refs(['from' => '2026-09-10']));
    }

    public function test_pagination_is_server_side_with_exact_or_capped_totals_and_bounded_queries(): void
    {
        $this->seedSales(1150, ['pay' => 'paid', 'ful' => 'completed']);

        // Exact total for index-only filters; page 2 is the next 30 newest.
        $this->get(route('admin.utility-bill-sales.index'))->assertSee('1,150 sales');
        $this->assertSame($this->expected(fn ($q) => $q->skip(30)), $this->refs(['page' => 2]));

        // The payment filter needs the envelope order: counted up to 1,000.
        $this->get(route('admin.utility-bill-sales.index', ['payment' => 'paid']))->assertSee('1,000+ sales')->assertSee('narrow by date');
        $this->assertSame($this->expected(fn ($q) => $q->skip(60)), $this->refs(['payment' => 'paid', 'page' => 3]));

        // Query count does not grow with the data (no N+1 over the 30 rows on the page).
        $count = function (array $params) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('admin.utility-bill-sales.index', $params))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->assertLessThanOrEqual(25, $full = $count([]));
        $this->assertLessThanOrEqual($full + 1, $count(['q' => 'UBNOTHINGXX']));   // + the payment-reference lookup
        $this->assertLessThanOrEqual(25, $count(['payment' => 'paid', 'q' => 'UB']));
    }

    public function test_the_list_masks_accounts_and_is_admin_only(): void
    {
        $this->seedSales(5);
        $u = UtilityBillOrder::query()->firstOrFail();

        $this->get(route('admin.utility-bill-sales.index'))->assertOk()
            ->assertDontSee($u->account_number)->assertSee(UtilityBillOrder::mask($u->account_number), false);

        auth()->logout();
        $this->get(route('admin.utility-bill-sales.index'))->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'vendor']));
        $this->get(route('admin.utility-bill-sales.index', ['q' => $u->account_number]))->assertStatus(403);
    }
}
