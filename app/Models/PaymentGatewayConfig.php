<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaymentGatewayConfig extends Model
{
    use HasFactory;

    public const TYPE_PAYMENT_COLLECTION = 'payment_collection';
    public const TYPE_PAYOUT = 'payout';
    public const TYPE_SMS = 'sms';

    public const GATEWAY_PAYSTACK = 'paystack';
    public const GATEWAY_FLUTTERWAVE = 'flutterwave';
    public const GATEWAY_BULKCLIX = 'bulkclix';
    public const GATEWAY_HUBTEL = 'hubtel';
    public const GATEWAY_MOMO = 'momo';
    public const GATEWAY_MOOLRE = 'moolre';

    public const ENV_SANDBOX = 'sandbox';
    public const ENV_LIVE = 'live';

    protected $fillable = [
        'gateway_name',
        'gateway_type',
        'is_active',
        'is_default',
        'config_data',
        'supported_features',
        'environment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'config_data' => 'array',
        'supported_features' => 'array',
    ];

    /**
     * Automatically encrypt config_data when saving
     */
    public function setConfigDataAttribute($value)
    {
        $this->attributes['config_data'] = Crypt::encryptString(json_encode($value));
    }

    /**
     * Automatically decrypt config_data when retrieving
     */
    public function getConfigDataAttribute($value)
    {
        if (!$value) {
            return [];
        }
        
        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the default gateway for a specific type
     */
    public static function getDefault(string $type): ?self
    {
        return static::where('gateway_type', $type)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Get all active gateways for a specific type
     */
    public static function getActive(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('gateway_type', $type)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->get();
    }

    /**
     * Set this gateway as default (and unset others)
     */
    public function setAsDefault(): void
    {
        // First, unset all other defaults for this type
        static::where('gateway_type', $this->gateway_type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        // Set this one as default
        $this->update(['is_default' => true, 'is_active' => true]);
    }

    /**
     * Get available gateway types
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_PAYMENT_COLLECTION => 'Payment Collection',
            self::TYPE_PAYOUT => 'Payout/Withdrawal',
            self::TYPE_SMS => 'SMS Service',
        ];
    }

    /**
     * Get available gateways
     */
    public static function getAvailableGateways(): array
    {
        return [
            self::GATEWAY_PAYSTACK => [
                'name' => 'Paystack',
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT],
                'config_fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                    'payment_url' => 'Payment URL',
                ],
                'default_config' => [
                    'payment_url' => 'https://api.paystack.co',
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'vodafone_cash' => true,
                    'airteltigo_momo' => true,
                    'bank_transfer' => true,
                ]
            ],
            self::GATEWAY_FLUTTERWAVE => [
                'name' => 'Flutterwave',
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT],
                'config_fields' => [
                    'public_key' => 'Public Key',
                    'secret_key' => 'Secret Key',
                    'encryption_key' => 'Encryption Key',
                    'payment_url' => 'Payment URL',
                ],
                'default_config' => [
                    'payment_url' => 'https://api.flutterwave.com/v3',
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'vodafone_cash' => true,
                    'airteltigo_momo' => true,
                ]
            ],
            self::GATEWAY_BULKCLIX => [
                'name' => 'BulkClix',
                'types' => [self::TYPE_PAYOUT, self::TYPE_SMS],
                'config_fields' => [
                    'api_key' => 'API Key',
                    'sender_id' => 'Sender ID',
                    'base_url' => 'Base URL',
                ],
                'default_config' => [
                    'base_url' => 'https://api.bulkclix.com/api/v1',
                    'sender_id' => 'XTRA4U',
                ]
            ],
            self::GATEWAY_HUBTEL => [
                'name' => 'Hubtel',
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT, self::TYPE_SMS],
                'config_fields' => [
                    'client_id' => 'Client ID',
                    'client_secret' => 'Client Secret',
                    'username' => 'Username',
                    'password' => 'Password',
                    'base_url' => 'Base URL',
                ],
                'default_config' => [
                    'base_url' => 'https://api.hubtel.com',
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'airteltigo_momo' => true,
                    'vodafone_cash' => true,
                    'bank_transfer' => true,
                    'sms' => true,
                    'bulk_sms' => true,
                ]
            ],
            // Moolre Gateway
            'moolre' => [
                'name' => 'Moolre',
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT],
                'config_fields' => [
                    'merchant_id' => 'Merchant ID',
                    'api_key' => 'API Key',
                    'api_secret' => 'API Secret',
                    'base_url' => 'Base URL',
                ],
                'default_config' => [
                    'base_url' => 'https://api.moolre.com',
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'bank_transfer' => true,
                    'payout' => true,
                ]
            ],
        ];
    }

    /**
     * Get configuration value by key
     */
    public function getConfig(string $key, $default = null)
    {
        $config = $this->config_data;
        return $config[$key] ?? $default;
    }

    /**
     * Check if gateway is properly configured
     */
    public function isConfigured(): bool
    {
        $config = $this->config_data;
        $gateways = static::getAvailableGateways();
        
        if (!isset($gateways[$this->gateway_name])) {
            return false;
        }

        $requiredFields = array_keys($gateways[$this->gateway_name]['config_fields']);
        
        foreach ($requiredFields as $field) {
            if (empty($config[$field])) {
                return false;
            }
        }

        return true;
    }
}