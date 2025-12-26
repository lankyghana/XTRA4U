<?php

namespace App\Http\Middleware;

use App\Models\PaymentGatewayConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply CSP to HTML responses
        if ($response instanceof Response && 
            str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            
            // Build CSP directives
            $localDevHosts = $this->getLocalDevHosts();
            $gatewayUrls = $this->getActiveGatewayUrls();

            $csp = implode('; ', array_filter([
                // Default: only allow same-origin resources
                "default-src 'self'",
                
                // Scripts: allow self, Vite dev server (in dev), and inline for Alpine.js
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'" . $this->getViteScriptSrc(),
                
                // Styles: allow self, inline styles (Tailwind), and Google Fonts
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
                
                // Fonts: allow self and trusted font CDNs
                "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com https://r2cdn.perplexity.ai data:",
                
                // Images: allow self, data URIs, and HTTPS images
                "img-src 'self' data: https: blob:",
                
                // Connect: allow API calls to self and active payment gateway
                "connect-src 'self'{$localDevHosts}{$gatewayUrls}" . $this->getViteConnectSrc(),
                
                // Frames: only self and active payment gateway (for payment modals if needed)
                "frame-src 'self'{$localDevHosts}{$gatewayUrls}",
                
                // Object/Embed: none (no Flash, etc.)
                "object-src 'none'",
                
                // Base URI: only self
                "base-uri 'self'",
                
                // Frame ancestors: none (prevent clickjacking)
                "frame-ancestors 'none'",
                
                // Upgrade insecure requests in production
                app()->environment('production') ? "upgrade-insecure-requests" : "",
            ]));

            // Clean up empty directives
            $csp = preg_replace('/;\s*;/', ';', $csp);
            $csp = trim($csp, '; ');

            $response->headers->set('Content-Security-Policy', $csp);
            
            // Additional security headers
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('X-XSS-Protection', '1; mode=block');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            
            // Permissions Policy (formerly Feature-Policy)
            $response->headers->set('Permissions-Policy', 
                'camera=(), microphone=(), geolocation=(), payment=(self)'
            );
        }

        return $response;
    }

    /**
     * Get Vite dev server script source (only in development)
     */
    private function getViteScriptSrc(): string
    {
        if (app()->environment('local', 'development')) {
            return ' http://localhost:5173 http://127.0.0.1:5173';
        }
        return '';
    }

    /**
     * Get Vite dev server connect source (only in development)
     */
    private function getViteConnectSrc(): string
    {
        if (app()->environment('local', 'development')) {
            return ' ws://localhost:5173 ws://127.0.0.1:5173 http://localhost:5173 http://127.0.0.1:5173';
        }
        return '';
    }

    /**
     * In local/dev, people often access the app via localhost or 127.0.0.1.
     * CSP 'self' only matches the current origin, so cross-host form posts can get blocked.
     * Note: CSP wildcards only work for subdomains, not ports. We must list ports explicitly.
     */
    private function getLocalDevHosts(): string
    {
        if (!app()->environment('local', 'development')) {
            return '';
        }

        // Allow common dev ports on both loopback hostnames.
        // CSP spec doesn't support port wildcards, so we list them explicitly.
        return ' http://localhost:8000 http://127.0.0.1:8000 http://localhost:8001 http://127.0.0.1:8001';
    }

    /**
     * Get URLs for active payment gateways
     * Only includes URLs for gateways that are currently active
     */
    private function getActiveGatewayUrls(): string
    {
        $urls = [];

        try {
            // Get all active payment gateways
            $activeGateways = PaymentGatewayConfig::where('is_active', true)->get();

            foreach ($activeGateways as $gateway) {
                switch ($gateway->gateway_name) {
                    case 'paystack':
                        $urls[] = 'https://paystack.com';
                        $urls[] = 'https://*.paystack.co';
                        $urls[] = 'https://checkout.paystack.com';
                        break;
                    
                    case 'flutterwave':
                        $urls[] = 'https://checkout.flutterwave.com';
                        $urls[] = 'https://*.flutterwave.com';
                        $urls[] = 'https://api.flutterwave.com';
                        break;
                    
                    case 'moolre':
                        $urls[] = 'https://api.moolre.com';
                        $urls[] = 'https://*.moolre.com';
                        break;
                    
                    case 'bulkclix':
                        $urls[] = 'https://bulkclix.com';
                        $urls[] = 'https://*.bulkclix.com';
                        break;
                    
                    case 'hubtel':
                        $urls[] = 'https://payproxyapi.hubtel.com';
                        $urls[] = 'https://*.hubtel.com';
                        break;
                }
            }
        } catch (\Exception $e) {
            // If there's an error (e.g., database not available), return empty string
            // This prevents the app from breaking during migrations or maintenance
            return '';
        }

        return $urls ? ' ' . implode(' ', array_unique($urls)) : '';
    }

}
