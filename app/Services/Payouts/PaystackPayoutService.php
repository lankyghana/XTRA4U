<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackPayoutService implements HandlesPayouts
{
    use FormatsGhanaPhoneNumber;

    protected PaymentGatewayConfig $config;

    protected array $networkCodes = [
        VendorWithdrawal::NETWORK_MTN       => 'mtn',
        VendorWithdrawal::NETWORK_TELECEL   => 'vod',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'tgo',
    ];

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    /**
     * HTTP client with retry — safe for idempotent/read requests.
     * Used for POST /transferrecipient (creating a recipient is idempotent:
     * Paystack returns the existing code if the number already exists).
     */
    protected function httpWithRetry(string $secretKey)
    {
        $client = Http::withToken($secretKey)->timeout(30)->retry(2, 100);
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }
        return $client;
    }

    /**
     * HTTP client WITHOUT retry — required for POST /transfer (payout mutation).
     * Retrying a transfer after a client-side timeout risks sending a duplicate
     * payout if the first attempt actually succeeded on Paystack's side.
     * Paystack rejects duplicate references, but the safest approach is no retry.
     */
    protected function httpNoRetry(string $secretKey)
    {
        $client = Http::withToken($secretKey)->timeout(30);
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }
        return $client;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Build a deterministic cache key for a Paystack transfer recipient.
     * Scoped to phone number + network so the same recipient code is reused
     * across multiple payouts to the same destination — avoiding a redundant
     * POST /transferrecipient on every withdrawal.
     */
    private function recipientCacheKey(string $formattedPhone, string $networkCode): string
    {
        return 'paystack_recipient:' . md5($formattedPhone . ':' . $networkCode);
    }

    public function processPayout(VendorWithdrawal $withdrawal): array
    {
        if (!$this->isConfigured()) {
            return [
                'success'   => false,
                'message'   => 'Payout gateway is not properly configured. Please check the API credentials.',
                'reference' => null,
            ];
        }

        $secretKey  = (string) $this->config->getConfig('secret_key');
        $baseUrl    = (string) $this->config->getConfig('payment_url', 'https://api.paystack.co');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode    = $this->networkCodes[$withdrawal->momo_network] ?? 'mtn';

            // Step 1: Resolve transfer recipient — use cached code when available.
            // Paystack allows reusing a recipient_code for the same phone/network,
            // so we cache it for 30 days to avoid a redundant API call per payout.
            $cacheKey     = $this->recipientCacheKey($formattedPhone, $networkCode);
            $recipientCode = Cache::remember($cacheKey, now()->addDays(30), function () use (
                $secretKey, $baseUrl, $formattedPhone, $networkCode, $withdrawal
            ) {
                $recipientResponse = $this->httpWithRetry($secretKey)->post($baseUrl . '/transferrecipient', [
                    'type'           => 'mobile_money',
                    'name'           => $withdrawal->vendor->name ?? 'Vendor',
                    'account_number' => $formattedPhone,
                    'bank_code'      => $networkCode,
                    'currency'       => 'GHS',
                ]);

                $recipientData = $recipientResponse->json();

                if (!$recipientResponse->successful() || !isset($recipientData['data']['recipient_code'])) {
                    Log::error('Paystack create recipient failed', [
                        'withdrawal_id' => $withdrawal->id,
                        'response'      => $recipientData,
                    ]);
                    // Return null so Cache::remember does NOT store the failure
                    return null;
                }

                return $recipientData['data']['recipient_code'];
            });

            // If recipient creation failed, the cached value will be null.
            // Evict the null entry so the next attempt retries the API call.
            if ($recipientCode === null) {
                Cache::forget($cacheKey);

                return [
                    'success'   => false,
                    'message'   => 'Failed to create transfer recipient. Please try again.',
                    'reference' => null,
                ];
            }

            // Step 2: Initiate transfer
            $transferResponse = $this->httpNoRetry($secretKey)->post($baseUrl . '/transfer', [
                'source'    => 'balance',
                'amount'    => (int) ($withdrawal->amount * 100),
                'recipient' => $recipientCode,
                'reason'    => 'XTRA4U Vendor Payout - ' . $withdrawal->reference,
                'reference' => $withdrawal->reference,
                'currency'  => 'GHS',
            ]);

            $transferData = $transferResponse->json();

            if ($transferResponse->successful() && isset($transferData['data']['transfer_code'])) {
                return [
                    'success'        => true,
                    'message'        => 'Payout initiated successfully via Paystack',
                    'reference'      => $transferData['data']['transfer_code'],
                    'transaction_id' => $transferData['data']['id'] ?? null,
                    'status'         => $transferData['data']['status'] ?? 'pending',
                ];
            }

            Log::error('Paystack transfer failed', [
                'withdrawal_id' => $withdrawal->id,
                'response'      => $transferData,
            ]);

            return [
                'success'   => false,
                'message'   => $transferData['message'] ?? 'Transfer failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack Payout exception', [
                'withdrawal_id' => $withdrawal->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'message'   => 'An error occurred while processing the payout: ' . $e->getMessage(),
                'reference' => null,
            ];
        }
    }
}

