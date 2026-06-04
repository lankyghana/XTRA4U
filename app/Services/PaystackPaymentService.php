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
     * Normalize an amount returned by Paystack (in kobo/pesewas) to GHS.
     * Paystack always returns amounts in the smallest currency unit (×100).
     * Call this wherever you read `data.amount` from a Paystack API response.
     *
     * Usage:
     *   $order->amount_paid = PaystackPaymentService::normalizeAmount($rawAmount);
     */
    public static function normalizeAmount(float $koboAmount): float
    {
        return $koboAmount / 100;
    }

    /**
     * Generate a unique, consistently-formatted Paystack transaction reference.
     *
     * Format: XTRA4U-{UPPERCASE_HEX_UNIQID}-{suffix}
     *   - With an Order:   suffix = order ID   (e.g. XTRA4U-5F3E2A1B4C-42)
     *   - Without an Order: suffix = unix timestamp (e.g. XTRA4U-5F3E2A1B4C-1717459260)
     *
     * Using strtoupper(uniqid()) everywhere means references look identical in
     * Paystack dashboard and Laravel logs regardless of which flow created them.
     */
    private function generateReference(?int $orderId = null): string
    {
        $suffix = $orderId !== null ? (string) $orderId : (string) time();
        return 'XTRA4U-' . strtoupper(uniqid()) . '-' . $suffix;
    }

    /**
     * Shared internal helper: POST to /transaction/initialize and return a
     * normalised result array. Handles the HTTP call, JSON decoding, warning/
     * error logging, and exception catching in one place.
     *
     * Both requestPayment() (Order-bound) and initiatePayment() (generic) call
     * this method; each handles its own pre/post logic around the shared call.
     *
     * @param  array  $payload  Already-built Paystack payload (email, amount, reference, …)
     * @return array  ['success' => bool, 'message' => string, 'reference' => string,
     *                 'authorization_url' => string|null, …]
     */
    private function callPaystackInitialize(array $payload): array
    {
        try {
            $response = $this->getHttpClient()
                ->post($this->paymentUrl . '/transaction/initialize', $payload);
            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? false)) {
                return [
                    'success'           => true,
                    'message'           => $data['message'] ?? 'Payment initialized.',
                    'reference'         => $payload['reference'],
                    'authorization_url' => $data['data']['authorization_url'] ?? null,
                ];
            }

            Log::warning('Paystack transaction/initialize failed', [
                'payload'     => $payload,
                'body'        => $response->body(),
                'json'        => $data,
                'http_status' => $response->status(),
            ]);

            return [
                'success'     => false,
                'message'     => $data['message'] ?? 'Failed to initialize payment.',
                'reference'   => $payload['reference'],
                'http_status' => $response->status(),
                'errors'      => $data['data'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack transaction/initialize exception', [
                'payload' => $payload,
                'error'   => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'message'   => 'Error initializing payment: ' . $e->getMessage(),
                'reference' => $payload['reference'],
            ];
        }
    }

    /**
     * Initiate payment for an Order (customer checkout).
     * Persists the reference + payment_status on the Order before returning.
     */
    public function requestPayment(Order $order, string $email, float $amount): array
    {
        $reference = $this->generateReference($order->id);

        $payload = [
            'email'        => $email,
            'amount'       => intval($amount * 100), // Paystack expects amount in kobo/pesewas
            'reference'    => $reference,
            'callback_url' => route('payment.callback'),
        ];

        $result = $this->callPaystackInitialize($payload);

        if ($result['success']) {
            // Persist reference for later verification
            $order->update([
                'payment_reference' => $reference,
                'payment_status'    => 'pending',
                'payment_gateway'   => 'paystack',
            ]);
        } else {
            // Log order context on failure (callPaystackInitialize logs payload-level detail)
            Log::warning('Paystack requestPayment failed', ['order_id' => $order->id]);
        }

        return $result;
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
     * Initiate a generic payment (for AFA registrations, wallet top-ups, etc.)
     * No Order model is involved; caller supplies the callback URL and optional metadata.
     */
    public function initiatePayment(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $metadata = []): array
    {
        $reference = $reference ?? $this->generateReference();

        $payload = [
            'email'        => $email,
            'amount'       => intval($amount * 100), // Paystack expects amount in kobo/pesewas
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'metadata'     => $metadata,
        ];

        return $this->callPaystackInitialize($payload);
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
