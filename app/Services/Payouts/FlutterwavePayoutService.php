<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwavePayoutService implements HandlesPayouts
{
    use FormatsGhanaPhoneNumber;

    protected PaymentGatewayConfig $config;

    protected array $networkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'MTN',
        VendorWithdrawal::NETWORK_TELECEL => 'VODAFONE',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'TIGO',
    ];

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function http(string $secretKey)
    {
        $client = Http::withHeaders([
            'Authorization' => 'Bearer ' . $secretKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->retry(2, 100);

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

        $secretKey = (string) $this->config->getConfig('secret_key');
        $baseUrl = (string) $this->config->getConfig('payment_url', 'https://api.flutterwave.com/v3');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->networkCodes[$withdrawal->momo_network] ?? 'MTN';

            $response = $this->http($secretKey)->post($baseUrl . '/transfers', [
                'account_bank' => $networkCode,
                'account_number' => $formattedPhone,
                'amount' => (float) $withdrawal->amount,
                'currency' => 'GHS',
                'narration' => 'XTRA4U Vendor Payout',
                'reference' => $withdrawal->reference,
                'beneficiary_name' => $withdrawal->vendor->name ?? 'Vendor',
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['data']['id'])) {
                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully via Flutterwave',
                    'reference' => $responseData['data']['reference'] ?? $withdrawal->reference,
                    'transaction_id' => $responseData['data']['id'],
                    'status' => $responseData['data']['status'] ?? 'pending',
                ];
            }

            Log::error('Flutterwave transfer failed', [
                'withdrawal_id' => $withdrawal->id,
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Transfer failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave Payout exception', [
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
