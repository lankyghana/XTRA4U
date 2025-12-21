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

        // Build config data from form inputs
        $configData = [];
        if (isset($gatewayInfo['config_fields'])) {
            foreach (array_keys($gatewayInfo['config_fields']) as $field) {
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
        $configData = $gateway->config_data; // Start with existing data

        if (isset($gatewayInfo['config_fields'])) {
            foreach (array_keys($gatewayInfo['config_fields']) as $field) {
                $value = $request->input("config.{$field}");
                if ($value !== null) {
                    $configData[$field] = $value;
                }
            }
        }

        try {
            DB::beginTransaction();

            $gateway->update([
                'environment' => $request->environment,
                'is_active' => $request->boolean('is_active'),
                'is_default' => $request->boolean('is_default'),
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

                case 'moolre':
                    if (isset($configData['api_key'])) {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_API_KEY', $configData['api_key']);
                    }
                    if (isset($configData['api_secret'])) {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_API_SECRET', $configData['api_secret']);
                    }
                    if (isset($configData['webhook_secret'])) {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_WEBHOOK_SECRET', $configData['webhook_secret']);
                    }
                    if (isset($configData['base_url'])) {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_BASE_URL', $configData['base_url']);
                    }
                    if (isset($configData['merchant_id'])) {
                        $envContent = $this->setEnvValue($envContent, 'MOOLRE_MERCHANT_ID', $configData['merchant_id']);
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