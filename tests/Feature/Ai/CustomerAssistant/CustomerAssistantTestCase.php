<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Models\Cms\CmsFaq;
use App\Models\NetworkService;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolExecutor;
use App\Services\Ai\Tools\ToolResult;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\AiTestCase;

/**
 * Customer Assistant tests: engine ON, customer surface ON, offline scripted mock provider, the REAL sealed tool registry
 * and the shipped allowlist, a real database. Http is faked and asserted empty in tearDown (inherited from AiTestCase),
 * so every test also proves nothing reached the network.
 */
abstract class CustomerAssistantTestCase extends AiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.tools.allowlist.customer' => CustomerAssistant::TOOLS]);
    }

    // ------------------------------------------------------------------------------------------------ fixtures

    protected function approvedVendor(array $attrs = []): Vendor
    {
        return Vendor::factory()->create(array_merge(['is_approved' => true], $attrs))->fresh();
    }

    protected function assign(string $category, ?Vendor $vendor): void
    {
        PlatformServiceVendor::setVendorFor($category, $vendor?->id);
    }

    /** @param array<string,mixed> $meta */
    protected function product(Vendor $vendor, string $name, float $price, array $meta = [], bool $active = true, bool $resellable = true, ?string $rawDescription = null): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'description' => $rawDescription ?? json_encode($meta + ['category' => 'data', 'network' => 'MTN']),
            'price' => $price,
            'is_active' => $active,
            'is_resellable' => $resellable,
        ]);
    }

    protected function listing(Product $product, Vendor $reseller, float $markup = 2.0, bool $active = true): ResellerProduct
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

    protected function checker(Vendor $vendor, string $name, float $base, float $profit, bool $active = true, bool $serviceActive = true): NetworkService
    {
        $service = NetworkService::factory()->create([
            'name' => $name, 'category' => 'results', 'service_type' => 'results_checker',
            'base_price' => $base, 'is_active' => $serviceActive, 'description' => 'Exam result checker voucher',
        ]);

        VendorResultCheckerSetting::create([
            'vendor_id' => $vendor->id, 'service_id' => $service->id, 'profit_amount' => $profit, 'is_active' => $active,
        ]);

        return $service;
    }

    protected function faq(string $question, string $answer, bool $active = true, string $category = 'Buying'): CmsFaq
    {
        return CmsFaq::create(['category' => $category, 'question' => $question, 'answer' => $answer, 'is_active' => $active, 'sort_order' => 0]);
    }

    /**
     * The shared public catalogue.
     *   data    -> vendor A:  MTN 1GB 5.00, MTN 5GB 20.00, Telecel 2GB 8.00, (inactive MTN 10GB 40.00), and a listing of
     *                         vendor C's resellable "Telecel 10GB" (base 30.00 + markup 3.00 = 33.00)
     *   shop    -> vendor S:  Gift Voucher 50.00
     *   results -> vendor R:  WAEC Checker (base 18.00 + profit 2.00 = 20.00)
     *   afa     -> vendor F:  AFA enabled
     * Vendor Z is NOT assigned to any category: its products must never be visible.
     *
     * @return array<string,mixed>
     */
    protected function catalogue(): array
    {
        $a = $this->approvedVendor(['name' => 'Alpha Data']);
        $c = $this->approvedVendor(['name' => 'Charlie Source']);
        $s = $this->approvedVendor(['name' => 'Sierra Shop']);
        $r = $this->approvedVendor(['name' => 'Romeo Results']);
        $f = $this->approvedVendor(['name' => 'Foxtrot Afa']);
        $z = $this->approvedVendor(['name' => 'Zulu Unassigned']);

        $this->assign('data', $a);
        $this->assign('shop', $s);
        $this->assign('results', $r);
        $this->assign('afa', $f);

        $secret = ['external_mappings' => ['xpres' => ['service_id' => 'SECRET-SVC-9981', 'cost' => '3.10']], 'supplier_cost' => '2.50'];

        $p1 = $this->product($a, 'MTN 1GB', 5.00, ['size' => '1GB', 'validity' => '30 days', 'notes' => 'Great value daily bundle'] + $secret);
        $p5 = $this->product($a, 'MTN 5GB', 20.00, ['size' => '5GB', 'validity' => '30 days'] + $secret);
        $t2 = $this->product($a, 'Telecel 2GB', 8.00, ['network' => 'TELECEL', 'size' => '2GB', 'validity' => '7 days']);
        $off = $this->product($a, 'MTN 10GB', 40.00, ['size' => '10GB'], active: false);
        $source = $this->product($c, 'Telecel 10GB', 30.00, ['network' => 'TELECEL', 'size' => '10GB', 'validity' => '30 days'] + $secret);
        $listing = $this->listing($source, $a, 3.0);
        $voucher = $this->product($s, 'Gift Voucher', 50.00, ['category' => 'shop', 'service' => 'Gift Cards', 'notes' => 'A digital gift voucher']);
        $hidden = $this->product($z, 'MTN Zulu Special', 1.00, ['size' => '1GB']);
        $checker = $this->checker($r, 'WAEC Checker', 18.00, 2.00);

        Vendor::query()->whereKey($f->id)->update(['afa_enabled' => true, 'afa_price' => 30.00]);

        return compact('a', 'c', 's', 'r', 'f', 'z', 'p1', 'p5', 't2', 'off', 'source', 'listing', 'voucher', 'hidden', 'checker');
    }

    // ------------------------------------------------------------------------------------------------ actors

    protected function guestContext(?string $session = null): ToolContext
    {
        return ToolContext::forGuest($session ?? str_repeat('g', 40));
    }

    // ------------------------------------------------------------------------------------- AI test helpers

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
    protected function runTool(string $name, array $arguments = [], ?ToolContext $context = null, string $id = 'call_1'): ToolResult
    {
        $executor = app(ToolExecutor::class);

        return $executor->execute($this->toolCall($name, $arguments, $id), $context ?? $this->guestContext(), $executor->beginTurn());
    }

    /** POST a message as an anonymous visitor. */
    protected function say(string $message, array $extra = [])
    {
        return $this->postJson(route('customer.assistant.message'), ['message' => $message] + $extra);
    }

    protected function closeCategory(string $category): void
    {
        ServiceAvailability::setOpen($category, false);
    }
}
