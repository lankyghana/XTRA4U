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
            $gatewayDirectiveUrls = $this->getActiveGatewayDirectiveUrls();
            $scriptGatewayUrls = $this->formatDirectiveUrls($gatewayDirectiveUrls['script']);
            $connectGatewayUrls = $this->formatDirectiveUrls($gatewayDirectiveUrls['connect']);
            $frameGatewayUrls = $this->formatDirectiveUrls($gatewayDirectiveUrls['frame']);

            $csp = implode('; ', array_filter([
                // Default: only allow same-origin resources
                "default-src 'self'",

                // Scripts: allow self, Vite dev server (in dev), inline for Alpine.js,
                // and any active gateway that loads its checkout as a JS SDK (e.g. Payaza).
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'{$scriptGatewayUrls}".$this->getViteScriptSrc(),

                // Styles: allow self, inline styles (Tailwind), Google Fonts, and Vite dev server in development
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com".$this->getViteStyleSrc(),

                // Fonts: allow self and trusted font CDNs
                "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com https://r2cdn.perplexity.ai data:",

                // Images: allow self, data URIs, and HTTPS images
                "img-src 'self' data: https: blob:",

                // Connect: allow API calls to self and active payment gateway
                "connect-src 'self'{$localDevHosts}{$connectGatewayUrls}".$this->getViteConnectSrc(),

                // Frames: only self and active payment gateway (for payment modals/checkout iframes)
                "frame-src 'self'{$localDevHosts}{$frameGatewayUrls}",

                // Object/Embed: none (no Flash, etc.)
                "object-src 'none'",

                // Base URI: only self
                "base-uri 'self'",

                // Frame ancestors: none (prevent clickjacking)
                "frame-ancestors 'none'",

                // Upgrade insecure requests in production
                app()->environment('production') ? 'upgrade-insecure-requests' : '',
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
        if (! app()->environment('local', 'development')) {
            return '';
        }

        // Allow common dev ports on localhost/127.0.0.1 only (avoid bracketed IPv6 literals)
        return ' http://localhost:8000 http://127.0.0.1:8000 http://localhost:8001 http://127.0.0.1:8001';
    }

    /**
     * Get per-directive URLs for active payment gateways, keyed by
     * 'script' | 'connect' | 'frame'. Only includes URLs for gateways that
     * are currently active, and only adds an origin to the directives it
     * actually needs (e.g. Payaza's Web Checkout SDK is loaded as a script
     * AND embeds a checkout iframe, but never receives direct fetch/XHR
     * calls from this page — so it is not added to connect-src).
     */
    private function getActiveGatewayDirectiveUrls(): array
    {
        $directiveUrls = ['script' => [], 'connect' => [], 'frame' => []];

        try {
            // Get all active payment gateways
            $activeGateways = PaymentGatewayConfig::where('is_active', true)->get();

            foreach ($activeGateways as $gateway) {
                switch ($gateway->gateway_name) {
                    case 'paystack':
                        $directiveUrls['connect'] = array_merge($directiveUrls['connect'], [
                            'https://paystack.com',
                            'https://*.paystack.co',
                            'https://checkout.paystack.com',
                        ]);
                        $directiveUrls['frame'] = array_merge($directiveUrls['frame'], [
                            'https://paystack.com',
                            'https://*.paystack.co',
                            'https://checkout.paystack.com',
                        ]);
                        break;

                    case 'flutterwave':
                        $directiveUrls['connect'] = array_merge($directiveUrls['connect'], [
                            'https://checkout.flutterwave.com',
                            'https://*.flutterwave.com',
                            'https://api.flutterwave.com',
                        ]);
                        $directiveUrls['frame'] = array_merge($directiveUrls['frame'], [
                            'https://checkout.flutterwave.com',
                            'https://*.flutterwave.com',
                            'https://api.flutterwave.com',
                        ]);
                        break;

                    case 'moolre':
                        $directiveUrls['connect'] = array_merge($directiveUrls['connect'], [
                            'https://api.moolre.com',
                            'https://*.moolre.com',
                        ]);
                        $directiveUrls['frame'] = array_merge($directiveUrls['frame'], [
                            'https://api.moolre.com',
                            'https://*.moolre.com',
                        ]);
                        break;

                    case 'bulkclix':
                        $directiveUrls['connect'] = array_merge($directiveUrls['connect'], [
                            'https://bulkclix.com',
                            'https://*.bulkclix.com',
                        ]);
                        $directiveUrls['frame'] = array_merge($directiveUrls['frame'], [
                            'https://bulkclix.com',
                            'https://*.bulkclix.com',
                        ]);
                        break;

                    case 'hubtel':
                        $directiveUrls['connect'] = array_merge($directiveUrls['connect'], [
                            'https://payproxyapi.hubtel.com',
                            'https://*.hubtel.com',
                        ]);
                        $directiveUrls['frame'] = array_merge($directiveUrls['frame'], [
                            'https://payproxyapi.hubtel.com',
                            'https://*.hubtel.com',
                        ]);
                        break;

                    case PaymentGatewayConfig::GATEWAY_PAYAZA:
                        // Payaza's Web Checkout SDK (checkout-v2.payaza.africa/js/v1/bundle.js)
                        // is loaded as a same-page <script> (needs script-src) and, once
                        // running, opens its own checkout UI inside an iframe pointed at
                        // the same origin (needs frame-src). The bundle itself makes no
                        // direct fetch/XHR/WebSocket calls to Payaza — all of that happens
                        // inside the framed page, governed by Payaza's own CSP — so no
                        // connect-src entry is required here.
                        $directiveUrls['script'][] = 'https://checkout-v2.payaza.africa';
                        $directiveUrls['frame'][] = 'https://checkout-v2.payaza.africa';
                        break;
                }
            }
        } catch (\Exception $e) {
            // If there's an error (e.g., database not available), return empty arrays.
            // This prevents the app from breaking during migrations or maintenance.
            return $directiveUrls;
        }

        return $directiveUrls;
    }

    /**
     * Render a directive's URL list (deduplicated) as a CSP source fragment,
     * e.g. ' https://foo.example https://bar.example', or '' if empty.
     */
    private function formatDirectiveUrls(array $urls): string
    {
        return $urls ? ' '.implode(' ', array_unique($urls)) : '';
    }

    /**
     * Allow Vite dev server origins for styles when in development.
     */
    private function getViteStyleSrc(): string
    {
        if (app()->environment('local', 'development')) {
            return ' http://localhost:5173 http://127.0.0.1:5173';
        }

        return '';
    }
}
