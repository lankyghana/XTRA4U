<?php

namespace App\Http\Middleware;

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
            $csp = implode('; ', [
                // Default: only allow same-origin resources
                "default-src 'self'",
                
                // Scripts: allow self, Vite dev server (in dev), and inline for Alpine.js
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'" . $this->getViteScriptSrc(),
                
                // Styles: allow self, inline styles (Tailwind), and Google Fonts
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
                
                // Fonts: allow self and trusted font CDNs
                "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
                
                // Images: allow self, data URIs, and HTTPS images
                "img-src 'self' data: https: blob:",
                
                // Connect: allow API calls to self and payment providers
                "connect-src 'self' https://paystack.com https://*.paystack.co https://bulkclix.com" . $this->getViteConnectSrc(),
                
                // Frames: only self (for payment modals if needed)
                "frame-src 'self' https://paystack.com https://*.paystack.co",
                
                // Object/Embed: none (no Flash, etc.)
                "object-src 'none'",
                
                // Base URI: only self
                "base-uri 'self'",
                
                // Form actions: only self
                "form-action 'self'",
                
                // Frame ancestors: none (prevent clickjacking)
                "frame-ancestors 'none'",
                
                // Upgrade insecure requests in production
                app()->environment('production') ? "upgrade-insecure-requests" : "",
            ]);

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
}
