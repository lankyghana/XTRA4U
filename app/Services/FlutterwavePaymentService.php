<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwavePaymentService
{
    protected string $publicKey;
    protected string $secretKey;
    protected string $encryptionKey;
    protected string $paymentUrl;
    protected ?PaymentGatewayConfig $config;

    public function __construct(?PaymentGatewayConfig $config = null)
    {
        $this->config = $config ?? PaymentGatewayConfig::where('gateway_name', PaymentGatewayConfig::GATEWAY_FLUTTERWAVE)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
            ->where('is_active', true)
            ->first();
        
        if ($this->config) {
            $this->publicKey = $this->config->getConfig('public_key', '');
            $this->secretKey = $this->config->getConfig('secret_key', '');
            $this->encryptionKey = $this->config->getConfig('encryption_key', '');
            $this->paymentUrl = $this->config->getConfig('payment_url', 'https://api.flutterwave.com/v3');
        } else {
            // Fallback to .env if available
            $this->publicKey = config('services.flutterwave.public_key', '');
            $this->secretKey = config('services.flutterwave.secret_key', '');
            $this->encryptionKey = config('services.flutterwave.encryption_key', '');
            $this->paymentUrl = config('services.flutterwave.payment_url', 'https://api.flutterwave.com/v3');
        }
    }

    /**
     * Get HTTP client with proper SSL configuration
     * - Production: Full SSL verification enabled
     * - Local/Development: SSL verification disabled (Windows CA cert issues)
     */
    protected function getHttpClient()
    {
        $client = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->retry(2, 100);

        // Only disable SSL verification in local development
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * Initiate payment (customer pays)
     */
    public function requestPayment(Order $order, string $email, float $amount): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Flutterwave payment gateway not configured.',
                'reference' => null,
            ];
        }

        $reference = 'XTRA4U-FLW-' . uniqid() . '-' . $order->id;
        $payload = [
            'tx_ref' => $reference,
            'amount' => $amount,
            'currency' => 'GHS',
            'redirect_url' => route('payment.callback'),
            'customer' => [
                'email' => $email,
                'phonenumber' => $order->recipient_phone_number,
                'name' => 'XTRA4U Customer',
            ],
            'customizations' => [
                'title' => 'XTRA4U Payment',
                'description' => 'Payment for ' . $order->service_purchased,
                'logo' => config('app.url') . '/logo.png',
            ],
        ];

        try {
            $response = $this->getHttpClient()
                ->post($this->paymentUrl . '/payments', $payload);

            $data = $response->json();

            if ($response->successful() && ($data['status'] === 'success')) {
                // Persist reference for later verification
                $order->update([
                    'payment_reference' => $reference,
                    'payment_status' => 'pending',
                    'payment_gateway' => 'flutterwave',
                ]);

                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Payment initialized.',
                    'reference' => $reference,
                    'authorization_url' => $data['data']['link'] ?? null,
                ];
            }

            Log::warning('Flutterwave init failed', [
                'order_id' => $order->id,
                'payload' => $payload,
                'response' => $data,
                'http_status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment.',
                'reference' => $reference,
                'http_status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave Payment Exception', [
                'order_id' => $order->id,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Error initializing payment.',
                'reference' => $reference,
            ];
        }
    }

    /**
     * Verify payment
     */
    public function verifyPayment(string $reference): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Flutterwave payment gateway not configured.',
            ];
        }

        try {
            $response = $this->getHttpClient()
                ->get($this->paymentUrl . '/transactions/verify_by_reference?tx_ref=' . $reference);

            $data = $response->json();

            if ($response->successful() && ($data['status'] === 'success')) {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Payment verified.',
                    'data' => $data['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to verify payment.',
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave Verify Exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Error verifying payment.',
            ];
        }
    }

    /**
     * Check if Flutterwave is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->publicKey) && !empty($this->secretKey) && !empty($this->paymentUrl);
    }

    /**
     * Get the current environment (sandbox/live)
     */
    public function getEnvironment(): string
    {
        return $this->config?->environment ?? 'sandbox';
    }
}