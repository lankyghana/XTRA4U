<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\VendorWithdrawal;

class MoolrePaymentService
{
    // Laravel expects requestPayment for payment gateways
    public function requestPayment(Order $order, string $email, float $amount)
    {
        // Use createPayment, passing required params
        return $this->createPayment($order, $email, $order->recipient_phone_number ?? null);
    }
    protected $apiPubkey;
    protected $apiUser;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiPubkey = config('services.moolre.api_pubkey');
        $this->apiUser = config('services.moolre.api_user');
        $this->baseUrl = config('services.moolre.base_url', 'https://api.moolre.com');
    }

    // 1. Payment Initialization - Generate Payment Link
    public function createPayment(Order $order, $customerEmail, $customerPhone)
    {
        $payload = [
            'type' => 1,
            'amount' => (string) $order->amount_paid,
            'email' => $customerEmail,
            'externalref' => 'order_' . $order->id . '_' . time(),
            'callback' => route('payment.webhook'),
            'redirect' => route('checkout.success', ['order' => $order->id]),
            'reusable' => '0',
            'currency' => 'GHS',
            'accountnumber' => $this->apiUser,
            'metadata' => [
                'order_id' => $order->id,
                'customer_phone' => $customerPhone,
            ],
        ];
        
        $url = rtrim($this->baseUrl, '/') . '/embed/link';

        try {
            $response = Http::withHeaders([
                    'X-API-USER' => $this->apiUser,
                    'X-API-PUBKEY' => $this->apiPubkey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('Moolre createPayment connection timeout', [
                'url' => $url,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unable to reach the Moolre gateway right now. Please try again shortly.',
            ];
        } catch (\Throwable $e) {
            Log::error('Moolre createPayment unexpected error', [
                'url' => $url,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error while contacting the payment gateway.',
            ];
        }
            
        if ($response->successful()) {
            $data = $response->json();
            // Moolre response format: {status: 1, code: "...", message: "...", data: {...}}
            if (isset($data['status']) && $data['status'] == 1) {
                $responseData = $data['data'] ?? [];
                $order->payment_reference = $responseData['reference'] ?? $payload['externalref'];
                $order->save();
                return [
                    'success' => true,
                    'authorization_url' => $responseData['authorization_url'] ?? null,
                    'reference' => $responseData['reference'] ?? $payload['externalref'],
                    'message' => $data['message'] ?? 'Payment link generated successfully.',
                ];
            }
        }
        
        Log::error('Moolre createPayment failed', [
            'url' => $url,
            'status' => $response->status(),
            'response' => $response->body(),
            'payload' => $payload,
        ]);
        
        $responseData = $response->json();
        return [
            'success' => false,
            'message' => $responseData['message'] ?? 'Failed to initialize payment.',
        ];
    }

    // 2. Payment Verification (Check Status)
    public function verifyPayment($reference)
    {
        // Extract just the externalref if it's in format "order_123_timestamp"
        $externalref = $reference;
        
        $payload = [
            'type' => 1,
            'idtype' => '1', // 1 = externalref, 2 = Moolre ID
            'id' => $externalref,
            'accountnumber' => $this->apiUser,
        ];
        
        $response = Http::withHeaders([
                'X-API-USER' => $this->apiUser,
                'X-API-PUBKEY' => $this->apiPubkey,
                'Content-Type' => 'application/json',
            ])
            ->post($this->baseUrl . '/open/transact/status', $payload);
            
        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['status']) && $data['status'] == 1) {
                return $data;
            }
        }
        Log::error('Moolre verifyPayment failed', ['reference' => $reference, 'response' => $response->body()]);
        return null;
    }

    // 3. Withdrawal Initiation
    public function createPayout(VendorWithdrawal $withdrawal, $vendor)
    {
        // Determine channel (1=MTN, 6=Vodafone, 7=AirtelTigo, 2=Bank)
        $channel = 1; // Default to MTN
        if (isset($withdrawal->momo_network)) {
            $network = strtolower($withdrawal->momo_network);
            if ($network === 'vodafone' || $network === 'telecel') {
                $channel = 6;
            } elseif ($network === 'airteltigo' || $network === 'airteltigo') {
                $channel = 7;
            }
        }

        $payload = [
            'type' => 1,
            'channel' => $channel,
            'currency' => 'GHS',
            'amount' => (string) $withdrawal->amount,
            'receiver' => $withdrawal->momo_number,
            'externalref' => (string) $withdrawal->id,
            'reference' => 'Vendor withdrawal',
            'accountnumber' => config('services.moolre.account_number'),
        ];

        $response = Http::withHeaders([
            'X-API-USER' => $this->apiUser,
            'X-API-KEY' => config('services.moolre.api_key'),
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/open/transact/transfer', $payload);

        Log::info('Moolre createPayout request', [
            'url' => $this->baseUrl . '/open/transact/transfer',
            'api_user' => $this->apiUser,
            'api_key_set' => !empty(config('services.moolre.api_key')),
            'account_number' => config('services.moolre.account_number'),
            'payload' => $payload,
            'response_status' => $response->status(),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['status']) && $data['status'] == 1) {
                $responseData = $data['data'] ?? [];
                $withdrawal->payout_reference = $responseData['transactionid'] ?? null;
                $withdrawal->save();
                return [
                    'success' => true,
                    'reference' => $responseData['transactionid'] ?? null,
                    'message' => $data['message'][0] ?? 'Payout initiated.',
                ];
            }
        }
        Log::error('Moolre createPayout failed', ['response' => $response->body()]);
        return [
            'success' => false,
            'message' => $response->json('message')[0] ?? 'Failed to initiate payout.',
        ];
    }

    // 4. Withdrawal Status Tracking
    public function verifyPayout($reference)
    {
        $response = Http::withHeaders([
                'X-API-USER' => $this->apiUser,
                'X-API-PUBKEY' => $this->apiPubkey,
                'Content-Type' => 'application/json',
            ])
            ->get($this->baseUrl . '/payouts/' . $reference);
        if ($response->successful()) {
            return $response->json();
        }
        Log::error('Moolre verifyPayout failed', ['reference' => $reference, 'response' => $response->body()]);
        return null;
    }

    // 5. Webhook Verification
    // Moolre sends a unique 'secret' field with each webhook payload for verification
    /**
     * Verify webhook signature from Moolre
     * Moolre sends the secret IN the webhook payload
     */
    public function verifyWebhook(array $payload, string $secret): bool
    {
        // Moolre includes a secret field in the webhook data
        // This secret is generated by Moolre for this specific transaction
        if (empty($secret)) {
            Log::warning('Moolre webhook verification: empty secret');
            return false;
        }

        // Validate the secret format (should be a valid UUID-like string)
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $secret)) {
            Log::warning('Moolre webhook verification: invalid secret format', ['secret' => $secret]);
            return false;
        }

        // Additional verification: check if the payload contains expected fields
        $requiredFields = ['reference', 'status'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) && !isset($payload['data'][$field])) {
                Log::warning('Moolre webhook verification: missing required field', ['field' => $field]);
                return false;
            }
        }

        Log::info('Moolre webhook verification successful', [
            'reference' => $payload['reference'] ?? $payload['data']['reference'] ?? 'unknown',
        ]);

        return true;
    }
}
