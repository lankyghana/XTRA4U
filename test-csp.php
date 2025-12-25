<?php
// Quick CSP test script
$ch = curl_init('http://127.0.0.1:8000/store/HEXANSX4LM/afa');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);

curl_close($ch);

// Extract CSP header
if (preg_match('/Content-Security-Policy:\s*(.+?)(?:\r\n|\n)/i', $headers, $matches)) {
    echo "=== Content Security Policy ===\n\n";
    $csp = $matches[1];
    $directives = explode('; ', $csp);
    
    foreach ($directives as $directive) {
        echo $directive . "\n";
    }
    
    echo "\n=== Key Directives for AFA ===\n\n";
    foreach ($directives as $directive) {
        if (str_starts_with($directive, 'form-action')) {
            echo "✓ $directive\n";
        }
        if (str_starts_with($directive, 'connect-src')) {
            echo "✓ $directive\n";
        }
    }
} else {
    echo "CSP header not found\n";
    echo "Response headers:\n";
    echo $headers;
}
