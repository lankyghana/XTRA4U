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

    protected function responseMessage(array $payload, string $fallback): string
    {
        $message = $payload['message'] ?? null;

        if (is_array($message)) {
            return (string) ($message[0] ?? $fallback);
        }

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        return $fallback;
    }

    protected function mapGatewayStatus(int $status): string
    {
        return match ($status) {
            1 => 'completed',
            2 => 'pending',
            0 => 'failed',
            default => 'unknown',
        };
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
                'amount' => (string) ((float) $withdrawal->amount),
                'currency' => $this->currency(),
                'channel' => $channel,
                'receiver' => $receiver,
                'externalref' => $externalRef,
                'narration' => 'Vendor withdrawal',
                'accountnumber' => $this->accountNumber(),
            ];

            Log::info('Moolre transfer initiation request', [
                'withdrawal_id' => $withdrawal->id,
                'reference' => $externalRef,
                'amount' => (string) ((float) $withdrawal->amount),
                'currency' => $this->currency(),
                'channel' => $channel,
                'receiver' => $receiver,
            ]);

            $transferResponse = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/transfer', $transferPayload);

            $transferData = $transferResponse->json();
            $message = $this->responseMessage($transferData, 'Transfer failed.');
            $transactionId = data_get($transferData, 'data.transactionid');

            if (!$transferResponse->successful()) {
                Log::error('Moolre initiate-transfer failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'payload' => $transferPayload,
                    'http_status' => $transferResponse->status(),
                    'response' => $transferData,
                ]);

                // Network/timeouts can be ambiguous; query status endpoint before deciding final state.
                $statusResult = $this->checkTransferStatus($externalRef);
                if (!empty($transactionId) && empty($statusResult['transaction_id'] ?? null)) {
                    $statusResult['transaction_id'] = $transactionId;
                }
                $statusResult['reference'] = $externalRef;
                if (!empty($message) && empty($statusResult['message'] ?? null)) {
                    $statusResult['message'] = $message;
                }

                return $statusResult;
            }

            $transferStatus = (int) ($transferData['status'] ?? -1);
            $mappedStatus = $this->mapGatewayStatus($transferStatus);

            if ($mappedStatus === 'completed') {
                return [
                    'success' => true,
                    'message' => $message !== '' ? $message : 'Payout completed successfully via Moolre',
                    'reference' => $externalRef,
                    'transaction_id' => $transactionId,
                    'status' => 'completed',
                ];
            }

            if ($mappedStatus === 'pending') {
                $statusResult = $this->checkTransferStatus($externalRef);
                if (!empty($transactionId) && empty($statusResult['transaction_id'] ?? null)) {
                    $statusResult['transaction_id'] = $transactionId;
                }
                $statusResult['reference'] = $externalRef;
                return $statusResult;
            }

            if ($mappedStatus === 'failed') {
                return [
                    'success' => false,
                    'message' => $message !== '' ? $message : 'Payout failed via Moolre',
                    'reference' => $externalRef,
                    'transaction_id' => $transactionId,
                    'status' => 'failed',
                ];
            }

            // Unknown response shape: confirm via status endpoint.
            $statusResult = $this->checkTransferStatus($externalRef);
            if (!empty($transactionId) && empty($statusResult['transaction_id'] ?? null)) {
                $statusResult['transaction_id'] = $transactionId;
            }
            $statusResult['reference'] = $externalRef;
            return $statusResult;
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
            'type' => 2,
            'idtype' => 1,
            'id' => $externalRef,
            'accountnumber' => $this->accountNumber(),
        ];

        try {
            Log::info('Moolre transfer status request', [
                'reference' => $externalRef,
                'type' => 2,
                'idtype' => 1,
            ]);

            $response = $this->http([
                'X-API-USER' => $this->apiUser(),
                'X-API-KEY' => $this->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl() . '/open/transact/status', $payload);

            $data = $response->json();
            $message = $this->responseMessage($data, 'Unable to confirm payout status.');

            if (!$response->successful()) {
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
                    'success' => false,
                    'pending' => false,
                    'message' => $message,
                    'reference' => $externalRef,
                    'status' => 'failed',
                ];
            }

            $apiStatus = (int) ($data['status'] ?? -1);
            $mappedStatus = $this->mapGatewayStatus($apiStatus);
            $transactionId = data_get($data, 'data.transactionid');

            if ($mappedStatus === 'completed') {
                return [
                    'success' => true,
                    'message' => $message,
                    'reference' => $externalRef,
                    'transaction_id' => $transactionId,
                    'status' => 'completed',
                ];
            }

            if ($mappedStatus === 'failed') {
                return [
                    'success' => false,
                    'pending' => false,
                    'message' => $message,
                    'reference' => $externalRef,
                    'transaction_id' => $transactionId,
                    'status' => 'failed',
                ];
            }

            if ($mappedStatus === 'pending') {
                return [
                    'success' => null,
                    'pending' => true,
                    'message' => $message,
                    'reference' => $externalRef,
                    'transaction_id' => $transactionId,
                    'status' => 'pending',
                ];
            }

            return [
                'success' => false,
                'pending' => false,
                'message' => $message,
                'reference' => $externalRef,
                'transaction_id' => $transactionId,
                'status' => 'failed',
            ];
        } catch (\Throwable $e) {
            Log::warning('Moolre transfer status check exception', [
                'reference' => $externalRef,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'pending' => false,
                'message' => 'Unable to confirm payout status.',
                'reference' => $externalRef,
                'status' => 'failed',
            ];
        }
    }
}
