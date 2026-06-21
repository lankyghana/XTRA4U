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
        'supports_collection',
        'supports_generic',
        'supports_payout',
        'supports_sms',
        'supports_webhook',
        'is_active',
        'is_default',
        'config_data',
        'supported_features',
        'environment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'supports_collection' => 'boolean',
        'supports_generic' => 'boolean',
        'supports_payout' => 'boolean',
        'supports_sms' => 'boolean',
        'supports_webhook' => 'boolean',
        'config_data' => 'array',
        'supported_features' => 'array',
    ];

    /**
     * Capability map is gateway-level (not type-level): the same provider can support multiple flows.
     * These flags are enforced at runtime by GatewayManager and used by the admin UI.
     */
    public static function capabilityMap(): array
    {
        return [
            self::GATEWAY_PAYSTACK => [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => false,
            ],
            self::GATEWAY_FLUTTERWAVE => [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => false,
            ],
            self::GATEWAY_BULKCLIX => [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => true,
            ],
            self::GATEWAY_HUBTEL => [
                // Safety enforcement: Hubtel collection + generic are not wired into our CollectsPayments flow.
                // Hubtel SMS is not wired into SmsService.
                'supports_collection' => false,
                'supports_generic' => false,
                'supports_payout' => true,
                'supports_sms' => false,
                'supports_webhook' => false,
            ],
            self::GATEWAY_MOOLRE => [
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => true,
                'supports_sms' => true,
                'supports_webhook' => true,
            ],
        ];
    }

    public static function defaultCapabilitiesFor(string $gatewayName): array
    {
        return static::capabilityMap()[$gatewayName] ?? [
            'supports_collection' => false,
            'supports_generic' => false,
            'supports_payout' => false,
            'supports_sms' => false,
            'supports_webhook' => false,
        ];
    }

    public function supports(string $flow): bool
    {
        return match ($flow) {
            self::TYPE_PAYMENT_COLLECTION => (bool) $this->supports_collection,
            'generic' => (bool) $this->supports_generic,
            self::TYPE_PAYOUT => (bool) $this->supports_payout,
            self::TYPE_SMS => (bool) $this->supports_sms,
            'webhook' => (bool) $this->supports_webhook,
            default => false,
        };
    }

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
                // Redirect/hosted checkout: payer phone is collected on the gateway UI when needed.
                'collection_flow' => 'redirect',
                'capabilities' => self::defaultCapabilitiesFor(self::GATEWAY_PAYSTACK),
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
                // Redirect/hosted checkout: payer phone is collected on the gateway UI when needed.
                'collection_flow' => 'redirect',
                'capabilities' => self::defaultCapabilitiesFor(self::GATEWAY_FLUTTERWAVE),
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
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT, self::TYPE_SMS],
                // Inline/API MoMo collection: we must collect the payer phone number before initiating payment.
                'collection_flow' => 'inline',
                'capabilities' => self::defaultCapabilitiesFor(self::GATEWAY_BULKCLIX),
                'config_fields_by_type' => [
                    self::TYPE_PAYMENT_COLLECTION => [
                        'api_key' => 'API Key',
                        'base_url' => 'Base URL',
                    ],
                    self::TYPE_PAYOUT => [
                        'api_key' => 'API Key',
                        'base_url' => 'Base URL',
                    ],
                    self::TYPE_SMS => [
                        'api_key' => 'API Key',
                        'sender_id' => 'Sender ID',
                        'base_url' => 'Base URL',
                    ],
                ],
                'default_config' => [
                    'base_url' => 'https://api.bulkclix.com/api/v1',
                    'sender_id' => 'XTRA4U',
                ]
            ],
            self::GATEWAY_HUBTEL => [
                'name' => 'Hubtel',
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT, self::TYPE_SMS],
                'capabilities' => self::defaultCapabilitiesFor(self::GATEWAY_HUBTEL),
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

            self::GATEWAY_MOOLRE => [
                'name' => 'Moolre',
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT, self::TYPE_SMS],
                // Use inline/embed flow where the payment UI can be embedded
                // in an iframe/modal so customers don't leave our site.
                'collection_flow' => 'inline',
                'capabilities' => self::defaultCapabilitiesFor(self::GATEWAY_MOOLRE),
                // Moolre has distinct credentials for collections vs transfers.
                // We model required fields by type to keep payout-only configs usable.
                'config_fields_by_type' => [
                    self::TYPE_PAYMENT_COLLECTION => [
                        'api_user' => 'API Username',
                        'api_key' => 'API Key',
                        'public_key' => 'Public API Key (legacy/optional)',
                        'account_number' => 'Account Number',
                        'business_email' => 'Business Email',
                        'webhook_secret' => 'Webhook Secret (optional)',
                        'currency' => 'Currency',
                        'base_url' => 'Base URL',
                    ],
                    self::TYPE_PAYOUT => [
                        'api_user' => 'API Username',
                        'api_key' => 'API Key',
                        'account_number' => 'Account Number',
                        'currency' => 'Currency',
                        'base_url' => 'Base URL',
                    ],
                    self::TYPE_SMS => [
                        'api_user'  => 'API Username',
                        'vas_key'   => 'VAS Key (SMS)',
                        'sender_id' => 'Sender ID (max 11 chars)',
                        'base_url'  => 'Base URL',
                    ],
                ],
                'default_config' => [
                    'base_url' => 'https://api.moolre.com',
                    'currency' => 'GHS',
                    'sender_id' => 'XTRA4U',
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'telecel_momo' => true,
                    'airteltigo_momo' => true,
                    'sms' => true,
                    'webhook' => true,
                ],
            ],
        ];
    }

    public static function collectionFlowFor(string $gatewayName): string
    {
        $info = static::getAvailableGateways()[$gatewayName] ?? [];
        $flow = (string) ($info['collection_flow'] ?? 'redirect');

        return in_array($flow, ['inline', 'redirect'], true) ? $flow : 'redirect';
    }

    public static function requiresPayerPhoneForCollectionGateway(string $gatewayName): bool
    {
        return static::collectionFlowFor($gatewayName) === 'inline';
    }

    public static function defaultCollectionRequiresPayerPhone(): bool
    {
        $gatewayName = static::getDefault(static::TYPE_PAYMENT_COLLECTION)?->gateway_name
            ?? static::GATEWAY_PAYSTACK;

        return static::requiresPayerPhoneForCollectionGateway($gatewayName);
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

        $gatewayInfo = $gateways[$this->gateway_name];

        $requiredFields = [];
        if (isset($gatewayInfo['config_fields_by_type'][$this->gateway_type])) {
            $requiredFields = array_keys($gatewayInfo['config_fields_by_type'][$this->gateway_type]);
        } elseif (isset($gatewayInfo['config_fields'])) {
            $requiredFields = array_keys($gatewayInfo['config_fields']);
        }
        
        foreach ($requiredFields as $field) {
            // Webhook secret is optional for Moolre collections.
            // We verify payment via Moolre's status API (server-to-server) instead of trusting the webhook payload.
            if ($this->gateway_name === self::GATEWAY_MOOLRE
                && $this->gateway_type === self::TYPE_PAYMENT_COLLECTION
                && in_array($field, ['webhook_secret', 'public_key', 'api_key'], true)
            ) {
                continue;
            }

            // For Moolre SMS, base_url has a default so treat it as optional if empty.
            if ($this->gateway_name === self::GATEWAY_MOOLRE
                && $this->gateway_type === self::TYPE_SMS
                && $field === 'base_url'
            ) {
                continue;
            }

            $value = $config[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            // Treat null/empty-string/whitespace-only as missing.
            // Avoid empty() so values like "0" aren't incorrectly rejected.
            if ($value === null || $value === '') {
                return false;
            }

            // Basic URL validation for URL-shaped fields.
            if (in_array($field, ['base_url', 'payment_url'], true) && is_string($value)) {
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    return false;
                }
            }
        }

        // Moolre collection credential compatibility:
        // prefer api_key, but allow legacy public_key-only configs.
        if ($this->gateway_name === self::GATEWAY_MOOLRE
            && $this->gateway_type === self::TYPE_PAYMENT_COLLECTION
        ) {
            $apiKey = trim((string) ($config['api_key'] ?? ''));
            $publicKey = trim((string) ($config['public_key'] ?? ''));

            if ($apiKey === '' && $publicKey === '') {
                return false;
            }
        }

        return true;
    }
}