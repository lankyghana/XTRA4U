<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Models\AfaRegistration;
use App\Models\NetworkService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\ResultCheckerOrder;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolExecutor;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Ai\AiTestCase;

/**
 * Vendor Assistant tests: engine ON, vendor surface ON, offline scripted mock provider, the REAL sealed tool registry
 * and the shipped allowlist, a real database. Http is faked and asserted empty in tearDown (inherited from AiTestCase),
 * so every test also proves nothing reached the network.
 *
 * Time is frozen at Wednesday 2026-11-18 15:00 so "today / yesterday / this week / this month" are all different,
 * non-empty buckets and every expected figure in these tests is a literal that can be checked by hand.
 */
abstract class VendorAssistantTestCase extends AiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00'));
        config(['ai.tools.allowlist.vendor' => VendorAssistant::TOOLS]);
        RateLimiter::clear('vendor-assistant:vendor:1');
    }

    // ------------------------------------------------------------------------------------------------ actors

    protected function approvedVendor(array $attrs = []): Vendor
    {
        return Vendor::factory()->create(array_merge(['is_approved' => true], $attrs))->fresh();
    }

    protected function unapprovedVendor(): Vendor
    {
        return Vendor::factory()->create(['is_approved' => false])->fresh();
    }

    protected function signedIn(Vendor $vendor): static
    {
        return $this->actingAs($vendor, 'vendor');
    }

    protected function vendorContext(?Vendor $vendor = null): ToolContext
    {
        return ToolContext::forVendor($vendor ?? $this->approvedVendor());
    }

    // ------------------------------------------------------------------------------------------------ fixtures

    /** @param array<string,mixed> $meta storefront metadata stored in the product description JSON */
    protected function makeProduct(Vendor $vendor, string $name, float $price = 10.0, array $meta = [], bool $active = true, bool $resellable = true): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => json_encode($meta + ['category' => 'data', 'network' => 'MTN']),
            'price' => $price,
            'is_active' => $active,
            'is_resellable' => $resellable,
        ]);
    }

    protected function makeListing(Product $product, Vendor $reseller, float $markup = 2.0, bool $active = true): ResellerProduct
    {
        return ResellerProduct::create([
            'product_id' => $product->id,
            'reseller_vendor_id' => $reseller->id,
            'owner_vendor_id' => $product->vendor_id,
            'base_price' => $product->price,
            'markup_price' => $markup,
            'is_active' => $active,
        ]);
    }

    /** @param array<string,mixed> $attrs */
    protected function makeOrder(array $attrs, string|Carbon|null $createdAt = null): Order
    {
        $order = new Order(array_merge([
            'recipient_phone_number' => '0241112222',
            'mobile_money_number' => '0553334444',
            'service_purchased' => 'Plain Bundle',
            'amount_paid' => 10.00,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'PAYREF-'.uniqid(),
            'is_reseller_order' => false,
        ], $attrs));

        if ($createdAt !== null) {
            $order->created_at = Carbon::parse($createdAt);
        }

        $order->save();

        return $order;
    }

    protected function makeTransaction(Vendor $vendor, float $amount, float $commission, float $earning, string|Carbon $createdAt, string $status = 'completed', ?Order $order = null): Transaction
    {
        $tx = new Transaction([
            'order_id' => $order?->id,
            'vendor_id' => $vendor->id,
            'recipient_phone' => '0241112222',
            'amount' => $amount,
            'commission_amount' => $commission,
            'vendor_earning' => $earning,
            'payment_status' => $status,
        ]);
        $tx->created_at = Carbon::parse($createdAt);
        $tx->save();

        return $tx;
    }

    protected function makeAfa(Vendor $vendor, array $attrs = []): AfaRegistration
    {
        return AfaRegistration::create(array_merge([
            'vendor_id' => $vendor->id,
            'reseller_vendor_id' => null,
            'full_name' => 'Test Customer',
            'id_number' => 'GHA-123456789-0',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0241234567',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => null,
            'amount' => 30.00,
            'vendor_price' => 30.00,
            'platform_commission' => 0.60,
            'vendor_earning' => 29.40,
            'reseller_earning' => 0.00,
            'is_reseller_order' => false,
            'payment_reference' => 'PAYREF-AFA-'.uniqid(),
            'payment_gateway' => 'test',
            'payment_status' => AfaRegistration::PAYMENT_COMPLETED,
            'payment_completed_at' => now(),
            'status' => AfaRegistration::STATUS_PENDING,
            'reference' => 'AFA-'.strtoupper(uniqid()),
        ], $attrs));
    }

    protected function makeResultChecker(Vendor $vendor, NetworkService $service, array $attrs = []): ResultCheckerOrder
    {
        return ResultCheckerOrder::factory()->create(array_merge([
            'vendor_id' => $vendor->id,
            'service_id' => $service->id,
            'status' => 'completed',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total_price' => 25.00,
            'fulfilled_at' => now(),
        ], $attrs));
    }

    /**
     * The shared "business" every parity / isolation test reads. Vendor A is the product owner (and a normal seller),
     * vendor B resells A's product, vendor C is unrelated.
     *
     * "Now" is Wed 2026-11-18 15:00. Transactions of vendor A (amount / commission / earning):
     *   today       09:00   100 /  2 /  98   completed
     *   yesterday   10:00    50 /  1 /  49   successful
     *   Monday      08:00   200 /  4 / 196   completed      (this week)
     *   Nov 3       08:00   400 /  8 / 392   completed      (this month)
     *   Oct 20      08:00  1000 / 20 / 980   completed      (older)
     *   today       10:00   999 /  0 /   0   pending        (never counted)
     * Vendor B has a 7777 transaction today (must never reach A).
     *
     * @return array{a:Vendor,b:Vendor,c:Vendor,product:Product,listing:ResellerProduct,rc_service:NetworkService}
     */
    protected function world(): array
    {
        $a = $this->approvedVendor(['name' => 'Alpha Data']);
        $b = $this->approvedVendor(['name' => 'Bravo Reseller']);
        $c = $this->approvedVendor(['name' => 'Charlie Other']);

        $product = $this->makeProduct($a, 'MTN 5GB', 40.0, ['size' => '5GB', 'validity' => '30 days']);
        $listing = $this->makeListing($product, $b, 3.0);

        $this->makeTransaction($a, 100, 2, 98, '2026-11-18 09:00:00');
        $this->makeTransaction($a, 50, 1, 49, '2026-11-17 10:00:00', 'successful');
        $this->makeTransaction($a, 200, 4, 196, '2026-11-16 08:00:00');
        $this->makeTransaction($a, 400, 8, 392, '2026-11-03 08:00:00');
        $this->makeTransaction($a, 1000, 20, 980, '2026-10-20 08:00:00');
        $this->makeTransaction($a, 999, 0, 0, '2026-11-18 10:00:00', 'pending');
        $this->makeTransaction($b, 7777, 155.54, 7621.46, '2026-11-18 11:00:00');

        // AFA: vendor A as provider (sales = vendor_price, earnings = vendor_earning) ...
        $this->makeAfa($a, ['amount' => 30, 'vendor_price' => 30, 'vendor_earning' => 29.40]);
        // ... and as reseller of vendor C's registration (sales = amount - vendor_price, earnings = reseller_earning).
        $this->makeAfa($c, [
            'reseller_vendor_id' => $a->id, 'is_reseller_order' => true,
            'amount' => 50, 'vendor_price' => 40, 'vendor_earning' => 39.20, 'reseller_earning' => 9.00,
        ]);
        // An unpaid AFA registration never counts.
        $this->makeAfa($a, ['payment_status' => 'pending', 'amount' => 500, 'vendor_price' => 500, 'vendor_earning' => 490]);

        // Result checkers (only `completed` counts, dated by fulfilled_at). The stored vendor_profit is what counts:
        // the column is NOT NULL DEFAULT 0, so the dashboard's COALESCE(...CASE...) fallback can never apply.
        $rc = NetworkService::factory()->create(['base_price' => 20.00]);
        $this->makeResultChecker($a, $rc, ['total_price' => 25, 'unit_price' => 25, 'vendor_profit' => 5, 'fulfilled_at' => '2026-11-18 12:00:00']);
        $this->makeResultChecker($a, $rc, ['quantity' => 2, 'unit_price' => 25, 'total_price' => 50, 'vendor_profit' => 10, 'fulfilled_at' => '2026-11-17 12:00:00']);
        $this->makeResultChecker($a, $rc, ['status' => 'pending_payment', 'total_price' => 900, 'fulfilled_at' => null]);

        return ['a' => $a, 'b' => $b, 'c' => $c, 'product' => $product, 'listing' => $listing, 'rc_service' => $rc];
    }

    // ----------------------------------------------------------------------------------- AI test helpers

    protected function textResponse(string $text): AiResponse
    {
        return new AiResponse($text, 'mock', 'mock-model', new AiUsage(20, 10));
    }

    /** @param list<AiToolCall> $calls */
    protected function toolCallResponse(array $calls, string $text = ''): AiResponse
    {
        return new AiResponse($text, 'mock', 'mock-model', new AiUsage(20, 10), $calls, 'tool_calls');
    }

    protected function toolCall(string $name, array $arguments = [], string $id = 'call_1'): AiToolCall
    {
        return new AiToolCall($id, $name, $arguments);
    }

    /** Run one tool call through the real executor, registry and allowlist. */
    protected function runTool(string $name, array $arguments, ToolContext $context, string $id = 'call_1'): ToolResult
    {
        $executor = app(ToolExecutor::class);

        return $executor->execute($this->toolCall($name, $arguments, $id), $context, $executor->beginTurn());
    }

    protected function payload(ToolResult $result): string
    {
        return $result->toModelPayload();
    }

    /** POST a question as the given vendor. */
    protected function ask(Vendor $vendor, string $message, array $extra = [])
    {
        return $this->signedIn($vendor)->postJson(route('vendor.assistant.message'), ['message' => $message] + $extra);
    }
}
