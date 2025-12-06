<?php
namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackPaymentService
{
    protected string $publicKey;
    protected string $secretKey;
    protected string $paymentUrl;

    public function __construct()
    {
        $this->publicKey = config('services.paystack.public_key');
        $this->secretKey = config('services.paystack.secret_key');
        $this->paymentUrl = config('services.paystack.payment_url', 'https://api.paystack.co');
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
            'callback_url' => route('admin.paystack-config.form'), // Change to your payment callback route
        ];
        try {
            $response = Http::withToken($this->secretKey)
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
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment.',
                'reference' => $reference,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack Payment Exception', ['error' => $e->getMessage()]);
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
            $response = Http::withToken($this->secretKey)
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
}
