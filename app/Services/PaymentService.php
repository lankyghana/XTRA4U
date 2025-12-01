<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;

class PaymentService
{
    /**
     * Process payment and create transaction.
     */
    public function processPayment(Order $order, $recipientPhone, $amount)
    {
        $commission = round($amount * 0.01, 2);
        $vendorEarning = round($amount * 0.99, 2);
        $transaction = Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'recipient_phone' => $recipientPhone,
            'amount' => $amount,
            'commission_amount' => $commission,
            'vendor_earning' => $vendorEarning,
            'payment_status' => 'completed',
            'timestamp' => now(),
        ]);
        return $transaction;
    }
}
