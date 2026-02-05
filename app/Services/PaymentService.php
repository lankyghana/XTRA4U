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
        // Create pending transaction(s) immediately so admin can audit/confirm payments
        // even if the gateway init call times out or the customer never returns.
        $this->ensurePendingTransactions($order);

        // All collection flows go through GatewayManager.
        $result = $this->gatewayManager->collect($order, $email, $amount);

        // Persist gateway transaction/tax id whenever a gateway returns one at initiation time.
        // Not all gateways provide this at init; when missing, we'll try again at verification.
        $gatewayTransactionId = $this->extractGatewayTransactionId($result);
        if (is_string($gatewayTransactionId) && $gatewayTransactionId !== '') {
            Transaction::where('order_id', $order->id)
                ->whereNull('gateway_transaction_id')
                ->update(['gateway_transaction_id' => $gatewayTransactionId]);
        }

        // Persist reference whenever we have one (even if init failed/timeout).
        // Gateways typically generate a reference client-side before making the HTTP call,
        // so this helps manual verification when the init response is lost.
        $reference = $result['reference'] ?? null;
        if (is_string($reference) && $reference !== '' && empty($order->payment_reference)) {
            $order->update([
                'payment_reference' => $reference,
                // Keep the canonical lifecycle in this app: unpaid -> paid
                'payment_status' => $order->payment_status ?: 'unpaid',
            ]);
        }

        if ($result['success']) {
            $order->update([
                'payment_reference' => $result['reference'],
                // Canonical lifecycle: unpaid -> paid
                'payment_status' => 'unpaid',
            ]);

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
     * Complete an order that was paid with a vendor wallet.
     *
     * This is a vendor-only, isolated flow that MUST NOT call reseller/affiliate
     * logic or credit vendor wallets. It records transactions/notifications and
     * marks the order as paid. It is intentionally separate from
     * `completeRegularOrder` to avoid accidental reuse for customer flows.
     *
     * Preconditions:
     * - Order->payment_source is 'wallet'
     * - The paying vendor already had their wallet debited for the total
     *   (base_price + platform_commission).
     */
    public function completeVendorWalletOrder(Order $order): bool
    {
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return true;
        }

        $postCommit = [];

        $didComplete = DB::transaction(function () use ($order, &$postCommit) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->payment_status, ['paid', 'completed'], true)) {
                return true;
            }

            // We intentionally DO NOT run reseller or affiliate payout logic here.
            // Vendor wallet purchases from the dashboard are charged the base
            // price + platform fee up-front. The platform retains the fee and
            // vendor earnings are recorded via transactions but are NOT credited
            // to vendor wallets here to avoid automated credit loops.

            $basePrice = (float) ($locked->base_price ?? $locked->amount_paid ?? 0);
            $platformFee = (float) ($locked->platform_commission ?? round($basePrice * 0.02, 2));
            $vendorEarning = round(max(0, $basePrice - $platformFee), 2);

            // Create or update a transaction row so accounting can reconcile.
            $this->upsertOrderTransaction($locked, $locked->vendor_id, [
                'recipient_phone' => $locked->recipient_phone_number,
                'amount' => $basePrice,
                'commission_amount' => $platformFee,
                'vendor_earning' => $vendorEarning,
                'payment_status' => 'successful',
            ]);

            // Mark order as paid/processing.
            $locked->update([
                'status' => 'Processing',
                'payment_status' => 'paid',
                'payment_completed_at' => now(),
            ]);

            // Notify product owner (vendor) and admin. We do NOT credit wallets here.
            if ($locked->vendor_id) {
                VendorNotification::create([
                    'vendor_id' => $locked->vendor_id,
                    'type' => VendorNotification::TYPE_NEW_ORDER,
                    'title' => 'New Order Received',
                    'message' => "You have a new order for '{$locked->service_purchased}'. Earning recorded: GHS " . number_format($vendorEarning, 2),
                    'order_id' => $locked->id,
                    'data' => [
                        'product_name' => $locked->service_purchased,
                        'amount' => $basePrice,
                        'earning' => $vendorEarning,
                    ],
                ]);

                $postCommit[] = [
                    'type' => 'communications',
                    'order_id' => $locked->id,
                    'vendor_id' => $locked->vendor_id,
                    'role' => 'owner',
                    'earning' => $vendorEarning,
                    'sms_type' => 'order',
                ];
            }

            AdminNotification::notifyNewOrder($locked);

            $postCommit[] = [
                'type' => 'event',
                'order_id' => $locked->id,
            ];

            return true;
        });

        if ($didComplete && ! empty($postCommit)) {
            $this->dispatchPostCommitActions($postCommit);
        }

        return $didComplete;
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

        // Multi-level reseller chain support (additive):
        // If this reseller product is sourced from an upstream reseller listing,
        // distribute earnings across the full chain. Single-level path stays unchanged.
        if (!empty($resellerProduct->source_reseller_product_id)) {
            return $this->completeMultiLevelResellerOrder($order, $resellerProduct, $postCommit);
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

    /**
     * Multi-level reseller order completion.
     *
     * Preserves existing external/customer flow and order schema.
     * Payouts are split across owner + all resellers in the reseller product chain.
     */
    protected function completeMultiLevelResellerOrder(Order $order, ResellerProduct $resellerProduct, array &$postCommit = []): bool
    {
        $chainService = new AffiliateChainService();
        $payout = $chainService->computeResellerProductPayout($resellerProduct);

        if (!($payout['ok'] ?? false)) {
            Log::warning('Multi-level reseller payout computation failed; falling back to 2-party split', [
                'order_id' => $order->id,
                'reseller_product_id' => $resellerProduct->id,
                'reason' => $payout['reason'] ?? null,
            ]);

            // Fallback: treat everything except immediate seller markup as the owner's portion.
            $fallbackOwnerAmount = max(0.0, round(((float) $order->amount_paid) - (float) $resellerProduct->markup_price, 2));
            $fallbackOwnerCommission = round($fallbackOwnerAmount * 0.02, 2);
            $fallbackOwnerEarning = round($fallbackOwnerAmount - $fallbackOwnerCommission, 2);

            $fallbackResellerAmount = (float) $resellerProduct->markup_price;
            $fallbackResellerCommission = round($fallbackResellerAmount * 0.02, 2);
            $fallbackResellerEarning = round($fallbackResellerAmount - $fallbackResellerCommission, 2);

            $platformCommission = round($fallbackOwnerCommission + $fallbackResellerCommission, 2);

            $order->update([
                'status' => 'Processing',
                'payment_status' => 'paid',
                'payment_completed_at' => now(),
                'base_price' => (float) $resellerProduct->base_price,
                'markup_price' => (float) $resellerProduct->markup_price,
                'owner_earning' => $fallbackOwnerEarning,
                'reseller_earning' => $fallbackResellerEarning,
                'platform_commission' => $platformCommission,
                'owner_vendor_id' => $resellerProduct->owner_vendor_id,
                'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                'is_reseller_order' => true,
            ]);

            $this->upsertOrderTransaction($order, (int) $resellerProduct->owner_vendor_id, [
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => $fallbackOwnerAmount,
                'commission_amount' => $fallbackOwnerCommission,
                'vendor_earning' => $fallbackOwnerEarning,
                'payment_status' => 'successful',
            ]);

            $this->upsertOrderTransaction($order, (int) $resellerProduct->reseller_vendor_id, [
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => $fallbackResellerAmount,
                'commission_amount' => $fallbackResellerCommission,
                'vendor_earning' => $fallbackResellerEarning,
                'payment_status' => 'successful',
            ]);

            Vendor::whereKey((int) $resellerProduct->owner_vendor_id)->increment('wallet_balance', $fallbackOwnerEarning);
            Vendor::whereKey((int) $resellerProduct->reseller_vendor_id)->increment('wallet_balance', $fallbackResellerEarning);

            AdminNotification::notifyNewOrder($order);
            $postCommit[] = ['type' => 'event', 'order_id' => $order->id];

            return true;
        }

        $resolvedServiceName = $resellerProduct->product?->name ?? $order->service?->name;

        // Persist order-facing fields; keep vendor_id semantics unchanged (seller/vendor storefront).
        $order->update([
            'status' => 'Processing',
            'payment_status' => 'paid',
            'payment_completed_at' => now(),
            'base_price' => (float) $resellerProduct->base_price,
            'markup_price' => (float) $resellerProduct->markup_price,
            'owner_earning' => (float) $payout['owner_earning'],
            'reseller_earning' => (float) $payout['immediate_seller_earning'],
            'platform_commission' => (float) $payout['platform_commission'],
            'affiliate_chain_snapshot' => $payout['snapshot'] ?? null,
            'owner_vendor_id' => $resellerProduct->owner_vendor_id,
            'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
            'is_reseller_order' => true,
            ...($resellerProduct->product?->id ? ['vendor_service_id' => $resellerProduct->product->id] : []),
            ...($resolvedServiceName ? ['service_purchased' => $resolvedServiceName] : []),
        ]);

        // Upsert transactions and credit wallets for each party in the chain.
        foreach (($payout['lines'] ?? []) as $line) {
            $vendorId = (int) ($line['vendor_id'] ?? 0);
            if ($vendorId <= 0) {
                continue;
            }

            $this->upsertOrderTransaction($order, $vendorId, [
                'recipient_phone' => $order->recipient_phone_number,
                'amount' => (float) ($line['amount'] ?? 0),
                'commission_amount' => (float) ($line['commission'] ?? 0),
                'vendor_earning' => (float) ($line['earning'] ?? 0),
                'payment_status' => 'successful',
            ]);

            $earning = (float) ($line['earning'] ?? 0);
            if ($earning > 0) {
                Vendor::whereKey($vendorId)->increment('wallet_balance', $earning);
            }
        }

        // Notifications: keep existing behavior for owner + immediate seller.
        $ownerVendor = $resellerProduct->ownerVendor;
        $immediateSeller = $resellerProduct->resellerVendor;

        if ($ownerVendor) {
            VendorNotification::create([
                'vendor_id' => $ownerVendor->id,
                'type' => VendorNotification::TYPE_AFFILIATE_ORDER,
                'title' => 'New Affiliate Order',
                'message' => "A reseller made a sale of your product '{$order->service_purchased}'. Your earning: GHS " . number_format((float) $payout['owner_earning'], 2),
                'order_id' => $order->id,
                'data' => [
                    'product_name' => $order->service_purchased,
                    'earning' => (float) $payout['owner_earning'],
                    'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                ],
            ]);

            $postCommit[] = [
                'type' => 'communications',
                'order_id' => $order->id,
                'vendor_id' => $ownerVendor->id,
                'role' => 'owner',
                'earning' => (float) $payout['owner_earning'],
                'sms_type' => 'affiliate',
            ];
        }

        if ($immediateSeller) {
            VendorNotification::create([
                'vendor_id' => $immediateSeller->id,
                'type' => VendorNotification::TYPE_NEW_ORDER,
                'title' => 'New Order Received',
                'message' => "You have a new order for '{$order->service_purchased}'. Your markup earning: GHS " . number_format((float) $payout['immediate_seller_earning'], 2),
                'order_id' => $order->id,
                'data' => [
                    'product_name' => $order->service_purchased,
                    'markup_price' => (float) $resellerProduct->markup_price,
                    'earning' => (float) $payout['immediate_seller_earning'],
                ],
            ]);

            $postCommit[] = [
                'type' => 'communications',
                'order_id' => $order->id,
                'vendor_id' => $immediateSeller->id,
                'role' => 'reseller',
                'earning' => (float) $payout['immediate_seller_earning'],
                'sms_type' => 'order',
            ];
        }

        AdminNotification::notifyNewOrder($order);
        $postCommit[] = ['type' => 'event', 'order_id' => $order->id];

        Log::info('Multi-level reseller order completed with split payment', [
            'order_id' => $order->id,
            'owner_vendor_id' => $resellerProduct->owner_vendor_id,
            'immediate_seller_vendor_id' => $resellerProduct->reseller_vendor_id,
            'platform_commission' => $payout['platform_commission'] ?? null,
            'depth' => $payout['depth'] ?? null,
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
            $gatewayTransactionId = $this->extractGatewayTransactionId($result);
            if (is_string($gatewayTransactionId) && $gatewayTransactionId !== '') {
                Transaction::where('order_id', $order->id)
                    ->whereNull('gateway_transaction_id')
                    ->update(['gateway_transaction_id' => $gatewayTransactionId]);
            }

            Log::info('Webhook: Order already completed', ['order_id' => $order->id]);
            return [
                'success' => true,
                'message' => 'Order already processed',
                'order_id' => $order->id,
            ];
        }

        // Backfill gateway transaction/tax id on verification if it wasn't available at initiation.
        $gatewayTransactionId = $this->extractGatewayTransactionId($result);
        if (is_string($gatewayTransactionId) && $gatewayTransactionId !== '') {
            Transaction::where('order_id', $order->id)
                ->whereNull('gateway_transaction_id')
                ->update(['gateway_transaction_id' => $gatewayTransactionId]);
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
            $isFinal = in_array($transaction->payment_status, ['completed', 'successful'], true);

            // If an admin prematurely marked a placeholder transaction as successful/completed,
            // we still need to backfill financial fields (commission/earning). This keeps
            // reporting consistent with wallet credits.
            $existingCommission = (float) ($transaction->commission_amount ?? 0);
            $existingEarning = (float) ($transaction->vendor_earning ?? 0);
            $incomingCommission = (float) ($attributes['commission_amount'] ?? 0);
            $incomingEarning = (float) ($attributes['vendor_earning'] ?? 0);

            $isPlaceholderFinancials = $existingCommission === 0.0
                && $existingEarning === 0.0
                && ($incomingCommission > 0.0 || $incomingEarning > 0.0);

            if ($isFinal && ! $isPlaceholderFinancials) {
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

    private function extractGatewayTransactionId(array $payload): ?string
    {
        $candidates = [
            $payload['transaction_id'] ?? null,
            $payload['transactionid'] ?? null,
            data_get($payload, 'data.transaction_id'),
            data_get($payload, 'data.transactionid'),
            data_get($payload, 'data.id'),
            data_get($payload, 'data.data.transaction_id'),
            data_get($payload, 'data.data.transactionid'),
        ];

        foreach ($candidates as $value) {
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }
}
