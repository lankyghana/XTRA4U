<?php
namespace App\Services;

use App\Events\OrderCompleted;
use App\Jobs\SendOrderPlacedCommunications;
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

class PaymentService
{
    protected GatewayManager $gatewayManager;
    protected ?SmsService $smsService;

    public function __construct(?GatewayManager $gatewayManager = null)
    {
        $this->gatewayManager = $gatewayManager ?? new GatewayManager();
        $this->smsService = $this->gatewayManager->getSmsService();
    }

    /**
     * Initiate payment collection from customer
     */
    public function initiatePayment(Order $order, string $email, float $amount): array
    {
        // All collection flows go through GatewayManager.
        $result = $this->gatewayManager->collect($order, $email, $amount);

        if ($result['success']) {
            $order->update([
                'payment_reference' => $result['reference'],
                // Canonical lifecycle: unpaid -> paid
                'payment_status' => 'unpaid',
            ]);

			// Payment lifecycle: create pending transaction(s) at checkout initiation.
			$this->ensurePendingTransactions($order);
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
     * Initiate a generic payment (non-Order flows like AFA Registration).
     * Uses the active default payment gateway selected in /admin/payment-gateways.
     */
    public function initiateGenericPayment(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $metadata = []): array
    {
        return $this->gatewayManager->genericPayment([
            'email' => $email,
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'reference' => $reference,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Complete an order after successful payment
     * This handles all post-payment logic: transactions, notifications, emails, SMS, wallet updates
     */
    public function completeOrder(Order $order): bool
    {
        // Fast-path idempotency check.
        // Concurrency safety is enforced by lockForUpdate() inside the transaction below.
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return true;
        }

        $postCommit = [];

        $didComplete = DB::transaction(function () use ($order, &$postCommit) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Idempotency: avoid double-crediting wallets / duplicate transactions.
            if (in_array($locked->payment_status, ['paid', 'completed'], true)) {
                return true;
            }

            if ($locked->is_reseller_order && $locked->reseller_product_id) {
                return $this->completeResellerOrder($locked, $postCommit);
            }

            return $this->completeRegularOrder($locked, $postCommit);
        });

        if ($didComplete && !empty($postCommit)) {
            $this->dispatchPostCommitActions($postCommit);
        }

        return $didComplete;
    }

    /**
     * Complete a regular (non-reseller) order
     */
    protected function completeRegularOrder(Order $order, array &$postCommit = []): bool
    {
        $amountPaid = (float) $order->amount_paid;
        $commission = round($amountPaid * 0.02, 2);
        $vendorEarnings = round($amountPaid - $commission, 2);

		// Prefer resolved product name over legacy/raw identifiers.
		$resolvedServiceName = $order->service?->name;

		// Update or create transaction (lifecycle: pending -> completed)
		$this->upsertOrderTransaction($order, $order->vendor_id, [
			'recipient_phone' => $order->recipient_phone_number,
			'amount' => $amountPaid,
			'commission_amount' => $commission,
			'vendor_earning' => $vendorEarnings,
            'payment_status' => 'successful',
		]);

        // Update order status
        $order->update([
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
			// Normalize display name whenever possible.
			...($resolvedServiceName ? ['service_purchased' => $resolvedServiceName] : []),
        ]);

        // Update vendor wallet balance
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

			// External side-effects must not run inside DB transactions.
			$postCommit[] = [
				'type' => 'communications',
				'order_id' => $order->id,
				'vendor_id' => $vendor->id,
				'role' => 'owner',
				'earning' => $vendorEarnings,
				'sms_type' => 'order',
			];
        }

        // Send admin notification
        AdminNotification::notifyNewOrder($order);

        // Dispatch event after commit (listener is queued).
        $postCommit[] = [
            'type' => 'event',
            'order_id' => $order->id,
        ];

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
    protected function completeResellerOrder(Order $order, array &$postCommit = []): bool
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

		$resolvedServiceName = $resellerProduct->product?->name ?? $order->service?->name;

		// Update order with earnings info
		$order->update([
			'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
            'base_price' => $basePrice,
            'markup_price' => $markupPrice,
            'owner_earning' => $ownerEarning,
            'reseller_earning' => $resellerEarning,
            'platform_commission' => $totalPlatformCommission,
			// Persist affiliate mapping on the order for consistent UI and reporting.
			'owner_vendor_id' => $resellerProduct->owner_vendor_id,
			'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
			'is_reseller_order' => true,
			...($resellerProduct->product?->id ? ['vendor_service_id' => $resellerProduct->product->id] : []),
			...($resolvedServiceName ? ['service_purchased' => $resolvedServiceName] : []),
        ]);

		// Update or create transactions (lifecycle: pending -> completed)
		$this->upsertOrderTransaction($order, $resellerProduct->owner_vendor_id, [
			'recipient_phone' => $order->recipient_phone_number,
			'amount' => $basePrice,
			'commission_amount' => $ownerCommission,
			'vendor_earning' => $ownerEarning,
            'payment_status' => 'successful',
		]);

		$this->upsertOrderTransaction($order, $resellerProduct->reseller_vendor_id, [
			'recipient_phone' => $order->recipient_phone_number,
			'amount' => $markupPrice,
			'commission_amount' => $resellerCommission,
			'vendor_earning' => $resellerEarning,
            'payment_status' => 'successful',
		]);

        // Update wallet balances
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

			$postCommit[] = [
				'type' => 'communications',
				'order_id' => $order->id,
				'vendor_id' => $ownerVendor->id,
				'role' => 'owner',
				'earning' => $ownerEarning,
				'sms_type' => 'affiliate',
			];
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

			$postCommit[] = [
				'type' => 'communications',
				'order_id' => $order->id,
				'vendor_id' => $resellerVendor->id,
				'role' => 'reseller',
				'earning' => $resellerEarning,
				'sms_type' => 'order',
			];
        }

        // Admin notification
        AdminNotification::notifyNewOrder($order);

        // Dispatch event after commit (listener is queued).
        $postCommit[] = [
            'type' => 'event',
            'order_id' => $order->id,
        ];

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

    private function dispatchPostCommitActions(array $actions): void
    {
        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if ($type === 'communications') {
                // Backward-compatible default: execute immediately (no queue worker required),
                // but still outside the DB transaction.
                // This job is queueable so the system can be switched to async later.
                SendOrderPlacedCommunications::dispatchSync(
                    (int) $action['order_id'],
                    (int) $action['vendor_id'],
                    (string) $action['role'],
                    (float) $action['earning'],
                    (string) ($action['sms_type'] ?? 'order')
                );
                continue;
            }

            if ($type === 'event') {
                $orderId = (int) ($action['order_id'] ?? 0);
                if ($orderId > 0) {
                    $order = Order::find($orderId);
                    if ($order) {
                        event(new OrderCompleted($order));
                    }
                }
            }
        }
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
        $result = $this->gatewayManager->verifyCollection($reference);

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
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
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
        } elseif ($paymentStatus === 'pending' || $paymentStatus === 'unknown') {
            Log::info('Webhook: Payment pending', [
                'order_id' => $order->id,
                'reference' => $reference,
                'status' => $paymentStatus,
            ]);
            return [
                'success' => true,
                'message' => 'Payment pending',
                'order_id' => $order->id,
            ];
        } else {
            $order->update([
                'payment_status' => 'failed',
                'status' => 'Failed',
            ]);
			$this->markOrderTransactionsFailed($order);
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
        return $this->gatewayManager->verifyCollection($reference);
    }

    /**
     * Get wallet balance
     */
    public function getBalance(): array
    {
        // Balance is not standardized across payout providers in this codebase.
        $payoutService = $this->gatewayManager->getPayoutService();

        if (!$payoutService || !method_exists($payoutService, 'getBalance')) {
            return [
                'success' => false,
                'message' => 'No active payout gateway configured.',
            ];
        }

        $balance = $payoutService->getBalance();
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

    private function ensurePendingTransactions(Order $order): void
    {
        // If pending/completed transactions already exist, don't create duplicates.
        $existingCount = Transaction::where('order_id', $order->id)->count();
        if ($existingCount > 0) {
            return;
        }

        if ($order->is_reseller_order && $order->reseller_product_id) {
            $resellerProduct = ResellerProduct::find($order->reseller_product_id);
            if (!$resellerProduct) {
                return;
            }

            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $resellerProduct->owner_vendor_id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => (float) $resellerProduct->base_price,
                'commission_amount' => 0,
                'vendor_earning' => 0,
                'payment_status' => 'pending',
            ]);

            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $resellerProduct->reseller_vendor_id,
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => (float) $resellerProduct->markup_price,
                'commission_amount' => 0,
                'vendor_earning' => 0,
                'payment_status' => 'pending',
            ]);

            return;
        }

        Transaction::create([
            'order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'recipient_phone' => $order->recipient_phone_number,
            'amount' => (float) $order->amount_paid,
            'commission_amount' => 0,
            'vendor_earning' => 0,
            'payment_status' => 'pending',
        ]);
    }

    /**
     * Update or create transaction for an order (legacy support)
     * Use upsertTransaction() for new code.
     */
    private function upsertOrderTransaction(Order $order, int $vendorId, array $attributes): void
    {
        $transaction = Transaction::where('order_id', $order->id)
            ->where('vendor_id', $vendorId)
            ->latest('id')
            ->first();

        if ($transaction) {
            if (in_array($transaction->payment_status, ['completed', 'successful'], true)) {
                return;
            }
            $transaction->update($attributes);
            return;
        }

        Transaction::create(array_merge([
            'order_id' => $order->id,
            'vendor_id' => $vendorId,
            'transactionable_type' => 'App\\Models\\Order',
            'transactionable_id' => $order->id,
            'payment_type' => 'order',
        ], $attributes));
    }

    /**
     * Create or update a polymorphic transaction record.
     * Use this for all new payment types (AFA, etc.)
     */
    private function upsertTransaction(
        \Illuminate\Database\Eloquent\Model $payable,
        string $paymentType,
        int $vendorId,
        array $attributes
    ): void {
        $transaction = Transaction::where('transactionable_type', get_class($payable))
            ->where('transactionable_id', $payable->id)
            ->where('vendor_id', $vendorId)
            ->latest('id')
            ->first();

        if ($transaction) {
            if (in_array($transaction->payment_status, ['completed', 'successful'], true)) {
                return;
            }
            $transaction->update($attributes);
            return;
        }

        Transaction::create(array_merge([
            'transactionable_type' => get_class($payable),
            'transactionable_id' => $payable->id,
            'vendor_id' => $vendorId,
            'payment_type' => $paymentType,
        ], $attributes));
    }

    private function markOrderTransactionsFailed(Order $order): void
    {
        Transaction::where('order_id', $order->id)
            ->whereNotIn('payment_status', ['completed', 'successful'])
            ->update(['payment_status' => 'failed']);
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
