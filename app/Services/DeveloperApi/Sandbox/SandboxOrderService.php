<?php

namespace App\Services\DeveloperApi\Sandbox;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\DeveloperApplication;
use App\Models\Developer\SandboxOrder;
use App\Services\DeveloperApi\PublicCatalogService;
use App\Services\DeveloperApi\QuoteService;
use App\Services\Payments\OrderPricingSnapshot;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use App\Support\GhanaPhoneNumber;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and looks up SANDBOX orders. Mirrors ApiOrderService's rules (single-use quotes, re-pricing under
 * current rules, idempotency) but writes ONLY to developer_sandbox_orders. It never touches the production
 * `orders` table, the payment gateways, wallets, commissions or inventory.
 *
 * Reads (catalog resolution, pricing arithmetic) reuse the production vendor-scoped catalog and the same
 * OrderPricingSnapshot, so a sandbox quote is priced exactly as the live one would be.
 */
class SandboxOrderService
{
    public const ENV = 'sandbox';

    public function __construct(
        private readonly PublicCatalogService $catalog,
        private readonly QuoteService $quotes,
        private readonly SandboxOrderTransitions $transitions,
        private readonly SandboxEventRecorder $events,
    ) {}

    /**
     * @return array{order: SandboxOrder, created: bool}
     */
    public function createFromQuote(
        DeveloperApplication $app,
        ?int $apiKeyId,
        string $quoteReference,
        string $idempotencyKey,
        ?string $payerPhone,
        ?string $payerNetwork,
        ?string $clientReference,
    ): array {
        // Environment-scoped: a production quote is invisible here, as a sandbox quote is to production.
        $quote = $this->quotes->findForApplication($app, $quoteReference, self::ENV);
        if (! $quote) {
            throw new ApiException(ApiError::QUOTE_NOT_FOUND, 'Quote not found.', 404);
        }

        if ($existing = $this->existingForKey($app, $idempotencyKey)) {
            return ['order' => $this->assertSameIntent($existing, $quote), 'created' => false];
        }

        if ($quote->consumed_at) {
            throw ApiException::conflict(ApiError::QUOTE_ALREADY_USED, 'This quote has already been used to create an order.');
        }
        if ($quote->isExpired()) {
            throw new ApiException(ApiError::QUOTE_EXPIRED, 'This quote has expired. Request a new quote.', 410);
        }

        // Re-resolve under CURRENT rules (same vendor-scoped catalog as production).
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

        $offer = $resolved['offer'];
        $pendingEvents = [];

        try {
            $order = DB::transaction(function () use ($app, $apiKeyId, $quote, $resolved, $offer, $idempotencyKey, $payer, $payerNetwork, $clientReference, &$pendingEvents) {
                $locked = ApiQuote::query()->lockForUpdate()->findOrFail($quote->id);
                if ($locked->consumed_at) {
                    throw ApiException::conflict(ApiError::QUOTE_ALREADY_USED, 'This quote has already been used to create an order.');
                }

                $order = new SandboxOrder;
                $order->forceFill([
                    // Ownership comes from the authenticated application, never from the request body.
                    'order_ref' => 'sbx_ord_'.Str::lower((string) Str::ulid()),
                    'vendor_id' => $app->vendor_id,
                    'application_id' => $app->id,
                    'api_key_id' => $apiKeyId,
                    'quote_reference' => $quote->reference,
                    'client_reference' => $clientReference,
                    'idempotency_key' => $idempotencyKey,
                    'offer_id' => $quote->offer_id,
                    'product_id' => $resolved['product']->id,
                    'reseller_product_id' => $resolved['reseller']?->id,
                    // Frozen: later product/price edits never alter this order.
                    'product_snapshot' => ['offer' => $offer, 'quoted_at' => $quote->created_at?->toIso8601String()],
                    'recipient_phone' => $quote->recipient_phone,
                    'payer_phone' => $payer,
                    'payer_network' => $payerNetwork,
                    'network' => $offer['network'] ?? null,
                    'quantity' => 1,
                    'unit_price' => $quote->amount,
                    'total_amount' => $quote->amount,
                    'currency' => $quote->currency,
                    'status' => 'awaiting_payment',
                    'payment_status' => 'unpaid',
                    'fulfillment_status' => 'not_started',
                ])->save();

                $locked->forceFill(['consumed_at' => now(), 'order_id' => $order->id])->save();

                $pendingEvents = $this->transitions->recordCreated($order);

                return $order;
            });
        } catch (QueryException $e) {
            // Lost a race on the same idempotency key / quote: the winner's order is the answer.
            if ($existing = $this->existingForKey($app, $idempotencyKey)) {
                return ['order' => $this->assertSameIntent($existing, $quote), 'created' => false];
            }
            throw $e;
        }

        $this->events->publish($pendingEvents);

        return ['order' => $order->refresh(), 'created' => true];
    }

    /** Ownership is part of the query: another vendor's/application's order is indistinguishable from a missing one. */
    public function findForApplication(DeveloperApplication $app, string $orderRef): ?SandboxOrder
    {
        return SandboxOrder::query()
            ->where('application_id', $app->id)
            ->where('vendor_id', $app->vendor_id)
            ->where('order_ref', $orderRef)
            ->first();
    }

    private function existingForKey(DeveloperApplication $app, string $key): ?SandboxOrder
    {
        return SandboxOrder::query()
            ->where('application_id', $app->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    /** The same Idempotency-Key reused for a DIFFERENT quote is a client bug, never a silent replay. */
    private function assertSameIntent(SandboxOrder $existing, ApiQuote $quote): SandboxOrder
    {
        if ($existing->quote_reference !== $quote->reference) {
            throw ApiException::conflict(ApiError::IDEMPOTENCY_CONFLICT, 'This Idempotency-Key was already used for a different request.');
        }

        return $existing;
    }
}
