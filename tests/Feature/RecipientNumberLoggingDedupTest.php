<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Vendor;
use App\Services\RecipientNumberLoggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipientNumberLoggingDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_number_logging_does_not_duplicate_rows_for_same_vendor_and_service(): void
    {
        $vendor = Vendor::factory()->create();

        $order = Order::create([
            'recipient_phone_number' => '0240000000',
            'mobile_money_number' => '0240000000',
            'service_purchased' => 'MTN_DATA_1GB',
            'amount_paid' => 10.00,
            'vendor_id' => $vendor->id,
            'status' => 'Completed',
            'payment_status' => 'paid',
            'payment_reference' => 'XTRA4U-REF-DEDUP',
            'payment_completed_at' => now(),
        ]);

        $logger = app(RecipientNumberLoggingService::class);

        $logger->logFromOrder($order);
        $logger->logFromOrder($order);

        $normalized = $logger->normalizePhoneNumber('0240000000');

        $this->assertSame(1, DB::table('recipient_number_logs')
            ->where('phone_number', $normalized)
            ->where('vendor_id', $vendor->id)
            ->where('service_type', 'MTN_DATA_1GB')
            ->count());
    }
}
