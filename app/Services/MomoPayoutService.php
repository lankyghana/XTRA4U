<?php

namespace App\Services;

use App\Models\VendorWithdrawal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MomoPayoutService
{
    protected ?string $apiKey;
    protected string $baseUrl;

    /**
     * Network code mappings for BulkClix API
     */
    protected array $networkCodes = [
        VendorWithdrawal::NETWORK_MTN => 'MTN',
        VendorWithdrawal::NETWORK_TELECEL => 'VOD', // Vodafone/Telecel
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'ATL', // AirtelTigo
    ];

    public function __construct()
    {
        $this->apiKey = config('services.bulkclix.api_key');
        $this->baseUrl = config('services.bulkclix.base_url') ?? 'https://api.bulkclix.com/api/v1';
    }

    /**
     * Process a mobile money payout for a vendor withdrawal
     */
    public function processPayout(VendorWithdrawal $withdrawal): array
    {
        if (empty($this->apiKey)) {
            Log::warning('BulkClix Payout: API key not configured');
            return [
                'success' => false,
                'message' => 'Payment gateway not configured. Please contact support.',
                'reference' => null,
            ];
        }

        try {
            $formattedPhone = $this->formatPhoneNumber($withdrawal->momo_number);
            $networkCode = $this->networkCodes[$withdrawal->momo_network] ?? 'MTN';

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/payments/disburse', [
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
                    'momo_number' => $formattedPhone,
                    'network' => $withdrawal->momo_network,
                    'reference' => $responseData['data']['reference'],
                ]);

                return [
                    'success' => true,
                    'message' => 'Payout initiated successfully',
                    'reference' => $responseData['data']['reference'],
                    'status' => $responseData['data']['status'] ?? 'pending',
                ];
            }

            $errorMessage = $responseData['message'] ?? 'Payout failed. Please try again.';
            
            Log::error('BulkClix Payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $response->status(),
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $errorMessage,
                'reference' => null,
            ];
        } catch (\Exception $e) {
            Log::error('BulkClix Payout exception', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while processing the payout. Please try again.',
                'reference' => null,
            ];
        }
    }

    /**
     * Check the status of a payout
     */
    public function checkPayoutStatus(string $reference): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Payment gateway not configured.',
                'status' => null,
            ];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/payments/status/' . $reference);

            $responseData = $response->json();

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $responseData['data']['status'] ?? 'unknown',
                    'message' => $responseData['message'] ?? 'Status retrieved',
                ];
            }

            return [
                'success' => false,
                'status' => null,
                'message' => $responseData['message'] ?? 'Failed to retrieve status',
            ];
        } catch (\Exception $e) {
            Log::error('BulkClix Payout status check exception', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'message' => 'Error checking payout status',
            ];
        }
    }

    /**
     * Get account balance
     */
    public function getBalance(): ?float
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/payments/balance');

            if ($response->successful()) {
                $data = $response->json();
                return (float) ($data['data']['balance'] ?? 0);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('BulkClix balance check exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
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
     * Validate if the API is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Lookup account holder name for a mobile money number
     */
    public function lookupAccountName(string $phoneNumber, string $network): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Payment gateway not configured.',
                'account_name' => null,
            ];
        }

        try {
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            $networkCode = $this->networkCodes[$network] ?? 'MTN';

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/payments/account-enquiry', [
                'account_number' => $formattedPhone,
                'account_issuer' => $networkCode,
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['data']['account_name'])) {
                return [
                    'success' => true,
                    'account_name' => $responseData['data']['account_name'],
                    'message' => 'Account found',
                ];
            }

            // Handle error responses
            $errorMessage = $responseData['message'] ?? 'Could not verify account';
            
            Log::warning('BulkClix account enquiry failed', [
                'phone' => $formattedPhone,
                'network' => $network,
                'status' => $response->status(),
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $errorMessage,
                'account_name' => null,
            ];
        } catch (\Exception $e) {
            Log::error('BulkClix account enquiry exception', [
                'phone' => $phoneNumber,
                'network' => $network,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error verifying account. Please try again.',
                'account_name' => null,
            ];
        }
    }
}
