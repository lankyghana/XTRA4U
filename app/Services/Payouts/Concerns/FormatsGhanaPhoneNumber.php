<?php

namespace App\Services\Payouts\Concerns;

trait FormatsGhanaPhoneNumber
{
    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '233' . substr($phone, 1);
        }

        if (!str_starts_with($phone, '233')) {
            $phone = '233' . $phone;
        }

        return $phone;
    }
}
