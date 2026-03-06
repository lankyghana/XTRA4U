<?php

namespace Tests\Unit;

use App\Models\PaymentGatewayConfig;
use App\Services\MoolrePaymentService;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoolrePhoneFormattingTest extends TestCase
{
    #[Test]
    public function moolre_collection_phone_normalization_uses_local_zero_prefixed_format(): void
    {
        $service = new MoolrePaymentService(new PaymentGatewayConfig());

        $method = new \ReflectionMethod($service, 'normalizePhone');
        $method->setAccessible(true);

        $this->assertSame('0244123456', $method->invoke($service, '+233244123456'));
        $this->assertSame('0244123456', $method->invoke($service, '233244123456'));
        $this->assertSame('0244123456', $method->invoke($service, '0244123456'));
        $this->assertSame('0244123456', $method->invoke($service, '244123456'));
    }

    #[Test]
    public function moolre_payout_phone_normalization_uses_local_zero_prefixed_format(): void
    {
        $formatter = new class {
            use FormatsGhanaPhoneNumber;

            public function callFormatPhoneNumber(string $phone): string
            {
                return $this->formatPhoneNumber($phone);
            }
        };

        $this->assertSame('0551234567', $formatter->callFormatPhoneNumber('+233551234567'));
        $this->assertSame('0551234567', $formatter->callFormatPhoneNumber('233551234567'));
        $this->assertSame('0551234567', $formatter->callFormatPhoneNumber('551234567'));
        $this->assertSame('0551234567', $formatter->callFormatPhoneNumber('0551234567'));
    }
}
