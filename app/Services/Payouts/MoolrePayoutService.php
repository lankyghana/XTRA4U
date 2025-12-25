<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use App\Services\Payouts\Concerns\FormatsGhanaPhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoolrePayoutService implements HandlesPayouts
{
    use FormatsGhanaPhoneNumber;

    protected PaymentGatewayConfig $config;

    protected array $networkCodes = [
        VendorWithdrawal::NETWORK_MTN => '1',
        VendorWithdrawal::NETWORK_TELECEL => '6',
        VendorWithdrawal::NETWORK_AIRTELTIGO => '7',
    ];

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config->getConfig('base_url', 'https://api.moolre.com'), '/');
    }

    protected function apiUser(): string
    {
        return trim((string) $this->config->getConfig('api_user', ''));
    }

    protected function apiKey(): string
    {
        return trim((string) $this->config->getConfig('api_key', ''));
    }

    protected function accountNumber(): string
    {
        return trim((string) $this->config->getConfig('account_number', ''));
    }

    protected function currency(): string
    {
        return (string) $this->config->getConfig('currency', 'GHS');
    }

    protected function http(array $headers): PendingRequest
    {
        $client = Http::withHeaders($headers)
            ->timeout(30)
            ->retry(2, 100);

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

        $externalRef = $withdrawal->payout_reference ?: $withdrawal->reference;

        // If we already have an external reference, check status first (idempotent / safe retries).
        if (!empty($withdrawal->payout_reference)) {
            return $this->checkTransferStatus($externalRef);
        }

        $channel = $this->networkCodes[$withdrawal->momo_network] ?? '1';
        $receiver = $this->formatPhoneNumber($withdrawal->momo_number);

        try {
            // Step 1: Validate name
            $validatePayload = [
                'type' => 1,
                'receiver' => $receiver,
                'channel' => $channel,
                'sublistid' => '',
                'currency' => $this->currency(),
                'accountnumber' => $this->accountNumber(),
            ];

            $validateResponse = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/validate', $validatePayload);

            $validateData = $validateResponse->json();

            if (!$validateResponse->successful() || (int) ($validateData['status'] ?? 0) !== 1) {
                $apiKey = $this->apiKey();
                Log::warning('Moolre validate-name failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'payload' => $validatePayload,
                    'base_url' => $this->baseUrl(),
                    'api_user' => $this->apiUser(),
                    'api_key_len' => strlen($apiKey),
                    'api_key_last4' => $apiKey !== '' ? substr($apiKey, -4) : null,
                    'http_status' => $validateResponse->status(),
                    'response' => $validateData,
                ]);

                return [
                    'success' => false,
                    'message' => $validateData['message'] ?? 'Failed to validate recipient details.',
                    'reference' => null,
                ];
            }

            // Step 2: Initiate transfer
            $transferPayload = [
                'type' => 1,
                'channel' => $channel,
                'currency' => $this->currency(),
                'amount' => (string) ((float) $withdrawal->amount),
                'receiver' => $receiver,
                'sublistid' => '',
                'externalref' => $externalRef,
                'reference' => 'XTRA4U Vendor Payout - ' . $externalRef,
                'accountnumber' => $this->accountNumber(),
            ];

            $transferResponse = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/transfer', $transferPayload);

            $transferData = $transferResponse->json();

            if (!$transferResponse->successful()) {
                Log::error('Moolre initiate-transfer failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'payload' => $transferPayload,
                    'http_status' => $transferResponse->status(),
                    'response' => $transferData,
                ]);

                return [
                    'success' => false,
                    'message' => $transferData['message'][0] ?? $transferData['message'] ?? 'Transfer failed.',
                    'reference' => null,
                ];
            }

            $txStatus = (int) data_get($transferData, 'data.txstatus', 3);
            $transactionId = data_get($transferData, 'data.transactionid');

            if ($txStatus === 1) {
                return [
                    'success' => true,
                    'message' => 'Payout completed successfully via Moolre',
                    'reference' => $externalRef,
                    'transaction_id' => $transactionId,
                    'status' => 'completed',
                ];
            }

            if ($txStatus === 0 || $txStatus === 3) {
                // Pending/Unknown: do not assume failure. Per docs, confirm using transfer status.
                $statusResult = $this->checkTransferStatus($externalRef);
                if (!empty($transactionId) && empty($statusResult['transaction_id'] ?? null)) {
                    $statusResult['transaction_id'] = $transactionId;
                }
                // Ensure we persist/use the same external reference.
                $statusResult['reference'] = $externalRef;
                return $statusResult;
            }

            return [
                'success' => false,
                'message' => 'Payout failed via Moolre',
                'reference' => $externalRef,
                'transaction_id' => $transactionId,
                'status' => 'failed',
            ];
        } catch (\Throwable $e) {
            Log::error('Moolre payout exception', [
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

    protected function checkTransferStatus(string $externalRef): array
    {
        $payload = [
            'type' => 1,
            'idtype' => 1,
            'id' => $externalRef,
            'accountnumber' => $this->accountNumber(),
        ];

        try {
            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/status', $payload);

            $data = $response->json();

            if (!$response->successful() || (int) ($data['status'] ?? 0) !== 1) {
                $apiKey = $this->apiKey();
                Log::warning('Moolre transfer status check failed', [
                    'reference' => $externalRef,
                    'payload' => $payload,
                    'base_url' => $this->baseUrl(),
                    'api_user' => $this->apiUser(),
                    'api_key_len' => strlen($apiKey),
                    'api_key_last4' => $apiKey !== '' ? substr($apiKey, -4) : null,
                    'http_status' => $response->status(),
                    'response' => $data,
                ]);

                return [
                    'success' => null,
                    'pending' => true,
                    'message' => $data['message'] ?? 'Unable to confirm payout status yet.',
                    'reference' => $externalRef,
                    'status' => 'pending',
                ];
            }

            $txStatus = (int) data_get($data, 'data.txstatus', 3);

            if ($txStatus === 1) {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Payout completed successfully via Moolre',
                    'reference' => $externalRef,
                    'transaction_id' => data_get($data, 'data.transactionid'),
                    'status' => 'completed',
                ];
            }

            if ($txStatus === 2) {
                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Payout failed via Moolre',
                    'reference' => $externalRef,
                    'transaction_id' => data_get($data, 'data.transactionid'),
                    'status' => 'failed',
                ];
            }

            return [
                'success' => null,
                'pending' => true,
                'message' => $data['message'] ?? 'Payout is still pending via Moolre',
                'reference' => $externalRef,
                'transaction_id' => data_get($data, 'data.transactionid'),
                'status' => 'pending',
            ];
        } catch (\Throwable $e) {
            Log::warning('Moolre transfer status check exception', [
                'reference' => $externalRef,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => null,
                'pending' => true,
                'message' => 'Unable to confirm payout status yet.',
                'reference' => $externalRef,
                'status' => 'pending',
            ];
        }
    }
}
