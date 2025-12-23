<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubtelPayoutService implements HandlesPayouts
{
    use FormatsGhanaPhoneNumber;

    protected PaymentGatewayConfig $config;

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function http(string $clientId, string $clientSecret)
    {
        $client = Http::withBasicAuth($clientId, $clientSecret)->timeout(30)->retry(2, 100);
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

        $clientId = (string) $this->config->getConfig('client_id');
        $clientSecret = (string) $this->config->getConfig('client_secret');
        $baseUrl = (string) $this->config->getConfig('base_url', 'https://api.hubtel.com');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);

            $channel = match ($withdrawal->momo_network) {
                VendorWithdrawal::NETWORK_MTN => 'mtn-gh',
                VendorWithdrawal::NETWORK_TELECEL => 'vodafone-gh',
                VendorWithdrawal::NETWORK_AIRTELTIGO => 'tigo-gh',
                default => 'mtn-gh',
            };

            $response = $this->http($clientId, $clientSecret)->post($baseUrl . '/v1/merchantaccount/merchants/transfer/send', [
                'RecipientMsisdn' => $formattedPhone,
                'Amount' => (float) $withdrawal->amount,
                'PrimaryCallbackUrl' => route('payment.callback'),
                'ClientReference' => $withdrawal->reference,
                'Description' => 'XTRA4U Vendor Payout',
                'Channel' => $channel,
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['Data']['TransactionId'])) {
                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully via Hubtel',
                    'reference' => $responseData['Data']['TransactionId'],
                    'status' => $responseData['Data']['Status'] ?? 'pending',
                ];
            }

            Log::error('Hubtel payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $responseData['Message'] ?? 'Payout failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Hubtel payout exception', [
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
