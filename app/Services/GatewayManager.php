<?php

namespace App\Services;

use App\Models\PaymentGatewayConfig;
use App\Services\PaystackPaymentService;
use App\Services\FlutterwavePaymentService;
use App\Services\HubtelPaymentService;
use App\Services\MomoPayoutService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class GatewayManager
{
    /**
     * Get the active payment collection service
     */
    public function getPaymentService(): ?object
    {
        $config = PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);
        
        if (!$config || !$config->is_active) {
            Log::warning('No active payment collection gateway configured');
            return null;
        }

        return match ($config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_PAYSTACK => new PaystackPaymentService($config),
            PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => new FlutterwavePaymentService($config),
            PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPaymentService($config->config_data),
            PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePaymentService($config),
            default => throw new \Exception("Unsupported payment gateway: {$config->gateway_name}")
        };
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

        return match ($gatewayName) {
            PaymentGatewayConfig::GATEWAY_PAYSTACK => new PaystackPaymentService($config),
            PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => new FlutterwavePaymentService($config),
            PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPaymentService($config->config_data),
            PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePaymentService($config),
            default => null
        };
    }

    /**
     * Get all active payment services
     */
    public function getAllPaymentServices(): array
    {
        $configs = PaymentGatewayConfig::getActive(PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);
        $services = [];

        foreach ($configs as $config) {
            $service = match ($config->gateway_name) {
                PaymentGatewayConfig::GATEWAY_PAYSTACK => new PaystackPaymentService($config),
                PaymentGatewayConfig::GATEWAY_FLUTTERWAVE => new FlutterwavePaymentService($config),
                PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPaymentService($config->config_data),
                PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePaymentService($config),
                default => null
            };

            if ($service && $service->isConfigured()) {
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
        $config = PaymentGatewayConfig::getDefault(PaymentGatewayConfig::TYPE_PAYOUT);
        
        if (!$config || !$config->is_active) {
            Log::warning('No active payout gateway configured');
            return null;
        }

        return match ($config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePaymentService($config),
            PaymentGatewayConfig::GATEWAY_BULKCLIX => new MomoPayoutService($config),
            PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPaymentService($config->config_data),
            default => throw new \Exception("Unsupported payout gateway: {$config->gateway_name}")
        };
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

        return match ($gatewayName) {
            PaymentGatewayConfig::GATEWAY_MOOLRE => new MoolrePaymentService($config),
            PaymentGatewayConfig::GATEWAY_BULKCLIX => new MomoPayoutService($config),
            PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPaymentService($config->config_data),
            default => null
        };
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

        return match ($config->gateway_name) {
            PaymentGatewayConfig::GATEWAY_BULKCLIX => new SmsService($config),
            PaymentGatewayConfig::GATEWAY_HUBTEL => new HubtelPaymentService($config->config_data),
            default => throw new \Exception("Unsupported SMS gateway: {$config->gateway_name}")
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