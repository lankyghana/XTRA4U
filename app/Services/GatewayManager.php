<?php

namespace App\Services;

use App\Contracts\Gateways\CollectsPayments;
use App\Contracts\Gateways\HandlesGenericPayments;
use App\Contracts\Gateways\HandlesPayouts;
use App\Models\PaymentGatewayConfig;
use App\Models\Order;
use App\Models\VendorWithdrawal;
use App\Services\PaystackPaymentService;
use App\Services\FlutterwavePaymentService;
use App\Services\HubtelPaymentService;
use App\Services\BulkClixPaymentService;
use App\Services\MoolrePaymentService;
use App\Services\SmsService;
use App\Services\Payouts\BulkClixPayoutService;
use App\Services\Payouts\FlutterwavePayoutService;
use App\Services\Payouts\HubtelPayoutService;
use App\Services\Payouts\MoolrePayoutService;
use App\Services\Payouts\PaystackPayoutService;
use Illuminate\Support\Facades\Log;

class GatewayManager
{
    /**
     * Single entry point: order checkout collection.
     */
    public function collect(Order $order, string $email, float $amount): array
    {
        $service = $this->getCollectionService();

        if (!$service) {
            return [
                'success' => false,
                'message' => 'No active payment gateway configured.',
                'reference' => null,
            ];
        }

        $result = $service->requestPayment($order, $email, $amount);
        $result['gateway_name'] = $this->getDefaultGatewayName(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);

        return $result;
    }

    /**
     * Single entry point: verify a collection payment.
     */
    public function verifyCollection(string $reference): array
    {
        $service = $this->getCollectionService();

        if (!$service) {
            return [
                'success' => false,
                'message' => 'No active payment gateway configured.',
            ];
        }

        return $service->verifyPayment($reference);
    }

    /**
     * Single entry point: generic payments (non-Order flows like AFA registration).
     *
     * Expected keys: email, amount, callback_url, reference(optional), metadata(optional)
     */
    public function genericPayment(array $payload): array
    {
        $config = $this->getDefaultConfig(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);

        if (!$config || !$config->is_active) {
            return [
                'success' => false,
                'message' => 'No active payment gateway configured.',
                'reference' => $payload['reference'] ?? null,
            ];
        }

        if (!$config->supports_generic) {
            return [
                'success' => false,
                'message' => 'Selected payment gateway does not support generic payments for this flow.',
                'reference' => $payload['reference'] ?? null,
            ];
        }

        $service = $this->getPaymentServiceByConfig($config);

        if (!$service || !($service instanceof HandlesGenericPayments)) {
            return [
                'success' => false,
                'message' => 'Selected payment gateway does not support generic payments for this flow.',
                'reference' => $payload['reference'] ?? null,
            ];
        }

        $result = $service->initiatePayment(
            (string) ($payload['email'] ?? ''),
            (float) ($payload['amount'] ?? 0),
            (string) ($payload['callback_url'] ?? ''),
            $payload['reference'] ?? null,
            (array) ($payload['metadata'] ?? [])
        );

        $result['gateway_name'] = $config->gateway_name;
        return $result;
    }

    /**
     * Single entry point: vendor withdrawal payouts.
     */
    public function payout(VendorWithdrawal $withdrawal): array
    {
        $config = $this->resolvePayoutConfigForWithdrawal($withdrawal);

        if (!$config || !$config->is_active) {
            return [
                'success' => false,
                'message' => 'No payout gateway configured. Please configure one in Admin → Payment Gateways.',
                'reference' => null,
            ];
        }

        if (!$config->supports_payout) {
            return [
                'success' => false,
                'message' => 'Selected payout gateway does not support payouts.',
                'reference' => null,
            ];
        }

        $service = $this->getPayoutServiceByConfig($config);

        if (!$service) {
            return [
                'success' => false,
                'message' => 'Selected payout gateway is not supported by this system.',
                'reference' => null,
            ];
        }

        $result = $service->processPayout($withdrawal);
        $result['gateway_name'] = $config->gateway_name;
        return $result;
    }

    /**
     * Resolve payout config; prefers the withdrawal's stored gateway if it exists and is active.
     */
    public function resolvePayoutConfigForWithdrawal(VendorWithdrawal $withdrawal): ?PaymentGatewayConfig
    {
        if (!empty($withdrawal->payout_gateway)) {
            $byName = PaymentGatewayConfig::where('gateway_name', $withdrawal->payout_gateway)
                ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
                ->where('is_active', true)
                ->first();

            if ($byName) {
                return $byName;
            }
        }

        return $this->getDefaultConfig(PaymentGatewayConfig::TYPE_PAYOUT);
    }

    public function getDefaultGatewayName(string $type): ?string
    {
        return $this->getDefaultConfig($type)?->gateway_name;
    }

    public function getDefaultConfig(string $type): ?PaymentGatewayConfig
    {
        return PaymentGatewayConfig::getDefault($type);
    }

    /**
     * Get the active payment collection service
     */
    public function getPaymentService(): ?object
    {
        return $this->getPaymentServiceByConfig($this->getDefaultConfig(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION));
    }

    /**
     * Get payment service by gateway name
     */
    public function getPaymentServiceByGateway(string $gatewayName): ?object
    {
        $config = PaymentGatewayConfig::where('gateway_name', $gatewayName)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            return null;
        }

        return $this->getPaymentServiceByConfig($config);
    }

    protected function getPaymentServiceByConfig(?PaymentGatewayConfig $config): ?object
    {
        if (!$config || !$config->is_active) {
            Log::warning('No active payment collection gateway configured');
            return null;
        }

        // Capability safety: block unsupported flows explicitly.
        if (!$config->supports_collection) {
            Log::warning('Configured gateway does not support collections', ['gateway' => $config->gateway_name]);
            return null;
        }

        return match ($config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_PAYSTACK => new PaystackPaymentService($config),
            PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => new FlutterwavePaymentService($config),
            // Hubtel checkout isn't wired into the CollectsPayments contract yet; keep blocked until implemented.
            PaymentGatewayConfig::GATEWAY_HUBTEL => null,
            PaymentGatewayConfig::GATEWAY_BULKCLIX => new BulkClixPaymentService($config),
            PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePaymentService($config),
            default => null
        };
    }

    protected function getCollectionService(): ?CollectsPayments
    {
        $service = $this->getPaymentServiceByConfig($this->getDefaultConfig(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION));

        if ($service instanceof CollectsPayments && $service->isConfigured()) {
            return $service;
        }

        return null;
    }

    /**
     * Get all active payment services
     */
    public function getAllPaymentServices(): array
    {
        $configs = PaymentGatewayConfig::getActive(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);
        $services = [];

        foreach ($configs as $config) {
            $service = $this->getPaymentServiceByConfig($config);

            if ($service instanceof CollectsPayments && $service->isConfigured()) {
                $services[$config->gateway_name] = [
                    'service' => $service,
                    'config' => $config,
                    'name' => PaymentGatewayConfig::getAvailableGateways()[$config->gateway_name]['name'] ?? $config->gateway_name,
                ];
            }
        }

        return $services;
    }

    /**
     * Get the active payout service
     */
    public function getPayoutService(): ?object
    {
        $config = $this->getDefaultConfig(PaymentGatewayConfig::TYPE_PAYOUT);
        return $config ? $this->getPayoutServiceByConfig($config) : null;
    }

    /**
     * Get payout service by gateway name
     */
    public function getPayoutServiceByGateway(string $gatewayName): ?object
    {
        $config = PaymentGatewayConfig::where('gateway_name', $gatewayName)
            ->where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            return null;
        }

        return $this->getPayoutServiceByConfig($config);
    }

    protected function getPayoutServiceByConfig(PaymentGatewayConfig $config): ?HandlesPayouts
    {
        if (!$config->is_active) {
            Log::warning('No active payout gateway configured');
            return null;
        }

        if (!$config->supports_payout) {
            Log::warning('Configured gateway does not support payouts', ['gateway' => $config->gateway_name]);
            return null;
        }

        $service = match ($config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_PAYSTACK => new PaystackPayoutService($config),
            PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => new FlutterwavePayoutService($config),
            PaymentGatewayConfig::GATEWAY_BULKCLIX => new BulkClixPayoutService($config),
            PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPayoutService($config),
            PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePayoutService($config),
            default => null,
        };

        if ($service && !$service->isConfigured()) {
            return null;
        }

        return $service;
    }

    /**
     * Get the active SMS service
     */
    public function getSmsService(): ?SmsService
    {
        $config = PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_SMS);
        
        if (!$config || !$config->is_active) {
            Log::warning('No active SMS gateway configured');
            return null;
        }

        if (!$config->supports_sms) {
            Log::warning('Configured gateway does not support SMS', ['gateway' => $config->gateway_name]);
            return null;
        }

        // Only BulkClix is wired to the SmsService contract in this codebase.
        return match ($config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_BULKCLIX => new SmsService($config),
            default => null,
        };
    }

    /**
     * Check if any payment gateway is configured
     */
    public function hasConfiguredPaymentGateway(): bool
    {
        return PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if any payout gateway is configured
     */
    public function hasConfiguredPayoutGateway(): bool
    {
        return PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if SMS gateway is configured
     */
    public function hasConfiguredSmsGateway(): bool
    {
        return PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_SMS)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get gateway statistics
     */
    public function getGatewayStats(): array
    {
        return [
            'payment_gateways' => [
                'total' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)->count(),
                'active' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)->where('is_active', true)->count(),
                'configured' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION)->get()->filter(fn($g) => $g->isConfigured())->count(),
            ],
            'payout_gateways' => [
                'total' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)->count(),
                'active' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)->where('is_active', true)->count(),
                'configured' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_PAYOUT)->get()->filter(fn($g) => $g->isConfigured())->count(),
            ],
            'sms_gateways' => [
                'total' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_SMS)->count(),
                'active' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_SMS)->where('is_active', true)->count(),
                'configured' => PaymentGatewayConfig::where('gateway_type', PaymentGatewayConfig::TYPE_SMS)->get()->filter(fn($g) => $g->isConfigured())->count(),
            ],
        ];
    }

    /**
     * Switch default gateway for a type
     */
    public function switchDefaultGateway(string $gatewayType, string $gatewayName): bool
    {
        try {
            $gateway = PaymentGatewayConfig::where('gateway_type', $gatewayType)
                ->where('gateway_name', $gatewayName)
                ->where('is_active', true)
                ->first();

            if (!$gateway) {
                return false;
            }

            $gateway->setAsDefault();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to switch default gateway', [
                'type' => $gatewayType,
                'gateway' => $gatewayName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}