<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackPayoutService implements HandlesPayouts
{
    use FormatsGhanaPhoneNumber;

    protected PaymentGatewayConfig $config;

    protected array $networkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'mtn',
        VendorWithdrawal::NETWORK_TELECEL => 'vod',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'tgo',
    ];

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function http(string $secretKey)
    {
        $client = Http::withToken($secretKey)->timeout(30)->retry(2, 100);
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
        $baseUrl = (string) $this->config->getConfig('payment_url', 'https://api.paystack.co');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->networkCodes[$withdrawal->momo_network] ?? 'mtn';

            // Step 1: Create transfer recipient
            $recipientResponse = $this->http($secretKey)->post($baseUrl . '/transferrecipient', [
                'type' => 'mobile_money',
                'name' => $withdrawal->vendor->name ?? 'Vendor',
                'account_number' => $formattedPhone,
                'bank_code' => $networkCode,
                'currency' => 'GHS',
            ]);

            $recipientData = $recipientResponse->json();

            if (!$recipientResponse->successful() || !isset($recipientData['data']['recipient_code'])) {
                Log::error('Paystack create recipient failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'response' => $recipientData,
                ]);

                return [
                    'success' => false,
                    'message' => $recipientData['message'] ?? 'Failed to create transfer recipient',
                    'reference' => null,
                ];
            }

            $recipientCode = $recipientData['data']['recipient_code'];

            // Step 2: Initiate transfer
            $transferResponse = $this->http($secretKey)->post($baseUrl . '/transfer', [
                'source' => 'balance',
                'amount' => (int) ($withdrawal->amount * 100),
                'recipient' => $recipientCode,
                'reason' => 'XTRA4U Vendor Payout - ' . $withdrawal->reference,
                'reference' => $withdrawal->reference,
                'currency' => 'GHS',
            ]);

            $transferData = $transferResponse->json();

            if ($transferResponse->successful() && isset($transferData['data']['transfer_code'])) {
                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully via Paystack',
                    'reference' => $transferData['data']['transfer_code'],
                    'transaction_id' => $transferData['data']['id'] ?? null,
                    'status' => $transferData['data']['status'] ?? 'pending',
                ];
            }

            Log::error('Paystack transfer failed', [
                'withdrawal_id' => $withdrawal->id,
                'response' => $transferData,
            ]);

            return [
                'success' => false,
                'message' => $transferData['message'] ?? 'Transfer failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack Payout exception', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while processing the payout: ' . $e->getMessage(),
                'reference' => null,
            ];
        }
    }
}
