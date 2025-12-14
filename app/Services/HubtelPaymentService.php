<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubtelPaymentService
{
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $baseUrl;

    public function __construct(array $config = [])
    {
        $this->clientId = $config['client_id'] ?? env('HUBTEL_CLIENT_ID');
        $this->clientSecret = $config['client_secret'] ?? env('HUBTEL_CLIENT_SECRET');
        $this->username = $config['username'] ?? env('HUBTEL_USERNAME');
        $this->password = $config['password'] ?? env('HUBTEL_PASSWORD');
        $this->baseUrl = $config['base_url'] ?? env('HUBTEL_BASE_URL', 'https://api.hubtel.com');
    }

    /**
     * Get HTTP client with proper SSL configuration
     * - Production: Full SSL verification enabled
     * - Local/Development: SSL verification disabled (Windows CA cert issues)
     */
    protected function getHttpClient()
    {
        $client = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->timeout(30)
            ->retry(2, 100);

        // Only disable SSL verification in local development
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * Process mobile money payment
     */
    public function processPayment(array $data)
    {
        try {
            $response = $this->getHttpClient()
                ->post("{$this->baseUrl}/v1/merchantaccount/merchants/{$this->username}/receive/mobilemoney", [
                    'CustomerName' => $data['customer_name'],
                    'CustomerMsisdn' => $data['customer_phone'],
                    'CustomerEmail' => $data['customer_email'] ?? '',
                    'Channel' => $data['channel'], // mtn-gh, airteltigo-gh, vodafone-gh
                    'Amount' => $data['amount'],
                    'PrimaryCallbackUrl' => $data['callback_url'],
                    'Description' => $data['description'],
                    'ClientReference' => $data['reference'],
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return [
                    'success' => true,
                    'transaction_id' => $responseData['TransactionId'],
                    'status' => $responseData['TransactionStatus'],
                    'message' => $responseData['ResponseText'],
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['ResponseText'] ?? 'Payment failed',
                'data' => $response->json()
            ];

        } catch (Exception $e) {
            Log::error('Hubtel payment error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send money (payout)
     */
    public function sendMoney(array $data)
    {
        try {
            $response = $this->getHttpClient()
                ->post("{$this->baseUrl}/v1/merchantaccount/merchants/{$this->username}/send/mobilemoney", [
                    'RecipientName' => $data['recipient_name'],
                    'RecipientMsisdn' => $data['recipient_phone'],
                    'Channel' => $data['channel'], // mtn-gh, airteltigo-gh, vodafone-gh
                    'Amount' => $data['amount'],
                    'PrimaryCallbackUrl' => $data['callback_url'] ?? '',
                    'Description' => $data['description'],
                    'ClientReference' => $data['reference'],
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return [
                    'success' => true,
                    'transaction_id' => $responseData['TransactionId'],
                    'status' => $responseData['TransactionStatus'],
                    'message' => $responseData['ResponseText'],
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['ResponseText'] ?? 'Payout failed',
                'data' => $response->json()
            ];

        } catch (Exception $e) {
            Log::error('Hubtel payout error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payout processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send SMS
     */
    public function sendSms(array $data)
    {
        try {
            $response = $this->getHttpClient()
                ->post("{$this->baseUrl}/v1/messages", [
                    'From' => $data['sender_id'] ?? 'XTRA4U',
                    'To' => $data['to'],
                    'Content' => $data['message'],
                    'ClientReference' => $data['reference'] ?? uniqid(),
                    'RegisteredDelivery' => true
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return [
                    'success' => true,
                    'message_id' => $responseData['MessageId'],
                    'status' => $responseData['Status'],
                    'message' => 'SMS sent successfully',
                    'data' => $responseData
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['Message'] ?? 'SMS sending failed',
                'data' => $response->json()
            ];

        } catch (Exception $e) {
            Log::error('Hubtel SMS error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'SMS sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check transaction status
     */
    public function checkTransactionStatus($transactionId)
    {
        try {
            $response = $this->getHttpClient()
                ->get("{$this->baseUrl}/v1/merchantaccount/merchants/{$this->username}/transactions/{$transactionId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to check transaction status',
                'data' => $response->json()
            ];

        } catch (Exception $e) {
            Log::error('Hubtel status check error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Status check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get supported mobile money channels
     */
    public static function getSupportedChannels()
    {
        return [
            'mtn-gh' => 'MTN Mobile Money',
            'airteltigo-gh' => 'AirtelTigo Money',
            'vodafone-gh' => 'Vodafone Cash',
        ];
    }

    /**
     * Validate configuration
     */
    public function validateConfig()
    {
        $required = ['client_id', 'client_secret', 'username', 'password'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($this->$field)) {
                $missing[] = $field;
            }
        }

        return [
            'valid' => empty($missing),
            'missing_fields' => $missing
        ];
    }
}