<?php

namespace App\Services;

use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected ?string $apiKey;
    protected string $senderId;
    protected string $baseUrl;
    protected ?PaymentGatewayConfig $config;

    public function __construct(?PaymentGatewayConfig $config = null)
    {
        $this->config = $config ?? PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_SMS);
        
        if ($this->config && $this->config->gateway_name === PaymentGatewayConfig::GATEWAY_BULKCLIX) {
            $this->apiKey = $this->config->getConfig('api_key');
            $this->senderId = $this->config->getConfig('sender_id', 'XTRA4U');
            $this->baseUrl = $this->config->getConfig('base_url', 'https://bulkclix.com/api/v1');
        } else {
            // Fallback to .env if no database config
            $this->apiKey = config('services.bulkclix.api_key');
            $this->senderId = config('services.bulkclix.sender_id', 'XTRA4U');
            $this->baseUrl = config('services.bulkclix.base_url', 'https://bulkclix.com/api/v1');
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
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30)->retry(2, 100);

        // Only disable SSL verification in local development
        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * Send an SMS message via BulkClix API
     */
    public function send(string $to, string $message): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('BulkClix SMS: API key not configured');
            return false;
        }

        try {
            // Format phone number (remove leading 0, add country code if needed)
            $formattedPhone = $this->formatPhoneNumber($to);

            $response = $this->getHttpClient()
                ->post($this->baseUrl . '/sms/send', [
                    'sender_id' => $this->senderId,
                    'recipient' => $formattedPhone,
                    'message' => $message,
                ]);

            if ($response->successful()) {
                Log::info('BulkClix SMS sent successfully', [
                    'to' => $formattedPhone,
                    'message_preview' => substr($message, 0, 50) . '...',
                ]);
                return true;
            }

            Log::error('BulkClix SMS failed', [
                'to' => $formattedPhone,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('BulkClix SMS exception', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Format phone number to international format
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If starts with 0, replace with 233 (Ghana country code)
        if (str_starts_with($phone, '0')) {
            $phone = '233' . substr($phone, 1);
        }

        // If doesn't start with country code, add Ghana code
        if (!str_starts_with($phone, '233') && strlen($phone) === 9) {
            $phone = '233' . $phone;
        }

        return $phone;
    }

    /**
     * Send order placement SMS to mobile money number
     */
    public function sendOrderPlacedSms(string $mobileMoneyNumber, string $orderId, string $recipientPhone, string $serviceName): bool
    {
        $message = "XTRA4U Order Confirmed!\n\n"
            . "Order ID: #{$orderId}\n"
            . "Service: {$serviceName}\n"
            . "Recipient: {$recipientPhone}\n\n"
            . "Your order will be delivered within 60 minutes.\n\n"
            . "Thank you for choosing XTRA4U!";

        return $this->send($mobileMoneyNumber, $message);
    }

    /**
     * Send order completion SMS to recipient phone number
     */
    public function sendOrderCompletedSms(string $recipientPhone, string $serviceName, string $orderId): bool
    {
        $message = "XTRA4U - Service Delivered!\n\n"
            . "Congratulations! You have been credited with:\n"
            . "{$serviceName}\n\n"
            . "Order ID: #{$orderId}\n\n"
            . "Thank you for using XTRA4U!";

        return $this->send($recipientPhone, $message);
    }

    /**
     * Check if SMS service is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    /**
     * Get the current environment (sandbox/live)
     */
    public function getEnvironment(): string
    {
        return $this->config?->environment ?? 'sandbox';
    }
}
