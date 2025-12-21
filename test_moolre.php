<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;

echo "Testing Moolre API Connection...\n\n";

$apiPubkey = config('services.moolre.api_pubkey');
$apiUser = config('services.moolre.api_user');
$baseUrl = config('services.moolre.base_url');

echo "API User: " . $apiUser . "\n";
echo "API Pubkey: " . substr($apiPubkey, 0, 20) . "...\n";
echo "Base URL: " . $baseUrl . "\n\n";

// Test Generate Payment Link
$payload = [
    'type' => 1,
    'amount' => '1.00',
    'email' => 'test@example.com',
    'externalref' => 'test_' . time(),
    'callback' => 'https://example.com/webhook',
    'redirect' => 'https://example.com/success',
    'reusable' => '0',
    'currency' => 'GHS',
    'accountnumber' => $apiUser,
    'metadata' => [
        'test' => 'true',
    ],
];

echo "Sending request to: " . $baseUrl . "/embed/link\n";
echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$response = Http::withHeaders([
        'X-API-USER' => $apiUser,
        'X-API-PUBKEY' => $apiPubkey,
        'Content-Type' => 'application/json',
    ])
    ->timeout(30)
    ->post($baseUrl . '/embed/link', $payload);

echo "Response Status: " . $response->status() . "\n";
echo "Response Body: " . $response->body() . "\n\n";

if ($response->successful()) {
    $data = $response->json();
    if (isset($data['status']) && $data['status'] == 1) {
        echo "✅ SUCCESS! Payment link generated.\n";
        echo "Authorization URL: " . ($data['data']['authorization_url'] ?? 'N/A') . "\n";
        echo "Reference: " . ($data['data']['reference'] ?? 'N/A') . "\n";
    } else {
        echo "❌ API returned unsuccessful status.\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
    }
} else {
    echo "❌ HTTP request failed.\n";
}
