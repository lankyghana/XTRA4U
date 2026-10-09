<?php

namespace App\Services\UtilityBills;

use App\Services\UtilityBills\Data\Biller;
use App\Services\UtilityBills\Data\LookupResult;
use App\Services\UtilityBills\Exceptions\ProviderNotFound;
use App\Services\UtilityBills\Exceptions\ProviderRateLimited;
use App\Services\UtilityBills\Exceptions\SaleNotAllowed;
use App\Services\UtilityBills\Exceptions\UtilityProviderException;
use App\Support\GhanaPhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory pre-payment account verification.
 *
 * A successful lookup is stored SERVER-SIDE under an unguessable token that is
 * bound to the browser session and to the storefront context it was performed
 * in. The order step reads the account, verified name and meters ONLY from that
 * stored snapshot — the browser sends the token plus a choice (which meter),
 * never the account itself — so verifying account A and then submitting B is
 * structurally impossible.
 *
 * Lookup is the provider's tightest limit (10/min per key, shared by every
 * customer), so identical lookups are served from a short cache.
 */
class UtilityBillLookupService
{
    public function __construct(
        private KingFlexyUtilityProvider $provider,
        private UtilityBillAvailability $availability,
    ) {}

    /**
     * @return array{token:string, biller:Biller, result:LookupResult, input_account:string, input_phone:?string}
     *
     * @throws ValidationException
     * @throws SaleNotAllowed
     * @throws UtilityProviderException
     */
    public function lookup(string $sessionId, ?int $vendorId, string $billerKey, ?string $account, ?string $phone): array
    {
        [$biller] = $this->availability->assertSellable($billerKey);

        [$inputAccount, $inputPhone] = $this->normaliseInput($biller, $account, $phone);

        $cacheKey = 'utility_bills.lookup.'.hash('sha256', $billerKey.'|'.$inputAccount.'|'.$inputPhone);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $result = LookupResult::fromArray($cached);
        } else {
            try {
                $result = $this->provider->lookup($billerKey, $inputAccount, $inputPhone);
            } catch (ProviderNotFound) {
                // Key the error to the field the customer actually typed into:
                // phone-lookup billers (ECG) have no account input on the form.
                throw ValidationException::withMessages($biller->lookupBy === 'phone'
                    ? ['phone' => 'We could not find any meters linked to that phone number. Please check it and try again.']
                    : ['account' => 'We could not find that '.strtolower($biller->accountLabel).'. Please check it and try again.']);
            } catch (ProviderRateLimited) {
                throw new SaleNotAllowed('Account verification is busy right now. Please try again in a minute.', 'lookup_busy');
            }

            Cache::put($cacheKey, $result->toArray(), max(1, (int) config('utility_bills.lookup_cache_ttl')));
        }

        // ECG-style billers must resolve to at least one meter to be payable.
        if ($biller->linksPhoneToAccount && ! $result->hasMeters()) {
            throw ValidationException::withMessages([
                $biller->lookupBy === 'phone' ? 'phone' : 'account' => 'No meters were found for that phone number.',
            ]);
        }

        $token = Str::random(40);
        Cache::put($this->tokenKey($token), [
            'sid' => $this->sessionHash($sessionId),
            'vendor_id' => $vendorId,
            'biller_key' => $billerKey,
            'biller' => $biller->toArray(),
            'account' => $inputAccount,
            'phone' => $inputPhone,
            'result' => $result->toArray(),
            'at' => time(),
        ], max(60, (int) config('utility_bills.lookup_token_ttl')));

        return ['token' => $token, 'biller' => $biller, 'result' => $result, 'input_account' => $inputAccount, 'input_phone' => $inputPhone];
    }

    /**
     * The verified snapshot for a token, only for the session + storefront it
     * was created in. Null for anything else (expired, forged, other session).
     *
     * @return array{biller_key:string,biller:array,account:string,phone:?string,result:array,vendor_id:?int}|null
     */
    public function resolve(string $token, string $sessionId, ?int $vendorId): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        $snapshot = Cache::get($this->tokenKey($token));

        if (! is_array($snapshot)
            || ! hash_equals((string) $snapshot['sid'], $this->sessionHash($sessionId))
            || ($snapshot['vendor_id'] ?? null) !== $vendorId) {
            return null;
        }

        return $snapshot;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->tokenKey($token));
    }

    /**
     * @return array{0:string,1:?string} [accountToQuery, phone]
     */
    private function normaliseInput(Biller $biller, ?string $account, ?string $phone): array
    {
        $errors = [];
        $phone = $phone !== null && trim($phone) !== '' ? GhanaPhoneNumber::toLocal($phone) : null;

        if ($phone !== null && ! GhanaPhoneNumber::isValidLocal($phone)) {
            $errors['phone'] = 'Enter a valid Ghana phone number.';
        }

        if ($biller->lookupBy === 'phone') {
            // ECG: the phone IS the lookup key (the provider takes it in `account` too).
            if ($phone === null) {
                $errors['phone'] = $errors['phone'] ?? 'Enter the phone number linked to the meter.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            return [$phone, $phone];
        }

        $account = trim((string) $account);
        if ($account === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9\- ]{2,29}$/', $account)) {
            $errors['account'] = 'Enter a valid '.strtolower($biller->accountLabel).'.';
        }
        if ($biller->requiresPhone && $phone === null) {
            $errors['phone'] = $errors['phone'] ?? 'Enter the phone number for this account.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [str_replace(' ', '', $account), $biller->requiresPhone ? $phone : null];
    }

    private function tokenKey(string $token): string
    {
        return 'utility_bills.lookup_token.'.$token;
    }

    private function sessionHash(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }
}
