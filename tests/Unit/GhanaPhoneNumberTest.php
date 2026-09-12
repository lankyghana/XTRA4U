<?php

namespace Tests\Unit;

use App\Support\GhanaPhoneNumber;
use Tests\TestCase;

class GhanaPhoneNumberTest extends TestCase
{
    public function test_local_number_converts_to_international(): void
    {
        $this->assertSame('233244123456', GhanaPhoneNumber::toInternational('0244123456'));
    }

    public function test_number_with_plus_233_converts_to_international(): void
    {
        $this->assertSame('233244123456', GhanaPhoneNumber::toInternational('+233244123456'));
    }

    public function test_number_with_bare_233_converts_to_international(): void
    {
        $this->assertSame('233244123456', GhanaPhoneNumber::toInternational('233244123456'));
    }

    public function test_number_with_spaces_and_dashes_is_normalized(): void
    {
        $this->assertSame('233244123456', GhanaPhoneNumber::toInternational('024 412-3456'));
    }

    public function test_bare_nine_digit_subscriber_number_is_normalized(): void
    {
        $this->assertSame('233244123456', GhanaPhoneNumber::toInternational('244123456'));
    }

    public function test_international_converts_back_to_local(): void
    {
        $this->assertSame('0244123456', GhanaPhoneNumber::toLocal('233244123456'));
    }

    public function test_invalid_number_returns_empty_string_for_international(): void
    {
        $this->assertSame('', GhanaPhoneNumber::toInternational('12345'));
        $this->assertSame('', GhanaPhoneNumber::toInternational(''));
        $this->assertSame('', GhanaPhoneNumber::toInternational('not-a-phone'));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(GhanaPhoneNumber::isValid('0244123456'));
        $this->assertTrue(GhanaPhoneNumber::isValid('233244123456'));
        $this->assertFalse(GhanaPhoneNumber::isValid('123'));
        $this->assertFalse(GhanaPhoneNumber::isValid(''));
    }
}
