<?php

namespace Tests\Feature;

use App\Models\ResultCheckerOrder;
use App\Models\ResultCheckerPin;
use App\Models\NetworkService;
use App\Models\Vendor;
use App\Models\Admin;
use App\Jobs\RetryResultCheckerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResultCheckerFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private NetworkService $service;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = Vendor::factory()->create();
        $this->service = NetworkService::factory()->create(['service_type' => 'results_checker']);
        $this->admin = Admin::factory()->create();
    }

    #[Test]
    public function allocated_pins_appear_on_admin_order_page()
    {
        $order = ResultCheckerOrder::factory()->create([
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'completed',
        ]);
        $pin = ResultCheckerPin::factory()->create([
            'service_id' => $this->service->id,
            'result_checker_order_id' => $order->id,
            'status' => 'sold',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get(route('admin.result-checkers.orders.show', $order));

        $response->assertOk();
        $response->assertSee(substr($pin->pin, -4));
        $response->assertSee(substr($pin->serial, -4));
    }

    #[Test]
    public function retry_job_does_not_duplicate_allocations()
    {
        $order = ResultCheckerOrder::factory()->create([
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'completed',
            'paid_at' => now(),
        ]);
        ResultCheckerPin::factory()->create([
            'service_id' => $this->service->id,
            'result_checker_order_id' => $order->id,
            'status' => 'sold',
        ]);
        ResultCheckerPin::factory()->create([
            'service_id' => $this->service->id,
            'status' => 'available',
        ]);

        $this->assertEquals(1, $order->pins()->count());

        $job = new RetryResultCheckerOrder($order->id);
        $job->handle(app(\App\Services\ResultCheckerService::class));

        $this->assertEquals(1, $order->refresh()->pins()->count());
    }

    #[Test]
    public function pending_orders_allocate_after_stock_upload()
    {
        $order = ResultCheckerOrder::factory()->create([
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor->id,
            'status' => 'pending_stock',
            'paid_at' => now(),
        ]);

        ResultCheckerPin::factory()->create([
            'service_id' => $this->service->id,
            'status' => 'available',
        ]);

        $job = new \App\Jobs\FulfillPendingOrdersJob($this->service->id);
        $job->handle(app(\App\Services\ResultCheckerService::class));

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertCount(1, $order->pins);
    }

    #[Test]
    public function sms_formatting_contains_real_line_breaks()
    {
        $order = ResultCheckerOrder::factory()->create([
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor->id,
        ]);
        $pins = [
            ['pin' => '123', 'serial' => 'ABC'],
            ['pin' => '456', 'serial' => 'DEF'],
        ];

        $service = app(\App\Services\ResultCheckerService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatSmsMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, $order, $pins);

        $this->assertStringContainsString("PIN: 123\nSERIAL: ABC\nPIN: 456\nSERIAL: DEF", $message);
    }

    #[Test]
    public function order_pins_relationship_works_correctly()
    {
        $order = ResultCheckerOrder::factory()->create([
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor->id,
        ]);
        ResultCheckerPin::factory()->count(2)->create([
            'service_id' => $this->service->id,
            'result_checker_order_id' => $order->id,
        ]);

        $this->assertCount(2, $order->pins);
    }
}
