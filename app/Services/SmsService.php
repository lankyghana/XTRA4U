<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected ?string $apiKey;
    protected string $senderId;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.bulkclix.api_key');
        $this->senderId = config('services.bulkclix.sender_id') ?? 'XTRA4U';
        $this->baseUrl = config('services.bulkclix.base_url') ?? 'https://bulkclix.com/api/v1';
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

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/sms/send', [
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
}
