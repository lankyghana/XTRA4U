<?php
namespace App\Services;

use App\Contracts\Gateways\CollectsPayments;
use App\Contracts\Gateways\HandlesGenericPayments;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackPaymentService implements CollectsPayments, HandlesGenericPayments
{
    protected string $publicKey;
    protected string $secretKey;
    protected string $paymentUrl;
    protected ?PaymentGatewayConfig $config;

    public function __construct(?PaymentGatewayConfig $config = null)
    {
        $this->config = $config ?? PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);
        
        if ($this->config && $this->config->gateway_name === PaymentGatewayConfig::GATEWAY_PAYSTACK) {
            $this->publicKey = $this->config->getConfig('public_key', '');
            $this->secretKey = $this->config->getConfig('secret_key', '');
            $this->paymentUrl = $this->config->getConfig('payment_url', 'https://api.paystack.co');
        } else {
            // Fallback to .env if no database config
            $this->publicKey = config('services.paystack.public_key', '');
            $this->secretKey = config('services.paystack.secret_key', '');
            $this->paymentUrl = config('services.paystack.payment_url', 'https://api.paystack.co');
        }
    }

    /**
     * Get HTTP client with proper SSL configuration
     * - Production: Full SSL verification enabled
     * - Local/Development: SSL verification disabled (Windows CA cert issues)
     */
    protected function getHttpClient()
    {
        $client = Http::withToken($this->secretKey)
            ->timeout(30)
            ->retry(2, 100);

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
        $reference = 'XTRA4U-' . uniqid() . '-' . $order->id;
        $payload = [
            'email' => $email,
            'amount' => intval($amount * 100), // Paystack expects amount in kobo/pesewas
            'reference' => $reference,
            'callback_url' => route('payment.callback'),
        ];
        try {
            $response = $this->getHttpClient()
                ->post($this->paymentUrl . '/transaction/initialize', $payload);
            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? false)) {
                // Persist reference for later verification
                $order->update([
                    'payment_reference' => $reference,
                    'payment_status' => 'pending',
                    'payment_gateway' => 'paystack',
                ]);

                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Payment initialized.',
                    'reference' => $reference,
                    'authorization_url' => $data['data']['authorization_url'] ?? null,
                ];
            }

            Log::warning('Paystack init failed', [
                'order_id' => $order->id,
                'payload' => $payload,
                'body' => $response->body(),
                'json' => $data,
                'http_status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment.',
                'reference' => $reference,
                'http_status' => $response->status(),
                'errors' => $data['data'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack Payment Exception', [
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
        try {
            $response = $this->getHttpClient()
                ->get($this->paymentUrl . '/transaction/verify/' . $reference);
            $data = $response->json();
            if ($response->successful() && ($data['status'] ?? false)) {
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
            Log::error('Paystack Verify Exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Error verifying payment.',
            ];
        }
    }

    /**
     * Initiate generic payment (for AFA, etc.)
     */
    public function initiatePayment(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $metadata = []): array
    {
        $reference = $reference ?? 'XTRA4U-' . strtoupper(uniqid()) . '-' . time();
        
        $payload = [
            'email' => $email,
            'amount' => intval($amount * 100), // Paystack expects amount in kobo/pesewas
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];
        
        try {
            $response = $this->getHttpClient()
                ->post($this->paymentUrl . '/transaction/initialize', $payload);
            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? false)) {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Payment initialized.',
                    'reference' => $reference,
                    'authorization_url' => $data['data']['authorization_url'] ?? null,
                ];
            }

            Log::warning('Paystack generic payment init failed', [
                'payload' => $payload,
                'body' => $response->body(),
                'json' => $data,
                'http_status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment.',
                'reference' => $reference,
                'http_status' => $response->status(),
                'errors' => $data['data'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack Generic Payment Exception', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Error initializing payment: ' . $e->getMessage(),
                'reference' => $reference,
            ];
        }
    }

    /**
     * Check if Paystack is properly configured
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
