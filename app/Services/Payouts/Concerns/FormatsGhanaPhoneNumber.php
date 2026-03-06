<?php

namespace App\Services\Payouts\Concerns;

trait FormatsGhanaPhoneNumber
{
    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if ($phone === '') {
            return '';
        }

        // Moolre expects local Ghana format (0XXXXXXXXX).
        if (str_starts_with($phone, '233')) {
            $phone = '0' . substr($phone, 3);
        }

        if (strlen($phone) === 9) {
            $phone = '0' . $phone;
        }

        return $phone;
    }
}
