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

    public const GATEWAY_PAYAZA = 'payaza';

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
        // NOT cast to 'array' here — config_data has its own get/set mutator
        // pair below that already handles encrypt/decrypt + JSON encode/decode
        // (a custom accessor/mutator takes precedence over a cast for reads
        // and writes either way). Also declaring an 'array' cast alongside
        // it creates a real bug: Eloquent's dirty-checking (originalIsEquivalent())
        // sees the 'array' cast and compares the OLD and NEW values by
        // json_decode()-ing them — but by the time it runs, both are already
        // *encrypted ciphertext strings* (the mutator ran first), so both
        // decode to null and compare as "equivalent" even when the actual
        // plaintext changed. That silently drops config_data from the UPDATE
        // query on every save — i.e. editing a gateway's credentials to a
        // genuinely different value would appear to succeed but never
        // persist. (Blank-preserve and initial create are unaffected: create
        // has no "original" to compare against, and leaving a field blank
        // never attempts a real change in the first place.)
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
            self::GATEWAY_PAYAZA => [
                // Payout is intentionally not wired: this integration covers the
                // Web Checkout (collection) SDK only. Extending to payouts would
                // need its own PayazaPayoutService + docs review — out of scope.
                'supports_collection' => true,
                'supports_generic' => true,
                'supports_payout' => false,
                'supports_sms' => false,
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
        if (! $value) {
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
                ],
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
                ],
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
                ],
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
                ],
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
                        'api_user' => 'API Username',
                        'vas_key' => 'VAS Key (SMS)',
                        'sender_id' => 'Sender ID (max 11 chars)',
                        'base_url' => 'Base URL',
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

            self::GATEWAY_PAYAZA => [
                'name' => 'Payaza',
                // TYPE_PAYOUT is listed so a payout config row can be created and
                // its credentials stored ahead of time — it is NOT live. Payaza
                // payouts remain disabled until a PayazaPayoutService exists:
                // capabilityMap() below still declares supports_payout => false,
                // which PaymentGatewayController enforces at save time (a payout
                // row can never be saved is_active=true while that's false) and
                // which GatewayManager checks again before resolving any payout
                // service. Storing credentials here does not enable anything.
                'types' => [self::TYPE_PAYMENT_COLLECTION, self::TYPE_PAYOUT],
                // Web Checkout SDK: the browser opens Payaza's own hosted popup
                // (card/MoMo/bank all collected inside it), so — like Paystack/
                // Flutterwave's hosted checkout — XTRA4U does not need to collect
                // the payer's MoMo number up front. 'redirect' is the flag that
                // controls that ("no upfront payer phone needed"); it does not
                // mean the browser actually navigates away.
                'collection_flow' => 'redirect',
                'capabilities' => self::defaultCapabilitiesFor(self::GATEWAY_PAYAZA),
                // Per-type fields: collection and payout need different secrets.
                // Payout additionally needs a transaction PIN — see
                // PaymentGatewayController's PIN masking/blank-preserve/validation
                // handling, and isConfigured() below for its format check.
                'config_fields_by_type' => [
                    self::TYPE_PAYMENT_COLLECTION => [
                        'public_key' => 'Public API Key (used client-side as merchant_key, and base64-encoded server-side for API auth)',
                        'secret_key' => 'Secret Key (server-side only — webhook signature verification)',
                        'base_url' => 'API Base URL',
                    ],
                    self::TYPE_PAYOUT => [
                        'public_key' => 'Public API Key (base64-encoded server-side for API auth — confirm with Payaza whether payouts need a distinct key from collections)',
                        'secret_key' => 'Secret Key (server-side only — payout webhook signature verification)',
                        'transaction_pin' => 'Transaction PIN (6 digits, no repeated or sequential pattern — set on your Payaza dashboard)',
                        'base_url' => 'API Base URL',
                    ],
                ],
                'default_config' => [
                    'base_url' => 'https://api.payaza.africa/live',
                    'currency' => 'GHS',
                ],
                'supported_features' => [
                    'mobile_money' => true,
                    'mtn_momo' => true,
                    'vodafone_cash' => true,
                    'airteltigo_momo' => true,
                    'card' => true,
                    'bank_transfer' => true,
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

        if (! isset($gateways[$this->gateway_name])) {
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

            // Payaza secret_key is only used to verify webhook signatures — an
            // optional, additional confirmation layer. Collection still works
            // (and is independently verified) via the public-key-authenticated
            // status-query API, so don't block the whole gateway on it being set.
            if ($this->gateway_name === self::GATEWAY_PAYAZA
                && $this->gateway_type === self::TYPE_PAYMENT_COLLECTION
                && $field === 'secret_key'
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

            // Full format check for the Payaza payout transaction PIN — same
            // rule the admin form enforces at save time (see
            // isValidPayazaTransactionPin()), checked again here so a row
            // created any other way (tinker, a future code path, direct DB
            // access) is never reported "configured" with a malformed PIN.
            if ($field === 'transaction_pin' && is_string($value)) {
                if (! static::isValidPayazaTransactionPin($value)) {
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

    /**
     * Payaza's documented payout transaction PIN rule: exactly 6 digits,
     * every digit distinct (no repeats), and not a strictly ascending or
     * descending run (e.g. 123456 or 654321). Shared between isConfigured()
     * above and the admin form validation in PaymentGatewayController so the
     * rule lives in exactly one place.
     */
    public static function isValidPayazaTransactionPin(string $pin): bool
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            return false;
        }

        $digits = str_split($pin);

        if (count(array_unique($digits)) !== 6) {
            return false;
        }

        $ascending = true;
        $descending = true;

        for ($i = 1; $i < 6; $i++) {
            if ((int) $digits[$i] !== (int) $digits[$i - 1] + 1) {
                $ascending = false;
            }
            if ((int) $digits[$i] !== (int) $digits[$i - 1] - 1) {
                $descending = false;
            }
        }

        return ! $ascending && ! $descending;
    }
}
