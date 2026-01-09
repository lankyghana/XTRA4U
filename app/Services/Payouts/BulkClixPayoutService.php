<?php

namespace App\Services\Payouts;

use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\VendorWithdrawal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkClixPayoutService implements HandlesPayouts
{
    protected PaymentGatewayConfig $config;

    /**
     * BulkClix transfer "channel" values per docs: MTN, Airtel, Vodafone.
     * Some accounts may accept alternate codes; keep mapping centralized here.
     */
    protected array $channelByNetwork = [
        VendorWithdrawal::NETWORK_MTN => 'MTN',
        VendorWithdrawal::NETWORK_TELECEL => 'VODAFONE',
        VendorWithdrawal::NETWORK_AIRTELTIGO => 'AIRTEL',
    ];

    public function __construct(PaymentGatewayConfig $config)
    {
        $this->config = $config;
    }

    protected function http()
    {
        // Do not throw after retries; we want to read the provider's error body (e.g. 401/403).
        $client = Http::timeout(30)->retry(2, 100, null, false);
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }
        return $client;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config->getConfig('base_url', 'https://api.bulkclix.com/api/v1'), '/');
    }

    /**
     * BulkClix transfer endpoints typically accept local Ghana format (e.g. 054xxxxxxx).
     */
    protected function toLocalGhanaNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($digits, '233') && strlen($digits) > 3) {
            return '0' . substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            return $digits;
        }

        if (strlen($digits) === 9) {
            return '0' . $digits;
        }

        return $digits;
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

        // If we already have a transaction id, check status first (idempotent retries).
        if (!empty($withdrawal->payout_transaction_id)) {
            return $this->checkTransferStatus($apiKey, (string) $withdrawal->payout_transaction_id, (string) ($withdrawal->payout_reference ?: $withdrawal->reference));
        }

        try {
            $localPhone = $this->toLocalGhanaNumber((string) $withdrawal->momo_number);
            if ($localPhone === '') {
                return [
                    'success' => false,
                    'message' => 'Missing MoMo number for this payout.',
                    'reference' => null,
                ];
            }

            $channel = $this->channelByNetwork[$withdrawal->momo_network] ?? 'MTN';
            $clientReference = (string) ($withdrawal->payout_reference ?: $withdrawal->reference);

            $response = $this->http()->withHeaders([
                'x-api-key' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl() . '/payment-api/send/mobilemoney', [
                'amount' => (string) ((float) $withdrawal->amount),
                'account_number' => $localPhone,
                'channel' => $channel,
                'account_name' => (string) (($withdrawal->vendor?->name) ?: 'Vendor'),
                'client_reference' => $clientReference,
            ]);

            $responseData = $response->json();

            if ($response->successful()) {
                $transactionId = (string) data_get($responseData, 'data.transaction_id', '');
                $returnedRef = (string) data_get($responseData, 'data.client_reference', $clientReference);

                if ($transactionId !== '') {
                    // Immediately check status once; if still pending, the retry job/scheduler can keep checking.
                    $statusResult = $this->checkTransferStatus($apiKey, $transactionId, $returnedRef);
                    // Ensure we return identifiers for persistence.
                    $statusResult['transaction_id'] = $transactionId;
                    $statusResult['reference'] = $returnedRef;
                    return $statusResult;
                }

                // If BulkClix didn't return a transaction id, treat as pending but persist reference.
                return [
                    'success' => null,
                    'pending' => true,
                    'message' => $responseData['message'] ?? 'Payout initiated. Awaiting confirmation.',
                    'reference' => $returnedRef,
                ];
            }

            Log::error('BulkClix payout failed', [
                'withdrawal_id' => $withdrawal->id,
                'http_status' => $response->status(),
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $responseData['message'] ?? ('Payout failed (HTTP ' . $response->status() . ').'),
                'reference' => null,
                'http_status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::error('BulkClix payout exception', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            $message = app()->environment('local', 'development', 'testing')
                ? ('BulkClix payout error: ' . $e->getMessage())
                : 'An error occurred while processing the payout.';

            return [
                'success' => false,
                'message' => $message,
                'reference' => null,
            ];
        }
    }

    protected function checkTransferStatus(string $apiKey, string $transactionId, string $reference): array
    {
        try {
            $response = $this->http()->withHeaders([
                'x-api-key' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl() . '/payment-api/checkstatus/' . urlencode($transactionId));

            $json = $response->json();
            $status = strtolower((string) data_get($json, 'data.status', ''));

            // Normalize common success/pending states.
            $successStates = ['success', 'successful', 'completed', 'paid'];
            $pendingStates = ['pending', 'processing', 'in_progress', 'in-progress', 'queued', 'initiated', 'unknown'];

            if ($response->successful() && in_array($status, $successStates, true)) {
                return [
                    'success' => true,
                    'message' => $json['message'] ?? 'Payout completed successfully via BulkClix',
                    'reference' => $reference,
                    'transaction_id' => $transactionId,
                    'status' => 'completed',
                ];
            }

            if ($response->successful() && ($status === '' || in_array($status, $pendingStates, true))) {
                return [
                    'success' => null,
                    'pending' => true,
                    'message' => $json['message'] ?? 'Payout is still processing. Please check again shortly.',
                    'reference' => $reference,
                    'transaction_id' => $transactionId,
                    'status' => $status ?: 'pending',
                ];
            }

            // Non-success terminal state.
            return [
                'success' => false,
                'message' => $json['message'] ?? ('Payout status check failed (status: ' . ($status ?: 'unknown') . ')'),
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'status' => $status ?: 'failed',
            ];
        } catch (\Throwable $e) {
            Log::warning('BulkClix payout status check exception', [
                'transaction_id' => $transactionId,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => null,
                'pending' => true,
                'message' => 'Unable to confirm payout status right now. Please check again shortly.',
                'reference' => $reference,
                'transaction_id' => $transactionId,
            ];
        }
    }
}
