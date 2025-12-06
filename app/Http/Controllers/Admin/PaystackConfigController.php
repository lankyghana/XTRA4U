<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Controller;

class PaystackConfigController extends Controller
{
    public function showForm()
    {
        $envPath = base_path('.env');
        $envContent = File::get($envPath);
        $publicKey = $this->getEnvValue($envContent, 'PAYSTACK_PUBLIC_KEY');
        $secretKey = $this->getEnvValue($envContent, 'PAYSTACK_SECRET_KEY');
        $paymentUrl = $this->getEnvValue($envContent, 'PAYSTACK_PAYMENT_URL');
        return View::make('admin.paystack-config', compact('publicKey', 'secretKey', 'paymentUrl'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'public_key' => 'required|string',
            'secret_key' => 'required|string',
            'payment_url' => 'required|url',
        ]);
        $envPath = base_path('.env');
        $envContent = File::get($envPath);
        $envContent = $this->setEnvValue($envContent, 'PAYSTACK_PUBLIC_KEY', $request->public_key);
        $envContent = $this->setEnvValue($envContent, 'PAYSTACK_SECRET_KEY', $request->secret_key);
        $envContent = $this->setEnvValue($envContent, 'PAYSTACK_PAYMENT_URL', $request->payment_url);
        File::put($envPath, $envContent);
        Artisan::call('config:clear');
        return Redirect::back()->with('success', 'Paystack API keys updated successfully!');
    }

    private function getEnvValue($content, $key)
    {
        if (preg_match("/^{$key}=([^\n]*)/m", $content, $matches)) {
            return $matches[1];
        }
        return '';
    }

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
