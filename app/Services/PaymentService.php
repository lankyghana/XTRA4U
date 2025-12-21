<?php
namespace App\Services;

use App\Events\OrderCompleted;
use App\Mail\OrderPlacedMail;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentService
{
    protected GatewayManager $gatewayManager;
    protected ?object $paymentGateway;
    protected ?SmsService $smsService;
    protected $payoutService; // Accept any payout service type

    public function __construct(?GatewayManager $gatewayManager = null)
    {
        $this->gatewayManager = $gatewayManager ?? new GatewayManager();
        $this->paymentGateway = $this->gatewayManager->getPaymentService();
        $this->smsService = $this->gatewayManager->getSmsService();
        $this->payoutService = $this->gatewayManager->getPayoutService();
    }

    /**
     * Initiate payment collection from customer
     * This ONLY initiates payment, does NOT complete the order or credit vendor balance
     */
    public function initiatePayment(Order $order, string $email, float $amount): array
    {
        if (!$this->paymentGateway) {
            return [
                'success' => false,
                'message' => 'No active payment gateway configured.',
                'reference' => null,
            ];
        }

        // Request payment via active gateway (returns authorization URL)
        $result = $this->paymentGateway->requestPayment($order, $email, $amount);

        if ($result['success']) {
            $order->update([
                'payment_reference' => $result['reference'],
                'payment_status' => 'pending',
                'status' => 'pending',
            ]);
            
            // Create PENDING transaction (NOT successful yet)
            $this->createPendingTransaction($order, $result['reference']);
            
            return [
                'success' => true,
                'message' => $result['message'] ?? 'Payment initialized. Please complete payment.',
                'reference' => $result['reference'],
                'authorization_url' => $result['authorization_url'] ?? null,
                'order_id' => $order->id,
            ];
        }
        return [
            'success' => false,
            'message' => $result['message'] ?? 'Failed to initiate payment. Please try again.',
            'reference' => $result['reference'] ?? null,
        ];
    }

    /**
     * Create pending transaction on payment initiation
     * Balance is NOT credited at this stage
     */
    protected function createPendingTransaction(Order $order, string $paymentReference): void
    {
        if ($order->is_reseller_order && $order->reseller_product_id) {
            $resellerProduct = ResellerProduct::find($order->reseller_product_id);
            if ($resellerProduct) {
                $basePrice = $resellerProduct->base_price;
                $markupPrice = $resellerProduct->markup_price;
                $ownerCommission = round($basePrice * 0.02, 2);
                $resellerCommission = round($markupPrice * 0.02, 2);
                $ownerEarning = round($basePrice - $ownerCommission, 2);
                $resellerEarning = round($markupPrice - $resellerCommission, 2);

                // Create PENDING transaction for owner
                Transaction::create([
                    'order_id' => $order->id,
                    'vendor_id' => $resellerProduct->owner_vendor_id,
                    'recipient_phone' => $order->recipient_phone_number,
                    'amount' => $basePrice,
                    'commission_amount' => $ownerCommission,
                    'vendor_earning' => $ownerEarning,
                    'payment_status' => 'pending',
                    'status' => Transaction::STATUS_PENDING,
                    'payment_reference' => $paymentReference,
                ]);

                // Create PENDING transaction for reseller
                Transaction::create([
                    'order_id' => $order->id,
                    'vendor_id' => $resellerProduct->reseller_vendor_id,
                    'recipient_phone' => $order->recipient_phone_number,
                    'amount' => $markupPrice,
                    'commission_amount' => $resellerCommission,
                    'vendor_earning' => $resellerEarning,
                    'payment_status' => 'pending',
                    'status' => Transaction::STATUS_PENDING,
                    'payment_reference' => $paymentReference,
                ]);
            }
        } else {
            // Regular order - create PENDING transaction
            $amountPaid = (float) $order->amount_paid;
            $commission = round($amountPaid * 0.02, 2);
            $vendorEarnings = round($amountPaid - $commission, 2);

            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => $amountPaid,
                'commission_amount' => $commission,
                'vendor_earning' => $vendorEarnings,
                'payment_status' => 'pending',
                'status' => Transaction::STATUS_PENDING,
                'payment_reference' => $paymentReference,
            ]);
        }
    }

    /**
     * Complete an order after VERIFIED successful payment
     * This handles all post-payment logic: transactions, notifications, emails, SMS, wallet updates
     * CRITICAL: Only call this after payment gateway confirms success
     */
    public function completeOrder(Order $order): bool
    {
        // Prevent duplicate processing - check if already completed
        if ($order->payment_status === 'completed') {
            Log::warning('Attempted to complete already completed order', ['order_id' => $order->id]);
            return false;
        }

        return DB::transaction(function () use ($order) {
            // Check if order is a reseller order
            if ($order->is_reseller_order && $order->reseller_product_id) {
                return $this->completeResellerOrder($order);
            } else {
                return $this->completeRegularOrder($order);
            }
        });
    }

    /**
     * Complete a regular (non-reseller) order
     */
    protected function completeRegularOrder(Order $order): bool
    {
        $amountPaid = (float) $order->amount_paid;
        $commission = round($amountPaid * 0.02, 2);
        $vendorEarnings = round($amountPaid - $commission, 2);

        // Update existing PENDING transaction to SUCCESSFUL
        $transaction = Transaction::where('order_id', $order->id)
            ->where('vendor_id', $order->vendor_id)
            ->where('status', Transaction::STATUS_PENDING)
            ->first();

        if ($transaction) {
            $transaction->update([
                'payment_status' => 'completed',
                'status' => Transaction::STATUS_SUCCESSFUL,
                'verified_at' => now(),
            ]);
        } else {
            // Fallback: create new transaction if pending doesn't exist
            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => $amountPaid,
                'commission_amount' => $commission,
                'vendor_earning' => $vendorEarnings,
                'payment_status' => 'completed',
                'status' => Transaction::STATUS_SUCCESSFUL,
                'payment_reference' => $order->payment_reference,
                'verified_at' => now(),
            ]);
        }

        // Update order status
        $order->update([
            'status' => 'Processing',
            'payment_status' => 'completed',
            'payment_completed_at' => now(),
        ]);

        // Update vendor wallet balance (NOW, not before)
        $vendor = Vendor::find($order->vendor_id);
        if ($vendor) {
            $vendor->increment('wallet_balance', $vendorEarnings);

            // Send notification to vendor
            VendorNotification::create([
                'vendor_id' => $vendor->id,
                'type' => VendorNotification::TYPE_NEW_ORDER,
                'title' => 'New Order Received',
                'message' => "You have a new order for '{$order->service_purchased}'. Earning: GHS " . number_format($vendorEarnings, 2),
                'order_id' => $order->id,
                'data' => [
                    'product_name' => $order->service_purchased,
                    'amount' => $amountPaid,
                    'earning' => $vendorEarnings,
                ],
            ]);

            // Send email to vendor
            if ($vendor->email) {
                try {
                    Mail::to($vendor->email)->send(new OrderPlacedMail($order, $vendor, 'owner', $vendorEarnings));
                } catch (\Exception $e) {
                    Log::warning('Failed to send order email to vendor', ['error' => $e->getMessage()]);
                }
            }

            // Send SMS to vendor
            $this->sendVendorSms($vendor, $order, $vendorEarnings);
        }

        // Send admin notification
        AdminNotification::notifyNewOrder($order);

        // Dispatch order completed event
        event(new OrderCompleted($order));

        Log::info('Regular order completed', [
            'order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'commission' => $commission,
            'vendor_earnings' => $vendorEarnings,
        ]);

        return true;
    }

    /**
     * Complete a reseller order with split payment
     */
    protected function completeResellerOrder(Order $order): bool
    {
        $resellerProduct = ResellerProduct::with(['ownerVendor', 'resellerVendor', 'product'])
            ->find($order->reseller_product_id);

        if (!$resellerProduct) {
            Log::error('Reseller product not found for order', ['order_id' => $order->id]);
            return false;
        }

        $basePrice = $resellerProduct->base_price;
        $markupPrice = $resellerProduct->markup_price;

        // Calculate commissions (2% each)
        $ownerCommission = round($basePrice * 0.02, 2);
        $resellerCommission = round($markupPrice * 0.02, 2);
        $totalPlatformCommission = $ownerCommission + $resellerCommission;

        // Calculate earnings (after commission)
        $ownerEarning = round($basePrice - $ownerCommission, 2);
        $resellerEarning = round($markupPrice - $resellerCommission, 2);

        // Update order with earnings info
        $order->update([
            'status' => 'Processing', // Immediately queue reseller orders for fulfillment after payment clears
            'payment_status' => 'completed',
            'payment_completed_at' => now(),
            'base_price' => $basePrice,
            'markup_price' => $markupPrice,
            'owner_earning' => $ownerEarning,
            'reseller_earning' => $resellerEarning,
            'platform_commission' => $totalPlatformCommission,
        ]);

        // Update existing PENDING transactions to SUCCESSFUL
        // Owner transaction
        $ownerTransaction = Transaction::where('order_id', $order->id)
            ->where('vendor_id', $resellerProduct->owner_vendor_id)
            ->where('status', Transaction::STATUS_PENDING)
            ->first();

        if ($ownerTransaction) {
            $ownerTransaction->update([
                'payment_status' => 'completed',
                'status' => Transaction::STATUS_SUCCESSFUL,
                'verified_at' => now(),
            ]);
        } else {
            // Fallback: create new transaction if pending doesn't exist
            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $resellerProduct->owner_vendor_id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => $basePrice,
                'commission_amount' => $ownerCommission,
                'vendor_earning' => $ownerEarning,
                'payment_status' => 'completed',
                'status' => Transaction::STATUS_SUCCESSFUL,
                'payment_reference' => $order->payment_reference,
                'verified_at' => now(),
            ]);
        }

        // Reseller transaction
        $resellerTransaction = Transaction::where('order_id', $order->id)
            ->where('vendor_id', $resellerProduct->reseller_vendor_id)
            ->where('status', Transaction::STATUS_PENDING)
            ->first();

        if ($resellerTransaction) {
            $resellerTransaction->update([
                'payment_status' => 'completed',
                'status' => Transaction::STATUS_SUCCESSFUL,
                'verified_at' => now(),
            ]);
        } else {
            // Fallback: create new transaction if pending doesn't exist
            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $resellerProduct->reseller_vendor_id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => $markupPrice,
                'commission_amount' => $resellerCommission,
                'vendor_earning' => $resellerEarning,
                'payment_status' => 'completed',
                'status' => Transaction::STATUS_SUCCESSFUL,
                'payment_reference' => $order->payment_reference,
                'verified_at' => now(),
            ]);
        }

        // Update wallet balances (NOW, not before)
        $ownerVendor = $resellerProduct->ownerVendor;
        $resellerVendor = $resellerProduct->resellerVendor;

        if ($ownerVendor) {
            $ownerVendor->increment('wallet_balance', $ownerEarning);

            // Notify owner vendor
            VendorNotification::create([
                'vendor_id' => $ownerVendor->id,
                'type' => VendorNotification::TYPE_AFFILIATE_ORDER,
                'title' => 'New Affiliate Order',
                'message' => "A reseller made a sale of your product '{$order->service_purchased}'. Your earning: GHS " . number_format($ownerEarning, 2),
                'order_id' => $order->id,
                'data' => [
                    'product_name' => $order->service_purchased,
                    'base_price' => $basePrice,
                    'earning' => $ownerEarning,
                    'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                ],
            ]);

            // Email owner vendor
            if ($ownerVendor->email) {
                try {
                    Mail::to($ownerVendor->email)->send(new OrderPlacedMail($order, $ownerVendor, 'owner', $ownerEarning));
                } catch (\Exception $e) {
                    Log::warning('Failed to send order email to owner vendor', ['error' => $e->getMessage()]);
                }
            }

            // SMS to owner vendor
            $this->sendVendorSms($ownerVendor, $order, $ownerEarning, 'affiliate');
        }

        if ($resellerVendor) {
            $resellerVendor->increment('wallet_balance', $resellerEarning);

            // Notify reseller vendor
            VendorNotification::create([
                'vendor_id' => $resellerVendor->id,
                'type' => VendorNotification::TYPE_NEW_ORDER,
                'title' => 'New Order Received',
                'message' => "You have a new order for '{$order->service_purchased}'. Your markup earning: GHS " . number_format($resellerEarning, 2),
                'order_id' => $order->id,
                'data' => [
                    'product_name' => $order->service_purchased,
                    'markup_price' => $markupPrice,
                    'earning' => $resellerEarning,
                ],
            ]);

            // Email reseller vendor
            if ($resellerVendor->email) {
                try {
                    Mail::to($resellerVendor->email)->send(new OrderPlacedMail($order, $resellerVendor, 'reseller', $resellerEarning));
                } catch (\Exception $e) {
                    Log::warning('Failed to send order email to reseller vendor', ['error' => $e->getMessage()]);
                }
            }

            // SMS to reseller vendor
            $this->sendVendorSms($resellerVendor, $order, $resellerEarning);
        }

        // Admin notification
        AdminNotification::notifyNewOrder($order);

        // Dispatch order completed event
        event(new OrderCompleted($order));

        Log::info('Reseller order completed with split payment', [
            'order_id' => $order->id,
            'owner_vendor_id' => $resellerProduct->owner_vendor_id,
            'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
            'base_price' => $basePrice,
            'markup_price' => $markupPrice,
            'owner_earning' => $ownerEarning,
            'reseller_earning' => $resellerEarning,
            'platform_commission' => $totalPlatformCommission,
        ]);

        return true;
    }

    /**
     * Send SMS notification to vendor
     */
    protected function sendVendorSms(Vendor $vendor, Order $order, float $earning, string $type = 'order'): void
    {
        if (!$this->smsService || !$vendor->phone_number) {
            return;
        }

        try {
            if ($type === 'affiliate') {
                $message = "XTRA4U: Affiliate sale! {$order->service_purchased} sold by your reseller. Your earning: GHS " . number_format($earning, 2);
            } else {
                $message = "XTRA4U: New order! {$order->service_purchased}. Earning: GHS " . number_format($earning, 2) . ". Customer: {$order->recipient_phone_number}";
            }

            $this->smsService->send($vendor->phone_number, $message);
        } catch (\Exception $e) {
            Log::warning('Failed to send SMS to vendor', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle payment webhook callback
     */
    public function handleWebhook(string $reference): array
    {
        if (!$this->paymentGateway) {
            return [
                'success' => false,
                'message' => 'No active payment gateway configured.',
            ];
        }
        
        $result = $this->paymentGateway->verifyPayment($reference);

        if (!$result['success']) {
            return $result;
        }

        $order = Order::where('payment_reference', $reference)->first();
        if (!$order) {
            Log::error('Webhook: Order not found', ['reference' => $reference]);
            return [
                'success' => false,
                'message' => 'Order not found',
            ];
        }

        // Prevent duplicate processing
        if ($order->payment_status === 'completed') {
            Log::info('Webhook: Order already completed', ['order_id' => $order->id]);
            return [
                'success' => true,
                'message' => 'Order already processed',
                'order_id' => $order->id,
            ];
        }

        $paymentStatus = strtolower($result['data']['status'] ?? '');
        if ($paymentStatus === 'success') {
            $this->completeOrder($order);
            Log::info('Webhook: Payment completed', [
                'order_id' => $order->id,
                'reference' => $reference,
            ]);
            return [
                'success' => true,
                'message' => 'Payment processed successfully',
                'order_id' => $order->id,
            ];
        } else {
            $order->update([
                'payment_status' => 'failed',
                'status' => 'Failed',
            ]);
            Log::info('Webhook: Payment failed', [
                'order_id' => $order->id,
                'reference' => $reference,
                'status' => $paymentStatus,
            ]);
            return [
                'success' => true,
                'message' => 'Payment failure recorded',
                'order_id' => $order->id,
            ];
        }
    }

    /**
     * Check payment status
     */
    public function checkPaymentStatus(string $reference): array
    {
        if (!$this->paymentGateway) {
            return [
                'success' => false,
                'message' => 'No active payment gateway configured.',
            ];
        }
        
        return $this->paymentGateway->verifyPayment($reference);
    }

    /**
     * Get wallet balance
     */
    public function getBalance(): array
    {
        if (!$this->payoutService) {
            return [
                'success' => false,
                'message' => 'No active payout gateway configured.',
            ];
        }
        
        $balance = $this->payoutService->getBalance();
        return [
            'success' => $balance !== null,
            'balance' => $balance ?? 0,
        ];
    }

    /**
     * Legacy method - Process payment and create transaction (for backwards compatibility)
     */
    public function processPayment(Order $order, $recipientPhone, $amount)
    {
        $commission = round($amount * 0.02, 2);
        $vendorEarning = round($amount * 0.98, 2);

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

        // Update order status
        $order->update([
            'payment_status' => 'completed',
            'status' => 'Completed',
        ]);

        // Update vendor balance
        $vendor = Vendor::find($order->vendor_id);
        if ($vendor) {
            $vendor->increment('wallet_balance', $vendorEarning);
        }

        return $transaction;
    }

    /**
     * Verify payment status with payment gateway
     * Call this before completing order
     */
    public function verifyPayment(string $paymentReference): array
    {
        try {
            $verification = $this->paymentGateway->verifyPayment($paymentReference);

            if (!$verification['success']) {
                Log::warning('Payment verification failed', [
                    'reference' => $paymentReference,
                    'response' => $verification,
                ]);
            }

            return $verification;
        } catch (\Exception $e) {
            Log::error('Payment verification error', [
                'reference' => $paymentReference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unable to verify payment',
            ];
        }
    }

    /**
     * Mark transaction as failed and DELETE the order
     * Orders with unsuccessful payments are not recorded
     */
    public function markTransactionFailed(Order $order, string $reason = null): void
    {
        Log::info('Deleting failed order and transactions', [
            'order_id' => $order->id,
            'payment_reference' => $order->payment_reference,
            'reason' => $reason,
        ]);

        // Delete all transactions for this order
        Transaction::where('order_id', $order->id)->delete();

        // Delete the order itself
        $order->delete();

        Log::info('Failed order deleted successfully', [
            'reason' => $reason,
        ]);
    }

    /**
     * Mark transaction as abandoned and DELETE the order
     * Orders with unsuccessful payments are not recorded
     */
    public function markTransactionAbandoned(Order $order): void
    {
        Log::info('Deleting abandoned order and transactions', [
            'order_id' => $order->id,
            'payment_reference' => $order->payment_reference,
        ]);

        // Delete all transactions for this order
        Transaction::where('order_id', $order->id)->delete();

        // Delete the order itself
        $order->delete();

        Log::info('Abandoned order deleted successfully');
    }

    /**
     * Get available payment gateways
     */
    public function getAvailablePaymentGateways(): array
    {
        return $this->gatewayManager->getAllPaymentServices();
    }

    /**
     * Switch to a different payment gateway
     */
    public function switchPaymentGateway(string $gatewayName): bool
    {
        $newGateway = $this->gatewayManager->getPaymentServiceByGateway($gatewayName);
        if ($newGateway && $newGateway->isConfigured()) {
            $this->paymentGateway = $newGateway;
            return true;
        }
        return false;
    }

    /**
     * Get current payment gateway info
     */
    public function getCurrentGatewayInfo(): ?array
    {
        if (!$this->paymentGateway) {
            return null;
        }

        return [
            'name' => get_class($this->paymentGateway),
            'environment' => method_exists($this->paymentGateway, 'getEnvironment') ? $this->paymentGateway->getEnvironment() : 'unknown',
            'configured' => $this->paymentGateway->isConfigured(),
        ];
    }

    /**
     * Check if payment system is ready
     */
    public function isReady(): bool
    {
        return $this->paymentGateway && $this->paymentGateway->isConfigured();
    }
}
