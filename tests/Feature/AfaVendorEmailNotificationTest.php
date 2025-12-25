<?php

namespace Tests\Feature;

use App\Mail\AfaOrderPlacedMail;
use App\Models\AfaRegistration;
use App\Models\Vendor;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class AfaVendorEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_afa_registration_sends_email_to_source_vendor(): void
    {
        Mail::fake();

        $vendor = Vendor::factory()->create([
            'email' => 'vendor@example.com',
            'afa_enabled' => true,
            'afa_price' => 10,
        ]);

        $registration = AfaRegistration::create([
            'vendor_id' => $vendor->id,
            'reseller_vendor_id' => null,
            'full_name' => 'Test User',
            'id_type' => AfaRegistration::ID_GHANA_CARD,
            'id_number' => 'GHA-123456789-0',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0550000000',
            'location' => 'Accra',
            'region' => 'Greater Accra',
            'occupation' => 'Engineer',
            'amount' => 10,
            'vendor_price' => 10,
            'platform_commission' => 0.2,
            'vendor_earning' => 9.8,
            'reseller_earning' => 0,
            'is_reseller_order' => false,
            'status' => AfaRegistration::STATUS_PENDING,
            'payment_status' => AfaRegistration::PAYMENT_PENDING,
            'reference' => AfaRegistration::generateReference(),
            'payment_reference' => 'PAY_REF_123',
        ]);

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('checkPaymentStatus')
            ->once()
            ->with('PAY_REF_123')
            ->andReturn(['success' => true]);

        $this->app->instance(PaymentService::class, $paymentService);

        $response = $this->get(route('afa.callback', ['reference' => 'PAY_REF_123']));
        $response->assertRedirect(route('afa.success', $registration->reference));

        Mail::assertQueued(AfaOrderPlacedMail::class, function (AfaOrderPlacedMail $mail) use ($vendor, $registration) {
            return $mail->hasTo($vendor->email)
                && $mail->registration->is($registration)
                && $mail->vendor->is($vendor);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
