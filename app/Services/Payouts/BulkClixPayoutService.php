<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkClixPayoutService implements HandlesPayouts
{
    use FormatsGhanaPhoneNumber;

    protected PaymentGatewayConfig $config;

    protected array $networkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'MTN',
        VendorWithdrawal::NETWORK_TELECEL => 'VOD',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'ATL',
    ];

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function http()
    {
        $client = Http::timeout(30)->retry(2, 100);
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }
        return $client;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function processPayout(VendorWithdrawal $withdrawal): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Payout gateway is not properly configured. Please check the API credentials.',
                'reference' => null,
            ];
        }

        $apiKey = (string) $this->config->getConfig('api_key');
        $baseUrl = (string) $this->config->getConfig('base_url', 'https://api.bulkclix.com/api/v1');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->networkCodes[$withdrawal->momo_network] ?? 'MTN';

            $response = $this->http()->withHeaders([
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($baseUrl . '/payments/disburse', [
                'account_number' => $formattedPhone,
                'account_issuer' => $networkCode,
                'amount' => (float) $withdrawal->amount,
                'description' => 'XTRA4U Vendor Payout - ' . $withdrawal->reference,
                'external_reference' => $withdrawal->reference,
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['data']['reference'])) {
                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully via BulkClix',
                    'reference' => $responseData['data']['reference'],
                    'status' => $responseData['data']['status'] ?? 'pending',
                ];
            }

            Log::error('BulkClix payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Payout failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('BulkClix payout exception', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while processing the payout.',
                'reference' => null,
            ];
        }
    }
}
