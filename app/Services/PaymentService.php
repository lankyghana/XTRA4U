<?php

namespace App\Services;

use App\Events\OrderCompleted;
use App\Jobs\SendOrderPlacedCommunications;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Support\Money;
use App\Support\PaymentIntegrity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    protected GatewayManager $gatewayManager;

    /**
     * Legacy handle for a manually-switched collection gateway. Actual payment
     * routing goes through $gatewayManager; this is only read back by
     * switchPaymentGateway()/getCurrentGatewayInfo(). Declared (not dynamic) so
     * reading it never trips PHP 8.2's undefined-property error.
     */
    protected ?object $paymentGateway = null;

    public function __construct(?GatewayManager $gatewayManager = null)
    {
        $this->gatewayManager = $gatewayManager ?? new GatewayManager;
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

        // Purchase-only safety fallback:
        // If Moolre responds with a verification-code flow, try another active
        // collection gateway automatically to keep checkout moving.
        $result = $this->applyCheckoutFallbackForMoolreVerificationCode($order, $email, $amount, $result);

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
                'gateway_name' => $result['gateway_name'] ?? null,
                'order_id' => $order->id,
                // Only ever populated by gateways that launch a client-side SDK
                // popup (currently Payaza) — see PayazaPaymentService::requestPayment().
                'flow_type' => $result['flow_type'] ?? null,
                'checkout_config' => $result['checkout_config'] ?? null,
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
     *
     * HARD FINANCIAL BOUNDARY. No order may create financial side effects
     * until the server has PROVEN that a trusted payment source satisfied
     * that exact order's immutable financial terms. That proof is carried by
     * `orders.payment_integrity_status` (see {@see PaymentIntegrity}), which
     * only PaymentIntegrityGuard — or one of the explicitly modelled
     * non-gateway trusted sources (vendor wallet debit, admin confirmation) —
     * can write.
     *
     * The check lives HERE rather than only in the callers because the
     * callers are many and growing: a browser verify endpoint, three provider
     * webhooks, a redirect callback, a reconciliation sweep, two admin
     * actions, and whatever integration is added next. Any of them could
     * forget the check; none of them can forget this one.
     */
    public function completeOrder(Order $order): bool
    {
        // Fast-path idempotency check.
        // Concurrency safety is enforced by lockForUpdate() inside the transaction below.
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return true;
        }

        if (! $this->maySettle($order)) {
            return false;
        }

        $postCommit = [];

        $didComplete = DB::transaction(function () use ($order, &$postCommit) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Idempotency: avoid double-crediting wallets / duplicate transactions.
            if (in_array($locked->payment_status, ['paid', 'completed'], true)) {
                return true;
            }

            // Re-check under the row lock: the integrity status could have
            // been changed (e.g. stamped MISMATCH by a concurrent webhook)
            // between the fast-path check above and acquiring this lock.
            if (! $this->maySettle($locked)) {
                return false;
            }

            if ($locked->is_reseller_order && $locked->reseller_product_id) {
                return $this->completeResellerOrder($locked, $postCommit);
            }

            return $this->completeRegularOrder($locked, $postCommit);
        });

        if ($didComplete && ! empty($postCommit)) {
            $this->dispatchPostCommitActions($postCommit);
        }

        return $didComplete;
    }

    /**
     * The settlement gate. Refuses — loudly, but without mutating anything —
     * any order whose payment has not been proven by a trusted source.
     */
    private function maySettle(Order $order): bool
    {
        if ($order->allowsSettlement()) {
            return true;
        }

        Log::error('PaymentService::completeOrder refused — payment integrity not established for this order', [
            'order_id' => $order->id,
            'payment_integrity_status' => $order->payment_integrity_status,
            'payment_integrity_note' => $order->payment_integrity_note,
            'payment_status' => $order->payment_status,
            'payment_gateway' => $order->payment_gateway,
            'payment_reference' => $order->payment_reference,
            'expected_amount' => $order->expected_amount,
            'amount_paid' => $order->amount_paid,
        ]);

        return false;
    }

    /**
     * The amount this order is settled against.
     *
     * Prefers the immutable snapshot frozen at creation. `amount_paid` is used
     * only for historical orders that predate the snapshot — never to override
     * it, so a gateway that confirmed a different figure can never change what
     * the platform pays out.
     */
    private function settlementAmountFor(Order $order): float
    {
        if ($order->hasPricingSnapshot()) {
            return (float) $order->expected_amount;
        }

        return (float) $order->amount_paid;
    }

    /**
     * The frozen base/markup split for a reseller order.
     *
     * This is the fix for the settlement path re-reading live pricing: the
     * owner is paid the base price THIS ORDER was created against and the
     * reseller the markup THIS ORDER was created against, regardless of what
     * the main vendor's product price or the reseller's markup have since
     * become. Price changes apply to new orders only.
     *
     * Historical orders (no snapshot) fall back to the live listing exactly as
     * before, but the drift is asserted against what was actually collected
     * and reported when it disagrees.
     *
     * @return array{base: float, markup: float, source: string}
     */
    private function resellerSettlementTerms(Order $order, ResellerProduct $resellerProduct): array
    {
        // What this order is authoritatively owed. Every split below must add
        // up to exactly this — the platform must never pay out more than was
        // collected, and must never silently pay out nothing.
        $total = $this->settlementAmountFor($order);

        // 1. The split frozen onto this order at creation, when it is present
        //    and internally consistent. This is the normal path.
        if ($order->hasPricingSnapshot() && $order->base_price !== null) {
            $frozenBase = (float) $order->base_price;
            $frozenMarkup = (float) $order->markup_price;

            if (Money::equals(Money::sum($frozenBase, $frozenMarkup), $total)) {
                return ['base' => $frozenBase, 'markup' => $frozenMarkup, 'source' => 'order_snapshot'];
            }

            Log::error('Reseller settlement: order has a pricing snapshot whose split does not add up to its expected amount', [
                'order_id' => $order->id,
                'frozen_base_price' => $frozenBase,
                'frozen_markup_price' => $frozenMarkup,
                'order_total' => $total,
            ]);
        }

        // 2. The live listing, but ONLY while it still reconciles with what
        //    this order owes — which is the case for a legacy order whose
        //    listing has not been re-priced since.
        $liveBase = (float) $resellerProduct->base_price;
        $liveMarkup = (float) $resellerProduct->markup_price;

        if (Money::equals(Money::sum($liveBase, $liveMarkup), $total)) {
            return ['base' => $liveBase, 'markup' => $liveMarkup, 'source' => 'live_listing'];
        }

        // 3. Neither reconciles — a legacy order whose listing was re-priced
        //    while it sat unresolved, or an inconsistent snapshot. Rather than
        //    paying out a total the customer never paid (or, worse, zero),
        //    honour the seller's markup up to the order total and give the
        //    remainder to the owner, so the books still balance to what was
        //    collected. Loud, because a human should look at it.
        $reconciledMarkup = min($liveMarkup, $total);

        Log::error('Reseller settlement: neither the frozen split nor the live listing reconciles with this order total; settling to the order total', [
            'order_id' => $order->id,
            'reseller_product_id' => $resellerProduct->id,
            'order_total' => $total,
            'live_base_price' => $liveBase,
            'live_markup_price' => $liveMarkup,
            'settled_markup' => $reconciledMarkup,
        ]);

        return [
            'base' => round($total - $reconciledMarkup, 2),
            'markup' => $reconciledMarkup,
            'source' => 'reconciled_to_order_total',
        ];
    }

    /**
     * Complete a regular (non-reseller) order
     */
    protected function completeRegularOrder(Order $order, array &$postCommit = []): bool
    {
        $amountPaid = $this->settlementAmountFor($order);
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
                'message' => "You have a new order for '{$order->service_purchased}'. Earning: GHS ".number_format($vendorEarnings, 2),
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
     * - That debit was recorded as the order's proof of payment
     *   (PaymentIntegrity::WALLET_VERIFIED). The wallet flow's trusted source
     *   is the atomic server-side debit, so the caller that performed the
     *   debit is what stamps it — this method verifies the stamp exists
     *   rather than assuming its own precondition, which is what keeps an
     *   un-debited order from being completed for free.
     */
    public function completeVendorWalletOrder(Order $order): bool
    {
        if (in_array($order->payment_status, ['paid', 'completed'], true)) {
            return true;
        }

        if (! $this->maySettle($order)) {
            return false;
        }

        $postCommit = [];

        $didComplete = DB::transaction(function () use ($order, &$postCommit) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->payment_status, ['paid', 'completed'], true)) {
                return true;
            }

            if (! $this->maySettle($locked)) {
                return false;
            }

            // We intentionally DO NOT run reseller or affiliate payout logic here.
            // Vendor wallet purchases from the dashboard are charged the base
            // price + platform fee up-front. The platform retains the fee and
            // vendor earnings are recorded via transactions but are NOT credited
            // to vendor wallets here to avoid automated credit loops.

            $basePrice = (float) ($locked->base_price ?? $locked->amount_paid ?? 0);
            $platformFee = (float) ($locked->platform_commission ?? round($basePrice * 0.02, 2));
            $vendorEarning = round(max(0, $basePrice - $platformFee), 2);

            // Choose which vendor receives the accounting transaction.
            // For reseller orders, the owner/vendor that should be credited
            // for earnings is `owner_vendor_id`. For regular orders we use
            // the order's `vendor_id`.
            $transactionVendorId = $locked->is_reseller_order && $locked->owner_vendor_id
                ? (int) $locked->owner_vendor_id
                : $locked->vendor_id;

            // Create or update a transaction row so accounting can reconcile.
            $this->upsertOrderTransaction($locked, $transactionVendorId, [
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

            // Notify parties: owner vendor should be notified of earnings
            // for reseller orders; the reseller (order.vendor_id) should
            // also receive a basic order notification so it appears in
            // their dashboard.
            if ($locked->is_reseller_order && $locked->owner_vendor_id) {
                // Notify owner vendor about affiliate sale and earning
                VendorNotification::create([
                    'vendor_id' => (int) $locked->owner_vendor_id,
                    'type' => VendorNotification::TYPE_AFFILIATE_ORDER,
                    'title' => 'New Affiliate Order',
                    'message' => "A reseller made a sale of your product '{$locked->service_purchased}'. Your earning: GHS ".number_format($vendorEarning, 2),
                    'order_id' => $locked->id,
                    'data' => [
                        'product_name' => $locked->service_purchased,
                        'amount' => $basePrice,
                        'earning' => $vendorEarning,
                        'reseller_vendor_id' => $locked->vendor_id,
                    ],
                ]);

                $postCommit[] = [
                    'type' => 'communications',
                    'order_id' => $locked->id,
                    'vendor_id' => (int) $locked->owner_vendor_id,
                    'role' => 'owner',
                    'earning' => $vendorEarning,
                    'sms_type' => 'affiliate',
                ];

                // Also notify the reseller (so the order shows up in their UI)
                if ($locked->vendor_id) {
                    VendorNotification::create([
                        'vendor_id' => $locked->vendor_id,
                        'type' => VendorNotification::TYPE_NEW_ORDER,
                        'title' => 'New Order Received',
                        'message' => "You have a new reseller order for '{$locked->service_purchased}'.",
                        'order_id' => $locked->id,
                        'data' => [
                            'product_name' => $locked->service_purchased,
                            'amount' => $basePrice,
                        ],
                    ]);

                    $postCommit[] = [
                        'type' => 'communications',
                        'order_id' => $locked->id,
                        'vendor_id' => $locked->vendor_id,
                        'role' => 'reseller',
                        'earning' => 0.0,
                        'sms_type' => 'order',
                    ];
                }
            } else {
                // Regular order: notify the vendor (owner)
                if ($locked->vendor_id) {
                    VendorNotification::create([
                        'vendor_id' => $locked->vendor_id,
                        'type' => VendorNotification::TYPE_NEW_ORDER,
                        'title' => 'New Order Received',
                        'message' => "You have a new order for '{$locked->service_purchased}'. Earning recorded: GHS ".number_format($vendorEarning, 2),
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

        if (! $resellerProduct) {
            Log::error('Reseller product not found for order', ['order_id' => $order->id]);

            return false;
        }

        // Multi-level reseller chain support (additive):
        // If this reseller product is sourced from an upstream reseller listing,
        // distribute earnings across the full chain. Single-level path stays unchanged.
        if (! empty($resellerProduct->source_reseller_product_id)) {
            return $this->completeMultiLevelResellerOrder($order, $resellerProduct, $postCommit);
        }

        // Settle against the terms frozen onto THIS order at creation, not
        // whatever the listing says now. A main vendor raising their price or
        // a reseller changing their markup after an order exists must not
        // change that order's economics — and must never be able to cause a
        // payout larger than what was actually collected.
        $terms = $this->resellerSettlementTerms($order, $resellerProduct);
        $basePrice = $terms['base'];
        $markupPrice = $terms['markup'];

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
                'message' => "A reseller made a sale of your product '{$order->service_purchased}'. Your earning: GHS ".number_format($ownerEarning, 2),
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
                'message' => "You have a new order for '{$order->service_purchased}'. Your markup earning: GHS ".number_format($resellerEarning, 2),
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
        $chainService = new AffiliateChainService;
        $payout = $chainService->computeResellerProductPayout($resellerProduct);

        // Chain-drift guard. The live chain is only an acceptable basis for
        // settlement while it still reconciles with the terms THIS order was
        // created against. If any listing in the chain has been re-priced
        // since — which is entirely legitimate, it just applies to new orders
        // — distributing from the live chain would pay out an amount the
        // customer never paid. Fall back to the order's own frozen split.
        if (($payout['ok'] ?? false) && $order->hasPricingSnapshot()) {
            $chainTotal = Money::sum(...array_map(
                static fn (array $line) => $line['amount'] ?? 0,
                $payout['lines'] ?? []
            ));

            if (! Money::equals($chainTotal, $order->expected_amount)) {
                Log::warning('Multi-level reseller chain has been re-priced since this order was created; settling from the order\'s frozen snapshot instead', [
                    'order_id' => $order->id,
                    'reseller_product_id' => $resellerProduct->id,
                    'live_chain_total' => $chainTotal,
                    'order_expected_amount' => (string) $order->expected_amount,
                ]);

                $payout = ['ok' => false, 'reason' => 'chain_repriced_since_order_created'];
            }
        }

        if (! ($payout['ok'] ?? false)) {
            Log::warning('Multi-level reseller payout computation failed; falling back to 2-party split', [
                'order_id' => $order->id,
                'reseller_product_id' => $resellerProduct->id,
                'reason' => $payout['reason'] ?? null,
            ]);

            // Fallback: the owner's portion is everything except the immediate
            // seller's markup. Both figures come from the order's frozen terms
            // when it has them, so a re-priced listing cannot inflate either.
            $fallbackTerms = $this->resellerSettlementTerms($order, $resellerProduct);
            $fallbackTotal = $order->hasPricingSnapshot()
                ? (float) $order->expected_amount
                : (float) $order->amount_paid;

            $fallbackOwnerAmount = max(0.0, round($fallbackTotal - $fallbackTerms['markup'], 2));
            $fallbackOwnerCommission = round($fallbackOwnerAmount * 0.02, 2);
            $fallbackOwnerEarning = round($fallbackOwnerAmount - $fallbackOwnerCommission, 2);

            $fallbackResellerAmount = $fallbackTerms['markup'];
            $fallbackResellerCommission = round($fallbackResellerAmount * 0.02, 2);
            $fallbackResellerEarning = round($fallbackResellerAmount - $fallbackResellerCommission, 2);

            $platformCommission = round($fallbackOwnerCommission + $fallbackResellerCommission, 2);

            $order->update([
                'status' => 'Processing',
                'payment_status' => 'paid',
                'payment_completed_at' => now(),
                // Frozen terms win; only a legacy order with no snapshot takes
                // these from the live listing (resellerSettlementTerms()).
                'base_price' => $fallbackTerms['base'],
                'markup_price' => $fallbackTerms['markup'],
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
            // Keep the order's frozen terms when it has them. The chain-drift
            // guard above has already established that the live chain totals
            // to exactly this order's expected amount, so the live per-listing
            // figures are only used for orders with no snapshot.
            ...($order->hasPricingSnapshot() ? [] : [
                'base_price' => (float) $resellerProduct->base_price,
                'markup_price' => (float) $resellerProduct->markup_price,
            ]),
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
                'message' => "A reseller made a sale of your product '{$order->service_purchased}'. Your earning: GHS ".number_format((float) $payout['owner_earning'], 2),
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
                'message' => "You have a new order for '{$order->service_purchased}'. Your markup earning: GHS ".number_format((float) $payout['immediate_seller_earning'], 2),
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
     * Check payment status
     */
    public function checkPaymentStatus(string $reference): array
    {
        return $this->gatewayManager->verifyCollection($reference);
    }

    /**
     * Verify a payment against the SPECIFIC gateway that created it. Use this
     * instead of checkPaymentStatus() whenever the caller already has (or can
     * look up) the payable's own `payment_gateway` value — i.e. for any
     * already-initialized Order/AfaRegistration/ResultCheckerOrder/
     * UssdSubscription. checkPaymentStatus() remains available for surfaces
     * that do not persist their originating gateway (currently: wallet
     * top-ups — see WalletTopup schema gap noted in the reconciliation audit).
     */
    public function checkPaymentStatusForGateway(string $reference, ?string $gatewayName): array
    {
        return $this->gatewayManager->verifyCollectionWithGateway($gatewayName, $reference);
    }

    /**
     * Look up which gateway a given payment reference was actually created
     * under, by checking every payable table that stores one. Used by
     * endpoints (like PaymentStatusController) that only receive a bare
     * reference and don't already have the owning record loaded.
     *
     * Deliberately does NOT infer the gateway from the reference's format —
     * only ever reads the persisted `payment_gateway` column. Returns null
     * when no matching payable is found (caller should treat verification as
     * unresolved, not fall back to guessing a gateway).
     */
    public function resolvePayableGateway(string $reference): ?string
    {
        $order = Order::where('payment_reference', $reference)->first(['payment_gateway']);
        if ($order) {
            return $order->payment_gateway;
        }

        $afa = \App\Models\AfaRegistration::query()
            ->where('payment_reference', $reference)
            ->orWhere('reference', $reference)
            ->first(['payment_gateway']);
        if ($afa) {
            return $afa->payment_gateway;
        }

        $resultChecker = \App\Models\ResultCheckerOrder::where('payment_reference', $reference)->first(['payment_gateway']);
        if ($resultChecker) {
            return $resultChecker->payment_gateway;
        }

        $ussd = \App\Models\UssdSubscription::where('payment_reference', $reference)->first(['payment_gateway']);
        if ($ussd) {
            return $ussd->payment_gateway;
        }

        return null;
    }

    /**
     * Get wallet balance
     */
    public function getBalance(): array
    {
        // Balance is not standardized across payout providers in this codebase.
        $payoutService = $this->gatewayManager->getPayoutService();

        if (! $payoutService || ! method_exists($payoutService, 'getBalance')) {
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
            if (! $resellerProduct) {
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
        if (! $this->paymentGateway) {
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
        // Source of truth is the active default collection gateway from the
        // gateway manager — not $this->paymentGateway, which is only populated
        // by the rarely-used switchPaymentGateway() and is null in normal flows.
        $gateway = $this->gatewayManager->getPaymentService();

        return $gateway !== null && $gateway->isConfigured();
    }

    private function extractGatewayTransactionId(array $payload): ?string
    {
        $candidates = [
            $payload['transaction_id'] ?? null,
            $payload['transactionid'] ?? null,
            data_get($payload, 'data.transaction_id'),
            data_get($payload, 'data.transactionid'),
            data_get($payload, 'data.payaza_reference'),
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

    private function applyCheckoutFallbackForMoolreVerificationCode(
        Order $order,
        string $email,
        float $amount,
        array $primaryResult
    ): array {
        if (! $this->shouldFallbackFromMoolreVerificationCode($primaryResult)) {
            return $primaryResult;
        }

        $fallbackResult = $this->attemptAlternativeCheckoutGateway($order, $email, $amount);
        if ($fallbackResult !== null) {
            Log::warning('Checkout gateway fallback applied after Moolre verification-code response', [
                'order_id' => $order->id,
                'from_gateway' => PaymentGatewayConfig::GATEWAY_MOOLRE,
                'to_gateway' => $fallbackResult['gateway_name'] ?? null,
                'primary_message' => $primaryResult['message'] ?? null,
            ]);

            return $fallbackResult;
        }

        Log::warning('Checkout gateway fallback unavailable after Moolre verification-code response', [
            'order_id' => $order->id,
            'primary_message' => $primaryResult['message'] ?? null,
        ]);

        return $primaryResult;
    }

    private function shouldFallbackFromMoolreVerificationCode(array $result): bool
    {
        $gatewayName = strtolower((string) (
            $result['gateway_name']
            ?? $this->gatewayManager->getDefaultGatewayName(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
            ?? ''
        ));

        if ($gatewayName !== PaymentGatewayConfig::GATEWAY_MOOLRE) {
            return false;
        }

        $message = strtolower(trim((string) ($result['message'] ?? '')));
        if ($message === '') {
            return false;
        }

        // Only fallback when Moolre explicitly indicates verification code flow.
        return str_contains($message, 'verification code')
            || str_contains($message, 'phone no. verification code')
            || str_contains($message, 'phone no verification code')
            || str_contains($message, 'phone number verification code');
    }

    private function attemptAlternativeCheckoutGateway(Order $order, string $email, float $amount): ?array
    {
        $services = $this->gatewayManager->getAllPaymentServices();

        foreach ($services as $gatewayName => $entry) {
            if (! is_string($gatewayName) || strtolower($gatewayName) === PaymentGatewayConfig::GATEWAY_MOOLRE) {
                continue;
            }

            $service = $entry['service'] ?? null;
            if (! is_object($service) || ! method_exists($service, 'requestPayment')) {
                continue;
            }

            try {
                $candidate = $service->requestPayment($order, $email, $amount);
            } catch (\Throwable $e) {
                Log::warning('Checkout fallback gateway threw during payment init', [
                    'order_id' => $order->id,
                    'gateway' => $gatewayName,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_array($candidate)) {
                continue;
            }

            $candidate['gateway_name'] = $gatewayName;

            if ((bool) ($candidate['success'] ?? false)) {
                return $candidate;
            }
        }

        return null;
    }
}
