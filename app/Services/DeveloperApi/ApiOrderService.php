<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\DeveloperApplication;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Services\GatewayManager;
use App\Services\Payments\OrderPricingSnapshot;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use App\Support\GhanaPhoneNumber;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and looks up production orders for API applications.
 *
 * Reuses the existing order model, frozen pricing snapshot and unique
 * (idempotency_scope, idempotency_key) index. It deliberately does NOT touch
 * CheckoutController. An order created here is a normal `orders` row: payment
 * confirmation, settlement, commission and fulfilment are performed by the
 * existing pipeline and by nothing in this class.
 */
class ApiOrderService
{
    public function __construct(
        private readonly PublicCatalogService $catalog,
        private readonly QuoteService $quotes,
        private readonly GatewayManager $gateways,
        private readonly \App\Services\DeveloperApi\Webhooks\DeveloperEventRecorder $events,
    ) {}

    public static function scopeFor(DeveloperApplication $app): string
    {
        // Distinct namespace from session/vendor/ussd scopes used by other flows.
        return 'api:app:'.$app->id;
    }

    /**
     * @return array{order: Order, created: bool}
     */
    public function createFromQuote(DeveloperApplication $app, string $quoteReference, string $idempotencyKey, ?string $payerPhone, ?string $payerNetwork): array
    {
        $quote = $this->quotes->findForApplication($app, $quoteReference);
        if (! $quote) {
            throw new ApiException(ApiError::QUOTE_NOT_FOUND, 'Quote not found.', 404);
        }

        // Replay of the same intent: return the existing order untouched.
        if ($existing = $this->existingForKey($app, $idempotencyKey)) {
            return ['order' => $this->assertSameIntent($existing, $quote), 'created' => false];
        }

        if ($quote->consumed_at) {
            throw ApiException::conflict(ApiError::QUOTE_ALREADY_USED, 'This quote has already been used to create an order.');
        }
        if ($quote->isExpired()) {
            throw new ApiException(ApiError::QUOTE_EXPIRED, 'This quote has expired. Request a new quote.', 410);
        }

        // Re-resolve under CURRENT rules: the offer may have been withdrawn, closed or repriced.
        $resolved = $this->catalog->resolveForPurchase($app, $quote->offer_id);
        if (! $resolved) {
            throw ApiException::conflict(ApiError::OFFER_UNAVAILABLE, 'This offer is no longer available.');
        }

        $pricing = $resolved['reseller']
            ? OrderPricingSnapshot::forResellerListing($resolved['reseller'])
            : OrderPricingSnapshot::forOwnedProduct($resolved['product']);

        if (! Money::equals($pricing['expected_amount'], $quote->amount)) {
            throw ApiException::conflict(ApiError::QUOTE_PRICE_CHANGED, 'The price has changed since this quote was issued. Request a new quote.', [
                'quoted_amount' => Money::toDecimalString($quote->amount),
                'current_amount' => Money::toDecimalString($pricing['expected_amount']),
            ]);
        }

        $payer = $payerPhone !== null && $payerPhone !== '' ? GhanaPhoneNumber::toLocal($payerPhone) : $quote->recipient_phone;
        if (! GhanaPhoneNumber::isValidLocal($payer)) {
            throw ApiException::unprocessable(ApiError::VALIDATION_FAILED, 'The payer phone number is not a valid Ghana mobile number.', [
                'fields' => ['payer_phone' => ['Invalid Ghana mobile number.']],
            ]);
        }

        $isReseller = $resolved['reseller'] !== null;
        $product = $resolved['product'];

        try {
            $order = DB::transaction(function () use ($app, $quote, $pricing, $resolved, $idempotencyKey, $payer, $payerNetwork, $isReseller, $product) {
                // Single-use quote, enforced under a row lock.
                $locked = ApiQuote::query()->lockForUpdate()->findOrFail($quote->id);
                if ($locked->consumed_at) {
                    throw ApiException::conflict(ApiError::QUOTE_ALREADY_USED, 'This quote has already been used to create an order.');
                }

                $order = new Order;
                $order->forceFill([
                    'recipient_phone_number' => $quote->recipient_phone,
                    'mobile_money_number' => $payer,
                    'mobile_money_network' => $payerNetwork,
                    'service_purchased' => $product->name,
                    'amount_paid' => $pricing['expected_amount'],
                    ...$pricing,
                    'vendor_id' => $resolved['vendor_id'],
                    'vendor_service_id' => $product->id,
                    'reseller_product_id' => $resolved['reseller']?->id,
                    'owner_vendor_id' => $isReseller ? ($resolved['reseller']->owner_vendor_id ?? $product->vendor_id) : null,
                    'reseller_vendor_id' => $isReseller ? $resolved['vendor_id'] : null,
                    'is_reseller_order' => $isReseller,
                    'status' => 'Pending',
                    'payment_status' => 'unpaid',
                    'payment_gateway' => $this->gateways->getDefaultGatewayName(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION) ?? 'paystack',
                    'idempotency_scope' => self::scopeFor($app),
                    'idempotency_key' => $idempotencyKey,
                    'developer_application_id' => $app->id,
                    'developer_order_ref' => 'ord_'.Str::lower((string) Str::ulid()),
                ])->save();

                $locked->forceFill(['consumed_at' => now(), 'order_id' => $order->id])->save();

                return $order;
            });
        } catch (QueryException $e) {
            // Lost a race on the same idempotency key: the winner's order is the answer.
            if ($existing = $this->existingForKey($app, $idempotencyKey)) {
                return ['order' => $this->assertSameIntent($existing, $quote), 'created' => false];
            }
            throw $e;
        }

        // Best effort: the sweeper records it if this fails; the unique (order, type) key prevents duplicates.
        try {
            $this->events->record($order, 'order.created');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('order.created event record failed', ['order_id' => $order->id]);
        }

        return ['order' => $order, 'created' => true];
    }

    /** Ownership is part of the query: another application's order is indistinguishable from a missing one. */
    public function findForApplication(DeveloperApplication $app, string $orderRef): ?Order
    {
        return Order::query()
            ->where('developer_application_id', $app->id)
            ->where('developer_order_ref', $orderRef)
            ->first();
    }

    public function findByPaymentReference(DeveloperApplication $app, string $reference): ?Order
    {
        return Order::query()
            ->where('developer_application_id', $app->id)
            ->where('payment_reference', $reference)
            ->first();
    }

    private function existingForKey(DeveloperApplication $app, string $key): ?Order
    {
        return Order::query()
            ->where('idempotency_scope', self::scopeFor($app))
            ->where('idempotency_key', $key)
            ->first();
    }

    /** The same Idempotency-Key reused for a DIFFERENT quote is a client bug, never a silent replay. */
    private function assertSameIntent(Order $existing, ApiQuote $quote): Order
    {
        if ((int) ApiQuote::query()->where('id', $quote->id)->value('order_id') !== (int) $existing->id) {
            throw ApiException::conflict(ApiError::IDEMPOTENCY_CONFLICT, 'This Idempotency-Key was already used for a different request.');
        }

        return $existing;
    }
}
