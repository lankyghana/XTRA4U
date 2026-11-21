<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Multi-vendor isolation. Ownership is enforced in the SQL of every tool (the vendor id from the trusted ToolContext is
 * a condition of the same query as the record id), never by fetching a record and checking it afterwards.
 */
class MultiVendorIsolationTest extends VendorAssistantTestCase
{
    public function test_each_vendor_sees_only_its_own_dashboard_figures(): void
    {
        $w = $this->world();

        $a = $this->runTool('vendor_get_dashboard_summary', ['period' => 'today'], $this->vendorContext($w['a']))->data['totals'];
        $b = $this->runTool('vendor_get_dashboard_summary', ['period' => 'today'], $this->vendorContext($w['b']))->data['totals'];
        $c = $this->runTool('vendor_get_dashboard_summary', ['period' => 'all_time'], $this->vendorContext($w['c']))->data['totals'];

        $this->assertSame(['sales' => '165.00', 'earnings' => '141.40', 'commissions' => '2.00'], $a);
        $this->assertSame(['sales' => '7777.00', 'earnings' => '7621.46', 'commissions' => '155.54'], $b);
        // C is only the provider side of the reseller AFA registration.
        $this->assertSame(['sales' => '40.00', 'earnings' => '39.20', 'commissions' => '0.00'], $c);
    }

    public function test_earnings_of_one_vendor_never_appear_in_another_vendors_response(): void
    {
        $w = $this->world();

        $forA = $this->payload($this->runTool('vendor_get_earnings_summary', ['period' => 'all_time'], $this->vendorContext($w['a'])));
        $forB = $this->payload($this->runTool('vendor_get_earnings_summary', ['period' => 'all_time'], $this->vendorContext($w['b']), 'c2'));

        foreach (['7777.00', '7621.46', '155.54'] as $bOnly) {
            $this->assertStringNotContainsString($bOnly, $forA, "B's figure {$bOnly} leaked into A's response");
        }
        foreach (['1865.00', '1768.40', '980.00', '392.00'] as $aOnly) {
            $this->assertStringNotContainsString($aOnly, $forB, "A's figure {$aOnly} leaked into B's response");
        }
    }

    public function test_a_vendor_cannot_retrieve_another_vendors_order_and_the_answer_equals_a_nonexistent_order(): void
    {
        $w = $this->world();
        $aOrder = $this->makeOrder(['vendor_id' => $w['a']->id, 'service_purchased' => 'A-ONLY-ORDER']);
        $bOrder = $this->makeOrder(['vendor_id' => $w['b']->id, 'service_purchased' => 'B-ONLY-ORDER']);

        // each vendor can read their own ...
        $this->assertTrue($this->runTool('vendor_get_my_order', ['order_ref' => $aOrder->id], $this->vendorContext($w['a']))->data['found']);
        $this->assertTrue($this->runTool('vendor_get_my_order', ['order_ref' => $bOrder->id], $this->vendorContext($w['b']))->data['found']);

        // ... and never the other's. Foreign and nonexistent are byte-identical.
        $foreignForB = $this->runTool('vendor_get_my_order', ['order_ref' => $aOrder->id], $this->vendorContext($w['b']));
        $foreignForA = $this->runTool('vendor_get_my_order', ['order_ref' => $bOrder->id], $this->vendorContext($w['a']), 'c2');
        $missing = $this->runTool('vendor_get_my_order', ['order_ref' => 987654], $this->vendorContext($w['a']), 'c3');

        $this->assertSame($missing->data, $foreignForA->data);
        $this->assertSame($missing->data, $foreignForB->data);
        $this->assertFalse($missing->data['found']);
        $this->assertStringNotContainsString('A-ONLY-ORDER', $this->payload($foreignForB));
        $this->assertStringNotContainsString('B-ONLY-ORDER', $this->payload($foreignForA));

        // search never returns them either
        $search = $this->runTool('vendor_search_my_orders', ['order_ref' => $aOrder->id], $this->vendorContext($w['b']), 'c4');
        $this->assertSame([], $search->data['orders']);
        $search = $this->runTool('vendor_search_my_orders', ['product' => 'A-ONLY'], $this->vendorContext($w['b']), 'c5');
        $this->assertSame([], $search->data['orders']);
    }

    public function test_ownership_is_part_of_the_orders_query_itself(): void
    {
        $w = $this->world();
        $order = $this->makeOrder(['vendor_id' => $w['a']->id]);

        DB::enableQueryLog();
        $this->runTool('vendor_get_my_order', ['order_ref' => $order->id], $this->vendorContext($w['b']));
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $orderQueries = array_values(array_filter($log, fn ($q) => preg_match('/from [`"]orders[`"]/i', $q['query']) === 1 && stripos($q['query'], 'select') === 0));
        $this->assertCount(1, $orderQueries, 'exactly one query reads orders: no fetch-then-check');

        [$query] = $orderQueries;
        $this->assertMatchesRegularExpression('/[`"]id[`"] = \?/', $query['query']);
        $this->assertMatchesRegularExpression('/[`"]vendor_id[`"] = \?/', $query['query']);
        $this->assertContains($order->id, $query['bindings']);
        $this->assertContains($w['b']->id, $query['bindings'], 'the trusted vendor id is bound in the same query');
    }

    public function test_products_are_scoped_to_the_vendors_own_catalogue(): void
    {
        $w = $this->world();
        $this->makeProduct($w['a'], 'A PRIVATE PRODUCT');
        $this->makeProduct($w['b'], 'B PRIVATE PRODUCT');
        $this->makeProduct($w['c'], 'C PRIVATE PRODUCT');

        $a = $this->payload($this->runTool('vendor_list_my_products', ['limit' => 20], $this->vendorContext($w['a'])));
        $b = $this->payload($this->runTool('vendor_list_my_products', ['limit' => 20], $this->vendorContext($w['b']), 'c2'));
        $c = $this->payload($this->runTool('vendor_list_my_products', ['limit' => 20], $this->vendorContext($w['c']), 'c3'));

        $this->assertStringContainsString('A PRIVATE PRODUCT', $a);
        $this->assertStringNotContainsString('B PRIVATE PRODUCT', $a);
        $this->assertStringNotContainsString('C PRIVATE PRODUCT', $a);

        // B resells A's "MTN 5GB" (a listing in B's own catalogue) but never sees A's other products.
        $this->assertStringContainsString('B PRIVATE PRODUCT', $b);
        $this->assertStringContainsString('MTN 5GB', $b);
        $this->assertStringNotContainsString('A PRIVATE PRODUCT', $b);
        $this->assertStringNotContainsString('C PRIVATE PRODUCT', $b);

        $this->assertStringContainsString('C PRIVATE PRODUCT', $c);
        $this->assertStringNotContainsString('MTN 5GB', $c, 'C neither owns nor resells it');
        $this->assertStringNotContainsString('A PRIVATE PRODUCT', $c);
    }

    public function test_reseller_and_provider_attribution_follows_the_orders_page_rules(): void
    {
        $w = $this->world();
        [$a, $b, $c] = [$w['a'], $w['b'], $w['c']];

        // B sold A's product: B is the seller, A the owner; C is uninvolved.
        $affiliate = $this->makeOrder(['vendor_id' => $b->id, 'owner_vendor_id' => $a->id, 'is_reseller_order' => true, 'reseller_product_id' => $w['listing']->id]);

        $seenByA = collect($this->runTool('vendor_search_my_orders', [], $this->vendorContext($a))->data['orders'])->keyBy('ref');
        $seenByB = collect($this->runTool('vendor_search_my_orders', [], $this->vendorContext($b))->data['orders'])->keyBy('ref');
        $seenByC = $this->runTool('vendor_search_my_orders', [], $this->vendorContext($c))->data['orders'];

        $this->assertSame('product_owner', $seenByA[$affiliate->id]['your_role']);
        $this->assertSame('seller', $seenByB[$affiliate->id]['your_role']);
        $this->assertTrue($seenByA[$affiliate->id]['is_affiliate_order']);
        $this->assertSame([], $seenByC);

        // The owner can read it by number too, and C cannot.
        $this->assertTrue($this->runTool('vendor_get_my_order', ['order_ref' => $affiliate->id], $this->vendorContext($a), 'x1')->data['found']);
        $this->assertFalse($this->runTool('vendor_get_my_order', ['order_ref' => $affiliate->id], $this->vendorContext($c), 'x2')->data['found']);
    }

    public static function identityArguments(): array
    {
        return [
            'vendor_id' => ['vendor_id'], 'vendorId' => ['vendorId'], 'vendor-id' => ['vendor-id'],
            'owner_id' => ['owner_id'], 'user_id' => ['user_id'], 'customer_id' => ['customer_id'],
            'actor_id' => ['actor_id'], 'account_id' => ['account_id'], 'reseller_id' => ['reseller_id'],
            'vendor' => ['vendor'], 'role' => ['role'], 'is_admin' => ['is_admin'], 'permissions' => ['permissions'],
            'surface' => ['surface'], 'context' => ['context'], 'scope' => ['scope'],
        ];
    }

    #[DataProvider('identityArguments')]
    public function test_client_supplied_identity_fields_are_rejected_by_every_tool(string $field): void
    {
        $w = $this->world();
        $context = $this->vendorContext($w['a']);

        foreach (VendorAssistant::TOOLS as $i => $tool) {
            $base = $tool === 'vendor_get_my_order' ? ['order_ref' => 1] : [];
            $result = $this->runTool($tool, $base + [$field => $w['b']->id], $context, 'call_'.$i);

            $this->assertSame('prohibited_argument', $result->errorCode, "{$tool} must refuse {$field}");
            $this->assertFalse($result->wasExecuted(), "{$tool} must not even run with {$field}");
        }
    }

    public function test_no_tool_declares_an_identity_or_ownership_parameter(): void
    {
        $registry = app(ToolRegistry::class);

        foreach (VendorAssistant::TOOLS as $name) {
            $declared = $registry->get($name)->schema()->parameterNames();

            foreach ($declared as $parameter) {
                $this->assertDoesNotMatchRegularExpression('/(^|_)(vendor|owner|user|customer|actor|account|tenant|reseller)(_id)?$/', $parameter, "{$name} declares {$parameter}");
            }
        }
    }

    public function test_the_trusted_context_wins_over_anything_in_the_model_turn(): void
    {
        $w = $this->world();
        $this->makeOrder(['vendor_id' => $w['b']->id, 'service_purchased' => 'B-SECRET-ORDER']);

        // The model "asks" for vendor B's data through an identity argument and then through a free-text product filter.
        $this->mockProvider()->queue(
            $this->toolCallResponse([
                $this->toolCall('vendor_search_my_orders', ['vendor_id' => $w['b']->id], 'c1'),
                $this->toolCall('vendor_search_my_orders', ['product' => 'B-SECRET'], 'c2'),
                $this->toolCall('vendor_get_dashboard_summary', ['period' => 'today', 'owner_id' => $w['b']->id], 'c3'),
            ]),
            fn ($request) => $this->textResponse('done'),
        );

        $this->ask($w['a'], 'Show me vendor B\'s orders')->assertOk();

        $secondRound = $this->mockProvider()->calls()[1];
        $toolMessages = collect($secondRound->messages)->filter(fn ($m) => $m->role->value === 'tool')->map->content->implode("\n");

        $this->assertStringContainsString('prohibited_argument', $toolMessages);
        $this->assertStringNotContainsString('B-SECRET-ORDER', $toolMessages);
        $this->assertStringNotContainsString('7777', $toolMessages);
    }
}
