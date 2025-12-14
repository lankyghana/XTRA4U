<?php

namespace App\Services;

use App\Models\VendorWithdrawal;
use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MomoPayoutService
{
    protected ?PaymentGatewayConfig $config;

    /**
     * Network code mappings for different gateways
     */
    protected array $paystackNetworkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'mtn',
        VendorWithdrawal::NETWORK_TELECEL => 'vod', // Vodafone/Telecel
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'tgo', // AirtelTigo
    ];

    protected array $bulkclixNetworkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'MTN',
        VendorWithdrawal::NETWORK_TELECEL => 'VOD',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'ATL',
    ];

    protected array $flutterwaveNetworkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'MTN',
        VendorWithdrawal::NETWORK_TELECEL => 'VODAFONE',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'TIGO',
    ];

    public function __construct(?PaymentGatewayConfig $config = null)
    {
        $this->config = $config ?? PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_PAYOUT);
    }

    /**
     * Get HTTP client with proper SSL configuration
     * - Production: Full SSL verification enabled
     * - Local/Development: SSL verification disabled (Windows CA cert issues)
     */
    protected function getHttpClient(string $secretKey)
    {
        $client = Http::withHeaders([
            'Authorization' => 'Bearer ' . $secretKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->retry(2, 100);

        // Only disable SSL verification in local development
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * Get base HTTP client with SSL configuration (no auth headers)
     */
    protected function getBaseHttpClient()
    {
        $client = Http::timeout(30)->retry(2, 100);

        // Only disable SSL verification in local development
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * Process a mobile money payout for a vendor withdrawal
     */
    public function processPayout(VendorWithdrawal $withdrawal): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'No payout gateway configured. Please configure one in Admin → Payment Gateways.',
                'reference' => null,
            ];
        }

        if (!$this->config->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Payout gateway is not properly configured. Please check the API credentials.',
                'reference' => null,
            ];
        }

        // Route to appropriate gateway
        return match ($this->config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_PAYSTACK => $this->processPaystackPayout($withdrawal),
            PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => $this->processFlutterwavePayout($withdrawal),
            PaymentGatewayConfig::GATEWAY_BULKCLIX => $this->processBulkclixPayout($withdrawal),
            PaymentGatewayConfig::GATEWAY_HUBTEL => $this->processHubtelPayout($withdrawal),
            default => [
                'success' => false,
                'message' => 'Unsupported payout gateway: ' . $this->config->gateway_name,
                'reference' => null,
            ],
        };
    }

    /**
     * Process payout via Paystack Transfers API
     */
    protected function processPaystackPayout(VendorWithdrawal $withdrawal): array
    {
        $secretKey = $this->config->getConfig('secret_key');
        $baseUrl = $this->config->getConfig('payment_url', 'https://api.paystack.co');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->paystackNetworkCodes[$withdrawal->momo_network] ?? 'mtn';

            // Step 1: Create a transfer recipient
            $recipientResponse = $this->getHttpClient($secretKey)
                ->post($baseUrl . '/transferrecipient', [
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

            // Step 2: Initiate the transfer
            $transferResponse = $this->getHttpClient($secretKey)
                ->post($baseUrl . '/transfer', [
                    'source' => 'balance',
                    'amount' => (int) ($withdrawal->amount * 100), // Paystack uses pesewas
                    'recipient' => $recipientCode,
                    'reason' => 'XTRA4U Vendor Payout - ' . $withdrawal->reference,
                    'reference' => $withdrawal->reference,
                    'currency' => 'GHS',
                ]);

            $transferData = $transferResponse->json();

            if ($transferResponse->successful() && isset($transferData['data']['transfer_code'])) {
                Log::info('Paystack Payout initiated', [
                    'withdrawal_id' => $withdrawal->id,
                    'vendor_id' => $withdrawal->vendor_id,
                    'amount' => $withdrawal->amount,
                    'transfer_code' => $transferData['data']['transfer_code'],
                    'status' => $transferData['data']['status'] ?? 'pending',
                ]);

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

    /**
     * Process payout via Flutterwave Transfers API
     */
    protected function processFlutterwavePayout(VendorWithdrawal $withdrawal): array
    {
        $secretKey = $this->config->getConfig('secret_key');
        $baseUrl = $this->config->getConfig('payment_url', 'https://api.flutterwave.com/v3');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->flutterwaveNetworkCodes[$withdrawal->momo_network] ?? 'MTN';

            $response = $this->getHttpClient($secretKey)
                ->post($baseUrl . '/transfers', [
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
                Log::info('Flutterwave Payout initiated', [
                    'withdrawal_id' => $withdrawal->id,
                    'vendor_id' => $withdrawal->vendor_id,
                    'amount' => $withdrawal->amount,
                    'transfer_id' => $responseData['data']['id'],
                ]);

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

    /**
     * Process payout via BulkClix API
     */
    protected function processBulkclixPayout(VendorWithdrawal $withdrawal): array
    {
        $apiKey = $this->config->getConfig('api_key');
        $baseUrl = $this->config->getConfig('base_url', 'https://api.bulkclix.com/api/v1');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->bulkclixNetworkCodes[$withdrawal->momo_network] ?? 'MTN';

            $response = $this->getBaseHttpClient()
                ->withHeaders([
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
                Log::info('BulkClix Payout successful', [
                    'withdrawal_id' => $withdrawal->id,
                    'vendor_id' => $withdrawal->vendor_id,
                    'amount' => $withdrawal->amount,
                    'reference' => $responseData['data']['reference'],
                ]);

                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully via BulkClix',
                    'reference' => $responseData['data']['reference'],
                    'status' => $responseData['data']['status'] ?? 'pending',
                ];
            }

            Log::error('BulkClix Payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Payout failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('BulkClix Payout exception', [
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

    /**
     * Process payout via Hubtel API
     */
    protected function processHubtelPayout(VendorWithdrawal $withdrawal): array
    {
        $clientId = $this->config->getConfig('client_id');
        $clientSecret = $this->config->getConfig('client_secret');
        $baseUrl = $this->config->getConfig('base_url', 'https://api.hubtel.com');

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);

            // Determine channel based on network
            $channel = match ($withdrawal->momo_network) {
                VendorWithdrawal::NETWORK_MTN => 'mtn-gh',
                VendorWithdrawal::NETWORK_TELECEL => 'vodafone-gh',
                VendorWithdrawal::NETWORK_AIRTELTIGO => 'tigo-gh',
                default => 'mtn-gh',
            };

            $response = $this->getBaseHttpClient()
                ->withBasicAuth($clientId, $clientSecret)
                ->post($baseUrl . '/v1/merchantaccount/merchants/transfer/send', [
                    'RecipientMsisdn' => $formattedPhone,
                    'Amount' => (float) $withdrawal->amount,
                    'PrimaryCallbackUrl' => route('payment.callback'),
                    'ClientReference' => $withdrawal->reference,
                    'Description' => 'XTRA4U Vendor Payout',
                ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['Data']['TransactionId'])) {
                Log::info('Hubtel Payout initiated', [
                    'withdrawal_id' => $withdrawal->id,
                    'vendor_id' => $withdrawal->vendor_id,
                    'amount' => $withdrawal->amount,
                    'transaction_id' => $responseData['Data']['TransactionId'],
                ]);

                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully via Hubtel',
                    'reference' => $responseData['Data']['TransactionId'],
                    'status' => $responseData['Data']['Status'] ?? 'pending',
                ];
            }

            Log::error('Hubtel Payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $responseData['Message'] ?? 'Payout failed. Please try again.',
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Hubtel Payout exception', [
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

    /**
     * Format phone number to standard format (233XXXXXXXXX)
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If starts with 0, replace with 233 (Ghana country code)
        if (str_starts_with($phone, '0')) {
            $phone = '233' . substr($phone, 1);
        }

        // If doesn't start with 233, add it
        if (!str_starts_with($phone, '233')) {
            $phone = '233' . $phone;
        }

        return $phone;
    }

    /**
     * Validate if the gateway is properly configured
     */
    public function isConfigured(): bool
    {
        return $this->config && $this->config->isConfigured();
    }

    /**
     * Get the current gateway name
     */
    public function getGatewayName(): ?string
    {
        return $this->config?->gateway_name;
    }

    /**
     * Get the current environment (sandbox/live)
     */
    public function getEnvironment(): string
    {
        return $this->config?->environment ?? 'sandbox';
    }
}
