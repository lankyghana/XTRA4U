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

    /** Which SMS provider is active: 'bulkclix' | 'moolre' */
    protected string $gateway;

    // Moolre-specific credentials
    protected ?string $apiUser;

    protected ?string $vasKey;

    public function __construct(?PaymentGatewayConfig $config = null)
    {
        $this->config = $config ?? PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_SMS);

        if ($this->config && $this->config->gateway_name === PaymentGatewayConfig::GATEWAY_MOOLRE) {
            // ── Moolre SMS ──────────────────────────────────────────────────
            $this->gateway = 'moolre';
            $this->apiUser = $this->config->getConfig('api_user');
            $this->vasKey = $this->config->getConfig('vas_key');
            $this->senderId = (string) $this->config->getConfig('sender_id', 'XTRA4U');
            $this->baseUrl = rtrim((string) $this->config->getConfig('base_url', 'https://api.moolre.com'), '/');
            $this->apiKey = null; // not used for Moolre SMS
        } elseif ($this->config && $this->config->gateway_name === PaymentGatewayConfig::GATEWAY_BULKCLIX) {
            // ── BulkClix SMS ─────────────────────────────────────────────────
            $this->gateway = 'bulkclix';
            $this->apiKey = $this->config->getConfig('api_key');
            $this->senderId = (string) $this->config->getConfig('sender_id', 'XTRA4U');
            $this->baseUrl = (string) $this->config->getConfig('base_url', 'https://bulkclix.com/api/v1');
            $this->apiUser = null;
            $this->vasKey = null;
        } else {
            // ── Fallback to .env (BulkClix legacy) ──────────────────────────
            $this->gateway = 'bulkclix';
            $this->apiKey = config('services.bulkclix.api_key');
            $this->senderId = config('services.bulkclix.sender_id', 'XTRA4U');
            $this->baseUrl = config('services.bulkclix.base_url', 'https://bulkclix.com/api/v1');
            $this->apiUser = null;
            $this->vasKey = null;
        }
    }

    /**
     * Get HTTP client with proper SSL configuration.
     * Production: full SSL verification enabled.
     * Local/development: SSL verification disabled (Windows CA cert issues).
     */
    protected function getHttpClient(array $headers)
    {
        $client = Http::withHeaders($headers)->timeout(30)->retry(2, 100);

        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * General SMS entry point — DISABLED.
     *
     * The platform intentionally sends only one kind of SMS: result-checker
     * PIN delivery (see sendResultCheckerPin()). Every other notification
     * (order placed/completed, vendor sales, withdrawals, AFA, USSD) is
     * handled via email / in-app notifications instead. Any call to send()
     * is a no-op so no accidental SMS can be dispatched from any call site.
     */
    public function send(string $to, string $message): bool
    {
        Log::debug('SMS suppressed: general SMS sending is disabled', ['to' => $to]);

        return false;
    }

    /**
     * Deliver a result-checker PIN/serial SMS.
     *
     * This is the ONLY enabled SMS path in the application.
     */
    public function sendResultCheckerPin(string $to, string $message): bool
    {
        return $this->dispatch($to, $message);
    }

    /**
     * Perform the actual provider dispatch (BulkClix or Moolre).
     */
    protected function dispatch(string $to, string $message): bool
    {
        return $this->gateway === 'moolre'
            ? $this->sendViaMoolre($to, $message)
            : $this->sendViaBulkClix($to, $message);
    }

    // ─── BulkClix ────────────────────────────────────────────────────────────

    protected function sendViaBulkClix(string $to, string $message): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('BulkClix SMS: API key not configured');

            return false;
        }

        try {
            $formattedPhone = $this->formatPhoneForBulkClix($to);

            $response = $this->getHttpClient([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/sms/send', [
                'sender_id' => $this->senderId,
                'recipient' => $formattedPhone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                Log::info('BulkClix SMS sent successfully', [
                    'to' => $formattedPhone,
                    'message_preview' => substr($message, 0, 50).'...',
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
     * Format phone number to international format for BulkClix (233XXXXXXXXX).
     */
    protected function formatPhoneForBulkClix(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '233'.substr($phone, 1);
        }

        if (! str_starts_with($phone, '233') && strlen($phone) === 9) {
            $phone = '233'.$phone;
        }

        return $phone;
    }

    // ─── Moolre ──────────────────────────────────────────────────────────────

    protected function sendViaMoolre(string $to, string $message): bool
    {
        if (empty($this->vasKey) || empty($this->apiUser)) {
            Log::warning('Moolre SMS: VAS Key or API User not configured');

            return false;
        }

        try {
            $formattedPhone = $this->formatPhoneForMoolre($to);
            $ref = 'XTRA4U-SMS-'.uniqid();

            $response = $this->getHttpClient([
                'X-API-USER' => $this->apiUser,
                'X-API-VASKEY' => $this->vasKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/open/sms/send', [
                'type' => 1,
                'senderid' => $this->senderId,
                'messages' => [
                    [
                        'recipient' => $formattedPhone,
                        'message' => $message,
                        'ref' => $ref,
                    ],
                ],
            ]);

            $data = $response->json();
            $apiStatus = (int) ($data['status'] ?? 0);

            if ($response->successful() && $apiStatus === 1) {
                Log::info('Moolre SMS sent successfully', [
                    'to' => $formattedPhone,
                    'ref' => $ref,
                    'message_preview' => substr($message, 0, 50).'...',
                ]);

                return true;
            }

            Log::error('Moolre SMS failed', [
                'to' => $formattedPhone,
                'status' => $response->status(),
                'code' => $data['code'] ?? null,
                'response' => $data,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Moolre SMS exception', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Format phone number to international format for Moolre (233XXXXXXXXX).
     */
    protected function formatPhoneForMoolre(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '233'.substr($phone, 1);
        }

        if (! str_starts_with($phone, '233') && strlen($phone) === 9) {
            $phone = '233'.$phone;
        }

        return $phone;
    }

    /**
     * Check if SMS service is properly configured.
     */
    public function isConfigured(): bool
    {
        if ($this->gateway === 'moolre') {
            return ! empty($this->vasKey) && ! empty($this->apiUser) && ! empty($this->baseUrl);
        }

        return ! empty($this->apiKey) && ! empty($this->baseUrl);
    }

    /**
     * Get the current environment (sandbox/live).
     */
    public function getEnvironment(): string
    {
        return $this->config?->environment ?? 'sandbox';
    }

    /**
     * Get the active gateway name.
     */
    public function getGateway(): string
    {
        return $this->gateway;
    }
}
