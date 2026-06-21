<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class PaymentGatewayController extends Controller
{
    // Middleware protection is handled at route level in web.php
    // No need for constructor middleware

    /**
     * Display payment gateway configurations
     */
    public function index()
    {
        $gateways = PaymentGatewayConfig::orderBy('gateway_type')
            ->orderBy('is_default', 'desc')
            ->orderBy('is_active', 'desc')
            ->get()
            ->groupBy('gateway_type');

        $availableGateways = PaymentGatewayConfig::getAvailableGateways();
        $gatewayTypes = PaymentGatewayConfig::getTypes();

        return view('admin.payment-gateways.index', compact('gateways', 'availableGateways', 'gatewayTypes'));
    }

    /**
     * Show form to create new gateway configuration
     */
    public function create()
    {
        $availableGateways = PaymentGatewayConfig::getAvailableGateways();
        $gatewayTypes = PaymentGatewayConfig::getTypes();

        return view('admin.payment-gateways.create', compact('availableGateways', 'gatewayTypes'));
    }

    /**
     * Store new gateway configuration
     */
    public function store(Request $request)
    {
        $availableGateways = PaymentGatewayConfig::getAvailableGateways();
        
        $request->validate([
            'gateway_name' => ['required', Rule::in(array_keys($availableGateways))],
            'gateway_type' => ['required', Rule::in(array_keys(PaymentGatewayConfig::getTypes()))],
            'environment' => ['required', Rule::in([PaymentGatewayConfig::ENV_SANDBOX, PaymentGatewayConfig::ENV_LIVE])],
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        // Validate gateway supports the selected type
        $gatewayInfo = $availableGateways[$request->gateway_name];
        if (!in_array($request->gateway_type, $gatewayInfo['types'])) {
            return back()->withErrors(['gateway_type' => 'Selected gateway does not support this type.']);
        }

        $capabilities = $gatewayInfo['capabilities'] ?? PaymentGatewayConfig::defaultCapabilitiesFor($request->gateway_name);

        // Safety: do not allow enabling a gateway for a flow it doesn't support.
        if ($request->gateway_type === PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION && $request->boolean('is_active') && empty($capabilities['supports_collection'])) {
            return back()->withErrors(['is_active' => 'This gateway is not wired for checkout collections in this system.']);
        }
        if ($request->gateway_type === PaymentGatewayConfig::TYPE_PAYOUT && $request->boolean('is_active') && empty($capabilities['supports_payout'])) {
            return back()->withErrors(['is_active' => 'This gateway does not support payouts.']);
        }
        if ($request->gateway_type === PaymentGatewayConfig::TYPE_SMS && $request->boolean('is_active') && empty($capabilities['supports_sms'])) {
            return back()->withErrors(['is_active' => 'This gateway is not wired for SMS in this system.']);
        }

        // Safety: payment collection default is used by both checkout + generic flows.
        if (
            $request->gateway_type === PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION
            && $request->boolean('is_default')
            && (empty($capabilities['supports_collection']) || empty($capabilities['supports_generic']))
        ) {
            return back()->withErrors(['is_default' => 'This gateway cannot be set as the default payment collection gateway because it does not support generic (AFA) payments.']);
        }

        // Build config data from form inputs
        $configData = [];
        $fieldsForType = $gatewayInfo['config_fields_by_type'][$request->gateway_type] ?? ($gatewayInfo['config_fields'] ?? null);
        if (is_array($fieldsForType)) {
            foreach (array_keys($fieldsForType) as $field) {
                $configData[$field] = $request->input("config.{$field}", '');
            }
        }

        // Merge with default config
        if (isset($gatewayInfo['default_config'])) {
            $configData = array_merge($gatewayInfo['default_config'], $configData);
        }

        try {
            DB::beginTransaction();

            $gateway = PaymentGatewayConfig::create([
                'gateway_name' => $request->gateway_name,
                'gateway_type' => $request->gateway_type,
                'supports_collection' => (bool) ($capabilities['supports_collection'] ?? false),
                'supports_generic' => (bool) ($capabilities['supports_generic'] ?? false),
                'supports_payout' => (bool) ($capabilities['supports_payout'] ?? false),
                'supports_sms' => (bool) ($capabilities['supports_sms'] ?? false),
                'supports_webhook' => (bool) ($capabilities['supports_webhook'] ?? false),
                'environment' => $request->environment,
                'is_active' => $request->boolean('is_active'),
                'is_default' => $request->boolean('is_default'),
                'config_data' => $configData,
                'supported_features' => $gatewayInfo['supported_features'] ?? [],
            ]);

            // If this is set as default, update other gateways of same type
            if ($gateway->is_default) {
                $gateway->setAsDefault();
            }

            // Update .env file with gateway configuration
            $this->updateEnvFile($gateway);

            DB::commit();

            return redirect()->route('admin.payment-gateways.index')
                ->with('success', 'Payment gateway configuration created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create payment gateway config', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to create gateway configuration.']);
        }
    }

    /**
     * Show form to edit gateway configuration
     */
    public function edit(PaymentGatewayConfig $gateway)
    {
        $availableGateways = PaymentGatewayConfig::getAvailableGateways();
        $gatewayTypes = PaymentGatewayConfig::getTypes();

        return view('admin.payment-gateways.edit', compact('gateway', 'availableGateways', 'gatewayTypes'));
    }

    /**
     * Update gateway configuration
     */
    public function update(Request $request, PaymentGatewayConfig $gateway)
    {
        $availableGateways = PaymentGatewayConfig::getAvailableGateways();
        
        $request->validate([
            'environment' => ['required', Rule::in([PaymentGatewayConfig::ENV_SANDBOX, PaymentGatewayConfig::ENV_LIVE])],
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        // Build config data from form inputs
        $gatewayInfo = $availableGateways[$gateway->gateway_name];
        $capabilities = $gatewayInfo['capabilities'] ?? PaymentGatewayConfig::defaultCapabilitiesFor($gateway->gateway_name);
        $configData = $gateway->config_data; // Start with existing data

        $fieldsForType = $gatewayInfo['config_fields_by_type'][$gateway->gateway_type] ?? ($gatewayInfo['config_fields'] ?? null);

        if (is_array($fieldsForType)) {
            foreach (array_keys($fieldsForType) as $field) {
                $value = $request->input("config.{$field}");
                // For secret/key-like fields, treat empty string as "no change".
                if (
                    $value !== null
                    && !(
                        $value === ''
                        && (
                            str_contains($field, 'secret')
                            || str_contains($field, 'key')
                        )
                    )
                ) {
                    $configData[$field] = $value;
                }
            }
        }

        try {
            DB::beginTransaction();

            // Safety: payment collection default is used by both checkout + generic flows.
            if (
                $gateway->gateway_type === PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION
                && $request->boolean('is_default')
                && (empty($capabilities['supports_collection']) || empty($capabilities['supports_generic']))
            ) {
                DB::rollBack();
                return back()->withErrors(['is_default' => 'This gateway cannot be set as the default payment collection gateway because it does not support generic (AFA) payments.']);
            }

            // Safety: do not allow enabling a gateway for a flow it doesn't support.
            if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION && $request->boolean('is_active') && empty($capabilities['supports_collection'])) {
                DB::rollBack();
                return back()->withErrors(['is_active' => 'This gateway is not wired for checkout collections in this system.']);
            }
            if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_SMS && $request->boolean('is_active') && empty($capabilities['supports_sms'])) {
                DB::rollBack();
                return back()->withErrors(['is_active' => 'This gateway is not wired for SMS in this system.']);
            }

            $gateway->update([
                'environment' => $request->environment,
                'is_active' => $request->boolean('is_active'),
                'is_default' => $request->boolean('is_default'),
                'supports_collection' => (bool) ($capabilities['supports_collection'] ?? false),
                'supports_generic' => (bool) ($capabilities['supports_generic'] ?? false),
                'supports_payout' => (bool) ($capabilities['supports_payout'] ?? false),
                'supports_sms' => (bool) ($capabilities['supports_sms'] ?? false),
                'supports_webhook' => (bool) ($capabilities['supports_webhook'] ?? false),
                'config_data' => $configData,
            ]);

            // If this is set as default, update other gateways of same type
            if ($gateway->is_default) {
                $gateway->setAsDefault();
            }

            // Update .env file with gateway configuration
            $this->updateEnvFile($gateway);

            DB::commit();

            return redirect()->route('admin.payment-gateways.index')
                ->with('success', 'Payment gateway configuration updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update payment gateway config', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to update gateway configuration.']);
        }
    }

    /**
     * Delete gateway configuration
     */
    public function destroy(PaymentGatewayConfig $gateway)
    {
        try {
            // Don't allow deleting the default gateway if it's the only one of its type
            if ($gateway->is_default) {
                $otherGateways = PaymentGatewayConfig::where('gateway_type', $gateway->gateway_type)
                    ->where('id', '!=', $gateway->id)
                    ->where('is_active', true)
                    ->count();

                if ($otherGateways === 0) {
                    return back()->withErrors(['error' => 'Cannot delete the only active gateway of this type.']);
                }
            }

            $gateway->delete();

            return redirect()->route('admin.payment-gateways.index')
                ->with('success', 'Payment gateway configuration deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to delete payment gateway config', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to delete gateway configuration.']);
        }
    }

    /**
     * Set gateway as default
     */
    public function setDefault(PaymentGatewayConfig $gateway)
    {
        try {
            if (
                $gateway->gateway_type === PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION
                && (!$gateway->supports_collection || !$gateway->supports_generic)
            ) {
                return back()->withErrors(['error' => 'This gateway cannot be set as the default payment collection gateway because it does not support generic (AFA) payments.']);
            }

            if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_PAYOUT && !$gateway->supports_payout) {
                return back()->withErrors(['error' => 'This gateway cannot be set as the default payout gateway because it does not support payouts.']);
            }

            if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_SMS && !$gateway->supports_sms) {
                return back()->withErrors(['error' => 'This gateway cannot be set as the default SMS gateway because it is not wired for SMS.']);
            }

            $gateway->setAsDefault();

            return redirect()->route('admin.payment-gateways.index')
                ->with('success', 'Gateway set as default successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to set default payment gateway', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to set gateway as default.']);
        }
    }

    /**
     * Toggle gateway active status
     */
    public function toggleActive(PaymentGatewayConfig $gateway)
    {
        try {
            // Prevent activating unsupported flows.
            if (!$gateway->is_active) {
                if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION && !$gateway->supports_collection) {
                    return back()->withErrors(['error' => 'This gateway is not wired for checkout collections in this system.']);
                }
                if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_PAYOUT && !$gateway->supports_payout) {
                    return back()->withErrors(['error' => 'This gateway does not support payouts.']);
                }
                if ($gateway->gateway_type === PaymentGatewayConfig::TYPE_SMS && !$gateway->supports_sms) {
                    return back()->withErrors(['error' => 'This gateway is not wired for SMS in this system.']);
                }
            }

            // Don't allow deactivating the default gateway if it's the only one of its type
            if ($gateway->is_active && $gateway->is_default) {
                $otherActiveGateways = PaymentGatewayConfig::where('gateway_type', $gateway->gateway_type)
                    ->where('id', '!=', $gateway->id)
                    ->where('is_active', true)
                    ->count();

                if ($otherActiveGateways === 0) {
                    return back()->withErrors(['error' => 'Cannot deactivate the only active gateway of this type.']);
                }
            }

            $gateway->update(['is_active' => !$gateway->is_active]);

            $status = $gateway->is_active ? 'activated' : 'deactivated';
            return redirect()->route('admin.payment-gateways.index')
                ->with('success', "Gateway {$status} successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to toggle payment gateway status', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to toggle gateway status.']);
        }
    }

    /**
     * Test gateway configuration
     */
    public function test(PaymentGatewayConfig $gateway)
    {
        try {
            $isConfigured = $gateway->isConfigured();
            
            if ($isConfigured) {
                // You can add more specific tests here for each gateway type
                return response()->json([
                    'success' => true,
                    'message' => 'Gateway configuration is valid.',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gateway configuration is incomplete or invalid.',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to test payment gateway config', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error testing gateway configuration.',
            ]);
        }
    }

    /**
     * Update .env file with gateway configuration
     */
    private function updateEnvFile(PaymentGatewayConfig $gateway)
    {
        if (!$gateway->is_active || !$gateway->is_default) {
            return; // Only update .env for active default gateways
        }

        $envPath = base_path('.env');
        $envContent = File::get($envPath);
        $configData = $gateway->config_data;

        try {
            // Update .env based on gateway type and name
            switch ($gateway->gateway_name) {
                case 'paystack':
                    if (isset($configData['public_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'PAYSTACK_PUBLIC_KEY', $configData['public_key']);
                    }
                    if (isset($configData['secret_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'PAYSTACK_SECRET_KEY', $configData['secret_key']);
                    }
                    if (isset($configData['payment_url'])) {
                        $envContent = $this->setEnvValue($envContent, 'PAYSTACK_PAYMENT_URL', $configData['payment_url']);
                    }
                    break;

                case 'moolre':
                case 'moole':
                    if (isset($configData['api_user']) && $configData['api_user'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_API_USER', $configData['api_user']);
                    }
                    // Collections typically use a "public_key" (JWT) while payouts use "api_key".
                    if (isset($configData['public_key']) && $configData['public_key'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_PUBLIC_KEY', $configData['public_key']);
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_API_PUBKEY', $configData['public_key']);
                    }
                    if (isset($configData['api_key']) && $configData['api_key'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_API_KEY', $configData['api_key']);
                    } elseif (isset($configData['public_key']) && $configData['public_key'] !== '') {
                        // Backward compatibility: if only public_key is configured, keep MOOLRE_API_KEY populated.
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_API_KEY', $configData['public_key']);
                    }
                    if (isset($configData['account_number']) && $configData['account_number'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_ACCOUNT_NUMBER', $configData['account_number']);
                    }
                    if (isset($configData['base_url']) && $configData['base_url'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_BASE_URL', $configData['base_url']);
                    }
                    // SMS-specific credentials
                    if (isset($configData['vas_key']) && $configData['vas_key'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_VAS_KEY', $configData['vas_key']);
                    }
                    if (isset($configData['sender_id']) && $configData['sender_id'] !== '') {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_SENDER_ID', $configData['sender_id']);
                    }
                    break;


                case 'flutterwave':
                    if (isset($configData['public_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'FLUTTERWAVE_PUBLIC_KEY', $configData['public_key']);
                    }
                    if (isset($configData['secret_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'FLUTTERWAVE_SECRET_KEY', $configData['secret_key']);
                    }
                    if (isset($configData['encryption_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'FLUTTERWAVE_ENCRYPTION_KEY', $configData['encryption_key']);
                    }
                    if (isset($configData['payment_url'])) {
                        $envContent = $this->setEnvValue($envContent, 'FLUTTERWAVE_PAYMENT_URL', $configData['payment_url']);
                    }
                    break;

                case 'bulkclix':
                    if (isset($configData['api_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'BULKCLIX_API_KEY', $configData['api_key']);
                    }
                    if (isset($configData['base_url'])) {
                        $envContent = $this->setEnvValue($envContent, 'BULKCLIX_BASE_URL', $configData['base_url']);
                    }
                    if (isset($configData['sender_id'])) {
                        $envContent = $this->setEnvValue($envContent, 'BULKCLIX_SENDER_ID', $configData['sender_id']);
                    }
                    break;

                case 'hubtel':
                    if (isset($configData['client_id'])) {
                        $envContent = $this->setEnvValue($envContent, 'HUBTEL_CLIENT_ID', $configData['client_id']);
                    }
                    if (isset($configData['client_secret'])) {
                        $envContent = $this->setEnvValue($envContent, 'HUBTEL_CLIENT_SECRET', $configData['client_secret']);
                    }
                    if (isset($configData['username'])) {
                        $envContent = $this->setEnvValue($envContent, 'HUBTEL_USERNAME', $configData['username']);
                    }
                    if (isset($configData['password'])) {
                        $envContent = $this->setEnvValue($envContent, 'HUBTEL_PASSWORD', $configData['password']);
                    }
                    if (isset($configData['base_url'])) {
                        $envContent = $this->setEnvValue($envContent, 'HUBTEL_BASE_URL', $configData['base_url']);
                    }
                    break;
            }

            File::put($envPath, $envContent);
            Artisan::call('config:clear');
        } catch (\Exception $e) {
            Log::error('Failed to update .env file', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get value from .env content
     */
    private function getEnvValue($content, $key)
    {
        if (preg_match("/^{$key}=([^\n]*)/m", $content, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Set value in .env content
     */
    private function setEnvValue($content, $key, $value)
    {
        $pattern = "/^{$key}=.*$/m";
        $replacement = "{$key}={$value}";
        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $replacement, $content);
        } else {
            return $content . "\n{$replacement}";
        }
    }
}