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
        $allowUnsafeEval = $this->shouldAllowUnsafeEval($request, $response);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->buildCsp($nonce, $allowUnsafeEval));

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function shouldAllowUnsafeEval(Request $request, Response $response): bool
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return true;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return false;
        }

        return str_contains($content, '/flux/flux')
            || str_contains($content, '/livewire/livewire')
            || str_contains($content, 'window.livewireScriptConfig');
    }

    private function buildCsp(string $nonce, bool $allowUnsafeEval = false): string
    {
        $scriptSources = [
            "'self'",
            "'unsafe-inline'",
            "'nonce-{$nonce}'",
            'https://cdn.jsdelivr.net',
        ];

        if ($this->shouldAllowViteDevServerSources()) {
            array_push(
                $scriptSources,
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://[::1]:5173',
            );
        }

        if ($allowUnsafeEval) {
            $scriptSources[] = "'unsafe-eval'";
        }

        $styleSources = [
            "'self'",
            "'unsafe-inline'",
            'https://cdn.jsdelivr.net',
            'https://fonts.googleapis.com',
        ];

        if ($this->shouldAllowViteDevServerSources()) {
            array_push(
                $styleSources,
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://[::1]:5173',
            );
        }

        $fontSources = implode(' ', [
            "'self'",
            'data:',
            'https://cdn.jsdelivr.net',
            'https://fonts.gstatic.com',
        ]);

        $connectSources = [
            "'self'",
            'https://cdn.jsdelivr.net',
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
            'wss:',
            'ws:',
        ];

        if ($this->shouldAllowViteDevServerSources()) {
            array_push(
                $connectSources,
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://[::1]:5173',
                'ws://localhost:5173',
                'ws://127.0.0.1:5173',
                'ws://[::1]:5173',
            );
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($scriptSources)),
            'style-src '.implode(' ', array_unique($styleSources)),
            "img-src 'self' data: blob: https:",
            "font-src {$fontSources}",
            'connect-src '.implode(' ', array_unique($connectSources)),
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    private function shouldAllowViteDevServerSources(): bool
    {
        return app()->runningUnitTests() || app(Vite::class)->isRunningHot();
    }
}
