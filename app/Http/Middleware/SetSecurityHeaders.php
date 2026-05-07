<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(16);
        app(Vite::class)->useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->buildCsp($nonce));

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function buildCsp(string $nonce): string
    {
        $scriptSources = implode(' ', [
            "'self'",
            "'unsafe-inline'",
            "'nonce-{$nonce}'",
            'https://cdn.jsdelivr.net',
        ]);

        $styleSources = implode(' ', [
            "'self'",
            "'unsafe-inline'",
            'https://cdn.jsdelivr.net',
            'https://fonts.googleapis.com',
        ]);

        $fontSources = implode(' ', [
            "'self'",
            'data:',
            'https://cdn.jsdelivr.net',
            'https://fonts.gstatic.com',
        ]);

        $connectSources = implode(' ', [
            "'self'",
            'https://cdn.jsdelivr.net',
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
            'wss:',
            'ws:',
        ]);

        return implode('; ', [
            "default-src 'self'",
            "script-src {$scriptSources}",
            "style-src {$styleSources}",
            "img-src 'self' data: blob: https:",
            "font-src {$fontSources}",
            "connect-src {$connectSources}",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
