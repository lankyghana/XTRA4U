<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\ApiQuote;
use App\Models\Developer\DeveloperApplication;
use App\Services\Payments\OrderPricingSnapshot;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use App\Support\GhanaPhoneNumber;
use Illuminate\Support\Str;

/**
 * Quotes freeze a price for a short time so a developer can show the final
 * amount before ordering. A quote is a PROMISE OF A PRICE, not a reservation:
 * the order step re-prices from live data and refuses if it no longer matches.
 */
class QuoteService
{
    public function __construct(private readonly PublicCatalogService $catalog) {}

    /**
     * @param  string  $environment  taken from the authenticated API key by the caller, never from request input.
     *                               A quote is stored with it and can only ever be read back in that environment.
     */
    public function create(DeveloperApplication $app, string $offerId, string $recipientPhone, string $environment = 'production'): ApiQuote
    {
        $environment = self::assertEnvironment($environment);

        $phone = GhanaPhoneNumber::toLocal($recipientPhone);
        if (! GhanaPhoneNumber::isValidLocal($phone)) {
            throw ApiException::unprocessable(ApiError::VALIDATION_FAILED, 'The recipient phone number is not a valid Ghana mobile number.', [
                'fields' => ['recipient_phone' => ['Invalid Ghana mobile number.']],
            ]);
        }

        $resolved = $this->catalog->resolveForPurchase($app, $offerId);
        if (! $resolved) {
            throw new ApiException(ApiError::OFFER_UNAVAILABLE, 'This offer does not exist or is not currently available.', 404);
        }

        $pricing = $resolved['reseller']
            ? OrderPricingSnapshot::forResellerListing($resolved['reseller'])
            : OrderPricingSnapshot::forOwnedProduct($resolved['product']);

        if ((float) $pricing['expected_amount'] <= 0) {
            throw new ApiException(ApiError::OFFER_UNAVAILABLE, 'This offer does not exist or is not currently available.', 404);
        }

        return ApiQuote::create([
            'reference' => ($environment === 'sandbox' ? 'sbx_qt_' : 'qt_').Str::lower((string) Str::ulid()),
            'application_id' => $app->id,
            'environment' => $environment,
            'offer_id' => $offerId,
            'product_id' => $resolved['product']->id,
            'reseller_product_id' => $resolved['reseller']?->id,
            'vendor_id' => $resolved['vendor_id'],
            'recipient_phone' => $phone,
            'amount' => $pricing['expected_amount'],
            'currency' => $pricing['currency'],
            // Public shape only: base/markup split is the seller's business, never exposed.
            'snapshot' => ['offer' => $resolved['offer']],
            'expires_at' => now()->addMinutes((int) config('developer_api.quote.ttl_minutes', 10)),
        ]);
    }

    /** A quote is only ever visible to the application that created it, in the environment it was created in. */
    public function findForApplication(DeveloperApplication $app, string $reference, string $environment = 'production'): ?ApiQuote
    {
        return ApiQuote::query()
            ->where('reference', $reference)
            ->where('application_id', $app->id)
            ->where('environment', self::assertEnvironment($environment))
            ->first();
    }

    private static function assertEnvironment(string $environment): string
    {
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new ApiException(ApiError::ENVIRONMENT_MISMATCH, 'The API environment could not be determined for this request.', 403);
        }

        return $environment;
    }
}
